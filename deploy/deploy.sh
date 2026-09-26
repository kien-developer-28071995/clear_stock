#!/usr/bin/env bash
# Runs ON THE SERVER, in the deploy directory (docker-compose.prod.yml + backend/.env).
# Called by .github/workflows/deploy.yml over SSH; can also be run by hand:
#   IMAGE_PREFIX=ghcr.io/<owner>/clear_stock bash deploy.sh sha-1a2b3c4 [--migrate]
#
# Order: pull -> preflight -> (migrations) -> switch containers -> health check.
# The running version keeps serving until the new images passed preflight and migrations.
set -euo pipefail
cd "$(dirname "$0")"

TAG="${1:?usage: deploy.sh <image tag> [--migrate]}"
MIGRATE="${2:-}"
: "${IMAGE_PREFIX:?IMAGE_PREFIX is required, e.g. ghcr.io/<owner>/clear_stock}"
[ -f backend/.env ] || { echo "backend/.env is missing (see docs/DEPLOY.md)"; exit 1; }

export APP_IMAGE="${IMAGE_PREFIX}-app:${TAG}"
export WEB_IMAGE="${IMAGE_PREFIX}-web:${TAG}"
DC="docker compose -f docker-compose.prod.yml"

echo "==> Deploying ${TAG}"
$DC pull app web

echo "==> Database and Redis"
$DC up -d --no-build mysql redis

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
$DC up -d --no-build --remove-orphans app horizon scheduler web

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
