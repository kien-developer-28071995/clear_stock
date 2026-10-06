#!/usr/bin/env bash
# Runs ON THE SERVER, in the deploy directory (docker-compose.prod.yml + app/backend/.env).
# Called by .github/workflows/deploy.yml over SSH; can also be run by hand:
#   IMAGE_PREFIX=ghcr.io/<owner>/clear_stock bash deploy.sh sha-1a2b3c4 [--migrate|--migrate=auto]
#
# Migrations: --migrate runs every pending one. --migrate=auto (the workflow's default) runs them
# only when they just add things (tables, columns, indexes): the old version keeps serving while
# they run and must still work, also after a rollback. Anything that drops, renames or changes a
# column stops the deploy for a manual run (Actions -> Deploy -> migrate = true). No flag: stop.
#
# Order: (new .env) -> pull -> preflight -> (migrations) -> switch containers -> health check.
# The running version keeps serving until the new images passed preflight and migrations.
# app/backend/.env.incoming (written by the workflow from GitHub Secrets/Variables) replaces app/backend/.env;
# if the deploy stops before switching, the previous file is put back.
# Tag "current" = the version that is running (apply config changes only).
# The owner reports ("admin" service) start too once admin/.env exists (deploy/admin-setup.sh).
set -euo pipefail
cd "$(dirname "$0")"

TAG="${1:?usage: deploy.sh <image tag|current> [--migrate|--migrate=auto]}"
MIGRATE="${2:-}"
: "${IMAGE_PREFIX:?IMAGE_PREFIX is required, e.g. ghcr.io/<owner>/clear_stock}"
if [ "${TAG}" = "current" ]; then
    TAG="$(cat .deployed-tag 2>/dev/null || true)"
    [ -n "${TAG}" ] || { echo "No .deployed-tag yet: deploy a real tag first."; exit 1; }
fi

# Servers set up before the app moved into app/: the env file lived in backend/. Moved once.
if [ -f backend/.env ] && [ ! -f app/backend/.env ]; then
    mkdir -p app/backend
    mv backend/.env app/backend/.env
    [ ! -f backend/.env.previous ] || mv backend/.env.previous app/backend/.env.previous
    rmdir backend 2>/dev/null || true
    echo "==> backend/.env moved to app/backend/.env"
fi

ENV_CHANGED=0
SWITCHED=0
if [ -f app/backend/.env.incoming ]; then
    chmod 600 app/backend/.env.incoming
    if [ -f app/backend/.env ] && cmp -s app/backend/.env.incoming app/backend/.env; then
        rm app/backend/.env.incoming
        echo "==> app/backend/.env unchanged"
    else
        [ -f app/backend/.env ] && cp -p app/backend/.env app/backend/.env.previous
        mv app/backend/.env.incoming app/backend/.env
        ENV_CHANGED=1
        echo "==> app/backend/.env updated from GitHub Secrets/Variables"
        # Stopped before switching: the running containers still use the previous file.
        trap 'if [ "${SWITCHED}" != 1 ] && [ -f app/backend/.env.previous ]; then mv app/backend/.env.previous app/backend/.env; echo "==> previous app/backend/.env restored"; fi' EXIT
    fi
fi
[ -f app/backend/.env ] || { echo "app/backend/.env is missing (see docs/DEPLOY.md)"; exit 1; }

export APP_IMAGE="${IMAGE_PREFIX}-app:${TAG}"
export WEB_IMAGE="${IMAGE_PREFIX}-web:${TAG}"
DC="docker compose -f docker-compose.prod.yml"

echo "==> Deploying ${TAG}"
$DC pull app web

echo "==> Database and Redis"
# A changed .env must not restart the database mid-deploy (a new DB_PASSWORD never applies to an existing database anyway).
$DC up -d --no-build $([ "${ENV_CHANGED}" = 1 ] && echo --no-recreate) mysql redis

echo "==> Preflight (production configuration)"
$DC run --rm --no-deps app php artisan app:preflight

