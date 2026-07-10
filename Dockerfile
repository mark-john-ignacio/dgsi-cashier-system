FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json vite.config.js ./
COPY resources ./resources
RUN npm ci && npm run build

FROM serversideup/php:8.3-fpm-nginx AS app
ENV PHP_OPCACHE_ENABLE=1
WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build
USER root
RUN composer install --no-dev --optimize-autoloader --no-interaction
USER www-data
