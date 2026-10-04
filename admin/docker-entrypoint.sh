#!/bin/sh
set -e
# Its own database is one SQLite file on a volume: created and migrated here (only this app uses it).
touch "${DB_DATABASE}"
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
# Installs, uninstalls and plan changes are recorded every 15 minutes (routes/console.php).
php artisan schedule:work &
exec php artisan serve --host=0.0.0.0 --port=8090 --no-reload
