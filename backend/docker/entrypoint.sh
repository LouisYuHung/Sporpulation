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

# 基礎資料（縣市、行政區、郵遞區號、運動類型）任何環境都會填；示範用的假資料則
# 由 SEED_DEMO_DATA 控制，預設在非正式環境開啟。所有 seeder 都是冪等的，因此每次
# 容器啟動都重跑也不會產生重複資料。
#
# 這一步刻意不讓它中斷啟動流程：schema 已經套用完成，填資料失敗頂多是內容不齊，
# 讓服務起不來（容器不斷重啟、平台偵測不到開放的埠）反而更難查問題。
if ! php artisan db:seed --force; then
    echo "entrypoint: 資料填充失敗，仍繼續啟動服務，請檢查上面的錯誤訊息。" >&2
fi

exec "$@"
