#!/bin/sh
set -e
# Cache config/routes/views at start-up so runtime env vars are baked in.
# Migrations are NOT run here: use `make prod-migrate` explicitly.
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
exec "$@"
