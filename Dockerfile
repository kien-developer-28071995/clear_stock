# syntax=docker/dockerfile:1.7
#
# Multi-stage build for clear_stock.
#   target "dev"   -> PHP-FPM with dev tooling, repo mounted from host
#   target "app"   -> production PHP-FPM runtime with backend/ (also horizon + scheduler)
#   target "web"   -> production nginx serving backend/public + frontend build
#
# Layout: backend/ = Laravel API + embedded page shell, frontend/ = React SPA (Vite).
# The frontend build is written into backend/public/build.
#
# Build prod:  docker compose -f docker-compose.prod.yml build

ARG PHP_VERSION=8.4
ARG NODE_VERSION=22

############################
# Base PHP image (shared)
############################
FROM php:${PHP_VERSION}-fpm-alpine AS php-base

# mlocati/php-extension-installer resolves build deps and cleans up after itself.
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions pdo_mysql redis bcmath intl gd zip opcache pcntl \
    && apk add --no-cache fcgi tini

WORKDIR /var/www/html
ENV PHP_FPM_MAX_CHILDREN=10

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/php/healthcheck.sh /usr/local/bin/php-fpm-healthcheck
RUN chmod +x /usr/local/bin/php-fpm-healthcheck

############################
# Dev image
############################
FROM php-base AS dev

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/opcache.dev.ini /usr/local/etc/php/conf.d/zz-opcache.ini

# Match host UID/GID so files created in the container are owned by you.
ARG UID=1000
ARG GID=1000
RUN apk add --no-cache shadow git unzip \
    && groupmod -o -g ${GID} www-data \
    && usermod -o -u ${UID} -g www-data www-data

USER www-data
CMD ["php-fpm"]

############################
# Composer dependencies (prod, no dev)
############################
FROM composer:2 AS vendor
WORKDIR /app
COPY backend/composer.json backend/composer.lock ./
RUN --mount=type=cache,target=/tmp/cache \
    COMPOSER_CACHE_DIR=/tmp/cache composer install \
      --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist \
      --ignore-platform-reqs
COPY backend/ .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts

############################
# Frontend build
############################
FROM node:${NODE_VERSION}-alpine AS frontend
WORKDIR /app/frontend
COPY frontend/package.json frontend/package-lock.json frontend/.npmrc ./
RUN --mount=type=cache,target=/root/.npm npm ci
COPY frontend/ .
# Outputs to /app/backend/public/build. The API key is injected at runtime by Blade, not Vite.
RUN npm run build

############################
# Production PHP runtime
############################
FROM php-base AS app

COPY docker/php/opcache.prod.ini /usr/local/etc/php/conf.d/zz-opcache.ini

COPY --chown=www-data:www-data backend/ .
COPY --chown=www-data:www-data --from=vendor /app/vendor ./vendor
COPY --chown=www-data:www-data --from=frontend /app/backend/public/build ./public/build
COPY docker/php/entrypoint.prod.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint \
    && rm -rf tests \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
ENTRYPOINT ["/sbin/tini", "--", "entrypoint"]
CMD ["php-fpm"]

############################
# Production nginx (static files + fastcgi to app)
############################
FROM nginx:1.27-alpine AS web
COPY docker/nginx/prod.conf /etc/nginx/conf.d/default.conf
COPY backend/public /var/www/html/public
COPY --from=frontend /app/backend/public/build /var/www/html/public/build
