# syntax=docker/dockerfile:1.7
#
# Owner reports (admin/): one small container that serves the pages, keeps its own SQLite file
# and runs the 15-minute ledger sync. Built with this directory as the context:
#   docker build -t clear_stock-admin admin
#
# One user, behind an SSH tunnel or an IP allowlist: PHP's own server with a few workers is
# enough here (no nginx + FPM pair to run for a page the owner opens a few times a day).

ARG PHP_VERSION=8.4

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/cache \
    COMPOSER_CACHE_DIR=/tmp/cache composer install \
      --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts

FROM php:${PHP_VERSION}-cli-alpine
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql intl opcache && apk add --no-cache tini

WORKDIR /var/www/admin
COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=vendor /app/vendor ./vendor
RUN chmod +x docker-entrypoint.sh \
    && rm -rf tests \
    && mkdir -p database/data storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data database storage bootstrap/cache

ENV PHP_CLI_SERVER_WORKERS=4 \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/var/www/admin/database/data/admin.sqlite \
    LOG_CHANNEL=stderr
USER www-data
EXPOSE 8090
ENTRYPOINT ["/sbin/tini", "--", "./docker-entrypoint.sh"]
