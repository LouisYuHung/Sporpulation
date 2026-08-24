#!/usr/bin/env bash
set -e

# 雲端平台（Render 等）會直接注入環境變數，這時候不需要 .env 檔。照樣複製
# .env.example 反而危險：Laravel 讀 .env 是 immutable 模式，平台有設的變數會贏，
# 但「漏設」的變數就會悄悄拿到本機用的預設值 —— 例如 REDIS_HOST=127.0.0.1，
# 結果就是連線被拒。同時也避免每次容器啟動都重新產生一把 APP_KEY。
#
# 以 APP_KEY 是否由環境提供，來判斷是不是平台注入的情境。
if [ -z "${APP_KEY:-}" ]; then
    if [ ! -f .env ]; then
        cp .env.example .env
    fi

    if ! grep -q "^APP_KEY=base64" .env; then
        php artisan key:generate --force
    fi
fi

# 掛載的 volume 會蓋掉映像裡的擁有者設定，而 web 與 worker 共用同一個 storage。
# 任何一邊用 root 建立的檔案都會讓另一邊寫不進去 —— 症狀是 Laravel 完全寫不出 log，
# 而錯誤訊息本身也寫不進 log。每次啟動時（此時仍是 root）修正一次。
if [ "$(id -u)" = "0" ]; then
    chown -R www-data:www-data storage bootstrap/cache
fi

php artisan config:clear

# schema 與基礎資料是「整個叢集做一次」的事，但這個 entrypoint 是每個容器都會跑。
# 只有一個容器時看不出差別；加上 queue-worker 之後，兩個容器會同時搶著 migrate。
# 因此由誰負責要明講 —— worker 設 RUN_MIGRATIONS=false，並等 backend 健康檢查
# 通過（見 docker-compose.yml）才啟動。
#
# 但那只是靜態指派，在 backend 擴成多台時就不成立了。--isolated 用一把 cache lock
# 補上：它的語意是「若另一個實例正在執行就跳過」——互斥，不是「整個叢集只跑一次」。
# 前一台跑完釋放鎖之後，下一台仍會跑一次。
#
# 這樣就夠了，因為真正的保證不在鎖上：migrations 資料表讓每個 migration 一輩子只
# 套用一次。鎖只負責擋掉「兩台同時 migrate」這個真正危險的情況，不負責擋掉重複執行。
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force --isolated

    # 基礎資料（縣市、行政區、郵遞區號、運動類型）任何環境都會填；示範用的假資料
    # 則由 SEED_DEMO_DATA 控制，預設在非正式環境開啟。所有 seeder 都是冪等的，
    # 因此每次容器啟動都重跑也不會產生重複資料。
    #
    # 這一步刻意不讓它中斷啟動流程：schema 已經套用完成，填資料失敗頂多是內容不齊，
    # 讓服務起不來（容器不斷重啟、平台偵測不到開放的埠）反而更難查問題。
    if ! php artisan db:seed --force; then
        echo "entrypoint: 資料填充失敗，仍繼續啟動服務，請檢查上面的錯誤訊息。" >&2
    fi
fi

exec "$@"
