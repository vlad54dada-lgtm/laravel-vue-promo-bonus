#!/bin/sh
set -e

# APP_KEY: беремо з оточення або генеруємо на старті (демо-режим:
# після рестарту сесії скидаються разом зі свіжою базою — це ок)
export APP_KEY="${APP_KEY:-$(php artisan key:generate --show)}"

mkdir -p database
touch database/database.sqlite

# Кожен старт контейнера — чиста демо-база з сідерами:
# гравець player@demo.test / password і три промокоди
php artisan migrate:fresh --seed --force

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
