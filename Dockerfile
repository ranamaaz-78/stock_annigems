# Stage 1: frontend build (Vite + Tailwind)
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm install
COPY . .
RUN npm run build

# Stage 2: Laravel (PHP + Nginx)
FROM serversideup/php:8.3-fpm-nginx

ENV PHP_OPCACHE_ENABLE=1
ENV AUTORUN_ENABLED=false

USER root
RUN install-php-extensions intl bcmath gd
USER www-data

COPY --chown=www-data:www-data . /var/www/html
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Vite ki built files (CSS/JS) pehle stage se copy
COPY --from=assets --chown=www-data:www-data /app/public/build /var/www/html/public/build
