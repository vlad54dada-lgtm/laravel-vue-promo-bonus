# ---- Стадія 1: збірка фронтенда (Vite) ----
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json vite.config.js ./
RUN npm ci
COPY resources ./resources
RUN npm run build

# ---- Стадія 2: PHP-рантайм ----
# Офіційний образ php:8.4-cli уже містить pdo_sqlite, mbstring, openssl тощо
FROM php:8.4-cli
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

# --no-autoloader: на цьому шарі ще немає app/ — автолоад генерується нижче.
# Повтор через || — страховка від транзієнтних мережевих збоїв білдера.
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-scripts --no-autoloader --no-progress \
    || composer install --no-dev --prefer-dist --no-scripts --no-autoloader --no-progress

COPY . .
RUN composer dump-autoload --optimize \
    && php artisan package:discover --ansi

COPY --from=assets /app/public/build ./public/build

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

EXPOSE 8080
CMD ["sh", "docker/start.sh"]
