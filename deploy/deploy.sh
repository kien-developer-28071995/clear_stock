#!/usr/bin/env bash
# Runs ON THE SERVER, in the deploy directory (docker-compose.prod.yml + backend/.env).
# Called by .github/workflows/deploy.yml over SSH; can also be run by hand:
#   IMAGE_PREFIX=ghcr.io/<owner>/clear_stock bash deploy.sh sha-1a2b3c4 [--migrate]
#
# Order: (new .env) -> pull -> preflight -> (migrations) -> switch containers -> health check.
# The running version keeps serving until the new images passed preflight and migrations.
# backend/.env.incoming (written by the workflow from GitHub Secrets/Variables) replaces backend/.env;
# if the deploy stops before switching, the previous file is put back.
# Tag "current" = the version that is running (apply config changes only).
set -euo pipefail
cd "$(dirname "$0")"

TAG="${1:?usage: deploy.sh <image tag|current> [--migrate]}"
MIGRATE="${2:-}"
: "${IMAGE_PREFIX:?IMAGE_PREFIX is required, e.g. ghcr.io/<owner>/clear_stock}"
if [ "${TAG}" = "current" ]; then
    TAG="$(cat .deployed-tag 2>/dev/null || true)"
    [ -n "${TAG}" ] || { echo "No .deployed-tag yet: deploy a real tag first."; exit 1; }
fi

ENV_CHANGED=0
SWITCHED=0
if [ -f backend/.env.incoming ]; then
    chmod 600 backend/.env.incoming
    if [ -f backend/.env ] && cmp -s backend/.env.incoming backend/.env; then
        rm backend/.env.incoming
        echo "==> backend/.env unchanged"
    else
        [ -f backend/.env ] && cp -p backend/.env backend/.env.previous
        mv backend/.env.incoming backend/.env
        ENV_CHANGED=1
        echo "==> backend/.env updated from GitHub Secrets/Variables"
        # Stopped before switching: the running containers still use the previous file.
        trap 'if [ "${SWITCHED}" != 1 ] && [ -f backend/.env.previous ]; then mv backend/.env.previous backend/.env; echo "==> previous backend/.env restored"; fi' EXIT
    fi
fi
[ -f backend/.env ] || { echo "backend/.env is missing (see docs/DEPLOY.md)"; exit 1; }

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

pending=$($DC run --rm app php artisan migrate:status 2>/dev/null | grep -c "Pending" || true)
if [ "${pending}" -gt 0 ]; then
    if [ "${MIGRATE}" != "--migrate" ]; then
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
# New config: recreate so every container re-reads backend/.env (config:cache runs on start).
$DC up -d --no-build --remove-orphans $([ "${ENV_CHANGED}" = 1 ] && echo --force-recreate) app horizon scheduler web

echo "==> Health check"
for i in $(seq 1 30); do
    if $DC exec -T web wget -qO- http://127.0.0.1/up >/dev/null 2>&1; then
        echo "${TAG}" > .deployed-tag
        docker image prune -f >/dev/null
        echo "==> ${TAG} is live"
        exit 0
    fi
    sleep 5
done
echo "!! Health check failed. Previous tag: $(cat .deployed-tag 2>/dev/null || echo unknown). Roll back with: bash deploy.sh <previous tag>"
$DC ps
exit 1
