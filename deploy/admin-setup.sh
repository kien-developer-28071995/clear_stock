#!/usr/bin/env bash
# One-time setup of the owner reports ON THE SERVER, in the deploy directory. Safe to run again.
#   bash admin-setup.sh
# Creates admin/.env with its own APP_KEY and a MySQL user that can only SELECT from the app's
# database. The next deploy (or `bash deploy.sh current`) then starts the "admin" container on
# 127.0.0.1:8090. Create your login afterwards:
#   docker compose -f docker-compose.prod.yml exec admin php artisan admin:user you@example.com
set -euo pipefail
cd "$(dirname "$0")"
[ -f backend/.env ] || { echo "backend/.env is missing: deploy the app first."; exit 1; }

get() { grep -E "^$1=" backend/.env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
DB_DATABASE="$(get DB_DATABASE)"
DB_ROOT_PASSWORD="$(get DB_ROOT_PASSWORD)"
REPORT_USER=report

mkdir -p admin
if [ -f admin/.env ]; then
    echo "==> admin/.env exists: kept as it is"
else
    REPORT_PASSWORD="$(openssl rand -hex 24)"
    echo "==> MySQL user '${REPORT_USER}' (SELECT only on ${DB_DATABASE})"
    docker compose -f docker-compose.prod.yml exec -T -e MYSQL_PWD="${DB_ROOT_PASSWORD}" mysql mysql -u root <<SQL
CREATE USER IF NOT EXISTS '${REPORT_USER}'@'%' IDENTIFIED BY '${REPORT_PASSWORD}';
ALTER USER '${REPORT_USER}'@'%' IDENTIFIED BY '${REPORT_PASSWORD}';
GRANT SELECT ON \`${DB_DATABASE}\`.* TO '${REPORT_USER}'@'%';
FLUSH PRIVILEGES;
SQL
    umask 077
    cat > admin/.env <<ENV
APP_NAME="Clear Stock Admin"
APP_ENV=production
APP_KEY=base64:$(openssl rand -base64 32)
APP_DEBUG=false
APP_URL=http://localhost:8090
LOG_LEVEL=warning

SESSION_DRIVER=database
SESSION_LIFETIME=480
CACHE_STORE=database
QUEUE_CONNECTION=sync

# The app's database, read-only.
APPDB_HOST=mysql
APPDB_PORT=3306
APPDB_DATABASE=${DB_DATABASE}
APPDB_USERNAME=${REPORT_USER}
APPDB_PASSWORD=${REPORT_PASSWORD}

REPORT_TIMEZONE=Asia/Ho_Chi_Minh
# Comma-separated IPs or CIDR ranges allowed in. Set it before putting the reports on a public domain.
REPORT_ALLOWED_IPS=
ENV
    echo "==> admin/.env written"
fi
echo "Done. Start it with the next deploy, or now: IMAGE_PREFIX=... bash deploy.sh current"
