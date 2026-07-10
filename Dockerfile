# Tailwind scans vendor's pagination views (see tailwind.config.js content globs),
# so the asset build needs them present or those utilities are silently dropped.
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist \
    --no-interaction --ignore-platform-reqs

FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json vite.config.js tailwind.config.js postcss.config.js ./
COPY resources ./resources
COPY --from=vendor /app/vendor/laravel/framework/src/Illuminate/Pagination \
    ./vendor/laravel/framework/src/Illuminate/Pagination
RUN npm ci && npm run build

FROM serversideup/php:8.3-fpm-nginx AS app
ENV PHP_OPCACHE_ENABLE=1
WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build
USER root
RUN composer install --no-dev --optimize-autoloader --no-interaction
USER www-data
