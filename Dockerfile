# ---- Stage 1: frontend (Vite) build ----
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm run build

# ---- Stage 2: PHP app ----
FROM serversideup/php:8.3-fpm-nginx

ENV PHP_OPCACHE_ENABLE=1
ENV AUTORUN_ENABLED=false

USER root
RUN install-php-extensions intl bcmath gd zip
USER www-data

COPY --chown=www-data:www-data . /var/www/html
COPY --chown=www-data:www-data --from=assets /app/public/build /var/www/html/public/build

RUN composer install --no-dev --optimize-autoloader --no-interaction