status=$($DC run --rm app php artisan migrate:status 2>&1 || true)
fresh=0
if echo "${status}" | grep -qi "migration table not found"; then
    fresh=1   # empty database (first deploy): nothing runs on it yet, every migration is safe
    pending="all"
elif echo "${status}" | grep -qE "Ran|Pending"; then
    pending=$(echo "${status}" | grep -c "Pending" || true)
else
    echo "!! Could not read the migration status. Nothing was switched."
    echo "${status}" | tail -5 | sed 's/^/     /'
    exit 1
fi
if [ "${pending}" != "0" ]; then
    if [ "${fresh}" = 1 ] && [ -n "${MIGRATE}" ]; then
        echo "==> Empty database: creating every table"
    elif [ "${MIGRATE}" = "--migrate=auto" ]; then
        # The SQL the migrations would run, without running it. No SQL to read = not safe to guess.
        if ! sql=$($DC run --rm app php artisan migrate --pretend --force 2>&1); then
            echo "!! Could not read the pending migrations' SQL (migrate --pretend failed). Nothing was switched."
            echo "${sql}" | tail -5 | sed 's/^/     /'
            exit 1
        fi
        risky=$(echo "${sql}" | grep -Eiw -o '(drop|rename|truncate|modify|change|delete +from|update +[^ ]+ +set)[^;]{0,80}' || true)
        if [ -n "${risky}" ]; then
            echo "!! ${pending} pending migration(s) change or remove existing data/schema:"
            echo "${risky}" | sed 's/^/     /'
            echo "   Not run automatically (the running version could break, a rollback too). Nothing was switched."
            echo "   Check them, then: Actions -> Deploy -> Run workflow -> migrate = true."
            exit 1
        fi
        echo "==> ${pending} pending migration(s), additive only: running them"
    elif [ "${MIGRATE}" != "--migrate" ]; then
        echo "!! ${pending} pending migration(s). Nothing was switched."
        echo "   Deploy again with migrations: Actions -> Deploy -> Run workflow -> migrate = true."
        exit 1
    fi
    echo "==> Backup before migrating"
    mkdir -p backups
    $DC exec -T mysql sh -c 'exec mysqldump --single-transaction -u root -p"$DB_ROOT_PASSWORD" "$DB_DATABASE"' | gzip > "backups/before-${TAG}-$(date +%Y%m%d%H%M%S).sql.gz"
    echo "==> Migrating"
    $DC run --rm app php artisan migrate --force
fi

echo "==> Switching containers"
SWITCHED=1
# New config: recreate so every container re-reads app/backend/.env (config:cache runs on start).
$DC up -d --no-build --remove-orphans $([ "${ENV_CHANGED}" = 1 ] && echo --force-recreate) app horizon scheduler web

# Owner reports: separate from the app. A failure here is reported, never stops or rolls back the deploy.
if [ -f admin/.env ]; then
    export ADMIN_IMAGE="${IMAGE_PREFIX}-admin:${TAG}"
    echo "==> Owner reports (admin)"
    if $DC pull admin && $DC up -d --no-build admin; then
        echo "    running on 127.0.0.1:${ADMIN_PORT:-8090}"
    else
        echo "!! The owner reports did not start (no admin image for ${TAG}?). The app is not affected."
    fi
fi

echo "==> Health check"
for i in $(seq 1 30); do
    if $DC exec -T web wget -qO- http://127.0.0.1/up >/dev/null 2>&1; then
        echo "${TAG}" > .deployed-tag
        # Unused images older than 7 days (each deploy adds a tagged one; recent ones stay for rollbacks).
        docker image prune -af --filter "until=168h" >/dev/null
        echo "==> ${TAG} is live"
        exit 0
    fi
    sleep 5
done
echo "!! Health check failed. Previous tag: $(cat .deployed-tag 2>/dev/null || echo unknown). Roll back with: bash deploy.sh <previous tag>"
$DC ps
exit 1
