#!/usr/bin/env bash
set -e

if [ ! -f .env ]; then
    cp .env.example .env
fi

if ! grep -q "^APP_KEY=base64" .env; then
    php artisan key:generate --force
fi

php artisan config:clear
php artisan migrate --force

# 只填基礎資料（縣市、行政區、郵遞區號、運動類型），不含假資料。這些 seeder 是
# 冪等的，所以每次容器啟動都重跑也不會產生重複資料。
php artisan db:seed --class='Database\Seeders\ReferenceDataSeeder' --force

exec "$@"
