# Sporpulation

前後端分離專案：

- `backend/` — Laravel 13 API (PHP 8.4 + MySQL)
- `frontend/` — Vue 3 SPA (Vite + Vue Router + Pinia)

## 使用 Docker 啟動（推薦）

```bash
docker compose up -d --build
```

- 後端 API：http://localhost:8080/api
- 前端頁面：http://localhost:5173
- MySQL：localhost:3306

## 本機開發

### 後端

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

### 前端

```bash
cd frontend
npm install
cp .env.example .env
npm run dev
```

前端透過 `VITE_API_BASE_URL`（預設 `http://localhost:8080/api`）呼叫後端 API；
後端透過 `FRONTEND_URL`（預設 `http://localhost:5173`）設定 CORS 允許來源。

## 活動報名

| Method | Endpoint                            | 說明                                              |
| ------ | ----------------------------------- | ------------------------------------------------- |
| GET    | `/api/activities`                   | 未開始的活動，可用 `sport_id`、`district_id` 篩選 |
| POST   | `/api/activities`                   | 開團（需登入）                                    |
| GET    | `/api/activities/{id}`              | 活動詳情                                          |
| POST   | `/api/activities/{id}/registration` | 報名，佔一個名額                                  |
| DELETE | `/api/activities/{id}/registration` | 取消報名，釋放名額                                |
| GET    | `/api/me/registrations`             | 我的報名                                          |

報名與取消都是冪等的：重送同一個請求不會佔到第二個名額，也不會重複釋放。
名額滿了回 409 `activity_full`，活動已開始回 409 `activity_closed`。

### 冪等碼（選用）

除了上面的天然冪等，任何寫入都可以帶 `Idempotency-Key` 標頭。伺服器會記住第一次的
結果，重送時直接回放並加上 `Idempotent-Replay: true`，不會再執行一次：

```
POST /api/activities/5/registration
Idempotency-Key: a3f1c9d2-7b64-4e01-9f2a-8c5d1e0b4a77
```

不帶標頭就完全不受影響。同一把 key 用在不同請求會被擋下（409 `idempotency_key_reused`），
第一個請求還在跑時的重複請求會收到 409 `request_in_progress`。

紀錄依路由分成兩個後端。**建立活動**存在 `idempotency_keys` 資料表：它沒有天然的唯一鍵
可以兜底，冪等碼是唯一的保證，而資料表不會被例行清除。**報名／取消**存在 Redis（獨立的
db，`cache:clear` 碰不到），靠 TTL 自動過期 —— 它背後還有 `unique(activity_id, user_id)`，
紀錄遺失最多讓重播退化成重新執行，而重新執行會撞上唯一鍵、整筆 rollback。

資料表的過期紀錄由排程每小時 `model:prune` 清理；Redis 那邊不需要，過期是資料儲存本身
在執行的。

### 限流

報名端點對**每個使用者**限制每分鐘 5 次嘗試。超過的請求會拿到 429：

```
HTTP/1.1 429 Too Many Requests
Retry-After: 42

{"message": "報名嘗試太過頻繁，請稍候再試。", "code": "too_many_requests"}
```

`Retry-After` 是算出來的，不是固定值 —— 它等於視窗裡最舊那次嘗試離開視窗還要多久。

額度綁在使用者身上而不是 IP，因此同一個公司網路或 NAT 後面的人不會互相消耗。取消報名
不受限流：正常使用者「報名後發現時間衝突」會馬上取消，不該因此吃掉額度。

### 併發驗證

測試套件跑在 sqlite 上、一次只跑一個請求，驗證得了邏輯但驗證不了併發：它檢查得到
邏輯對不對，但抓不到鎖順序造成的死結，也抓不到兩個請求同時拿走最後一個名額。

下面這個指令會 fork 出真正同時發生的請求，需要 MySQL：

```bash
php artisan activities:check-concurrency --capacity=7 --racers=100
```

100 個行程同時搶 7 個名額的實際輸出：

```
PASS seats granted equals capacity (granted=7 capacity=7)
PASS losers got a clean 409, not an error (rejected=93 errors=0)
PASS counter agrees with confirmed rows (joined_count=7 confirmed_rows=7)
PASS no retry errored (errors=0)
PASS the retries took exactly one seat (joined_count=1 confirmed_rows=1)
PASS the retries wrote exactly one row (rows=1)
PASS no churn worker errored (errors=0)
PASS counter never drifted from reality (joined_count=0 confirmed_rows=0)
PASS counter stayed within capacity (joined_count=0 confirmed_rows=0)
```

三組情境分別是：**多人搶名額**（剛好發出 7 個，其餘 93 個拿到乾淨的 409 而不是錯誤）、
**同一人重試風暴**（20 個並行請求只佔到 1 個名額、只寫 1 筆）、**報名取消交錯**
（12 個行程反覆報名又取消，計數器始終等於實際確認列數）。

它會寫入測試資料，只能指向可拋棄的資料庫。

限流器的「檢查 + 遞減」同樣驗不到 —— 單一行程裡 `ZCARD` 與 `ZADD` 之間不可能被插隊。
這兩個指令 fork 出真正同時抵達的嘗試：

```bash
php artisan limiter:race --naive    # 三次獨立往返的版本
php artisan limiter:race            # 單一 Lua script 的版本
```

| 100 個併發請求，上限 5 | 放行 |
| --- | --- |
| 三次獨立往返 | 25 / 99 / 53 / 62 / 73（每次都不同）|
| 單一 Lua script | 5 / 5 / 5 / 5 / 5 |

非原子版**在單元測試裡是全綠的** —— 它的錯誤只在併發下出現，而且錯誤的程度隨機。
最糟的一次讓 99 個請求全部通過了「每分鐘 5 次」的限制。

冪等的佔位是同一件事，只是兩個後端用不同的機制達成：

```bash
php artisan idempotency:race --store=redis      # Lua script
php artisan idempotency:race --store=database   # unique(user_id, key_hash)
```

| | 50 併發 | 200 併發 |
| --- | --- | --- |
| Redis | 搶到 1，錯誤 0 | 搶到 1，錯誤 0 |
| MySQL | 搶到 1，錯誤 0 | 搶到 1，**錯誤 26~49** |

兩個後端的正確性都守住了（永遠只有一個贏家），但 MySQL 在 200 併發下撞上
`max_connections = 151`，有幾十個請求根本沒被服務。**資料庫昂貴的不只是延遲，是連線槽
這個很小的有限資源** —— 而那正是限流在保護的東西。

最後量限流實際替資料庫擋下了多少工作。這個指令發出真正的 HTTP 請求（走完整的
middleware 堆疊），並直接向 MySQL 詢問執行過的語句數：

```bash
php artisan throttle:check --off    # 對照組
php artisan throttle:check
```

| 100 個併發請求 | 通過 | MySQL 語句數 |
| --- | --- | --- |
| 限流關閉 | 100 | 1088 |
| 限流開啟 | 5 | 405 |

降到 37%，但**降不到零**：`auth:sanctum` 與 route model binding 都排在限流之前，因此
每個被擋下的請求仍付出約 3 次 SELECT（token、user、activity）。要讓它更便宜，就得把限流
移到認證之前 —— 但那樣只能用 IP 當 key，同一個 NAT 後面的人會共用額度。**用公平性換成本。**

## 設計取捨

### 名額為什麼用 counter，不用 `COUNT(*)`

`activities.joined_count` 是反正規化的計數，`activity_registrations` 才是真相來源。
直接 `COUNT(*)` 也能算出目前人數，但那樣就沒有東西可以「原子地」檢查並佔用名額 ——
先 COUNT 再 INSERT 中間永遠有空隙，兩個請求會同時看到還有位子。

有了 counter，佔用名額就是**一句 SQL**，判斷條件放在 `WHERE` 裡：

```php
$claimed = static::whereKey($this->id)
    ->whereColumn('joined_count', '<', 'capacity')
    ->increment('joined_count');

if ($claimed === 0) {
    throw new ActivityFullException;   // 影響列數為 0 就是額滿
}
```

代價是計數可能與實際列數不一致，所以 `check-concurrency` 每一輪都會驗
`joined_count === confirmed_rows`。

### 為什麼是條件式 UPDATE，不是 `SELECT ... FOR UPDATE`

悲觀鎖也能正確，但要在交易中持鎖跨越一次來回；條件式 UPDATE 把讀與寫壓成單一敘述，
鎖只存在於該敘述執行期間。

不過**鎖順序**仍然重要。所有會動到名額的路徑都先取得 activities 那一列的寫入鎖，
再碰 `activity_registrations`。這不是潔癖：插入報名紀錄時，外鍵檢查會對母活動列取得
**共享鎖**，如果交易接著才想把它升級成排他鎖，就會跟其他所有報名者互相死結。開發過程
中先寫報名紀錄再扣名額的版本，40 個行程搶 5 個名額時只發出 2 個、38 個請求死於
SQLSTATE 40001，就是這個原因。改成先取排他鎖之後，報名者會在活動列上排隊而不是互鎖。

順帶一提，`joined_count` 與「名額（seat）」是刻意區分的兩個詞：前者是欄位與 API 欄位
名稱，後者只出現在方法名稱與說明文字（`claimSeat`、`releaseSeat`、`remainingSeats`）。
這條規則寫在 `Activity` 的 class docblock。

### 報名的冪等為什麼靠唯一鍵，而不是先查再寫

「先查有沒有報過，再決定要不要寫」在併發下同樣有空隙。改成讓
`unique(activity_id, user_id)` 來裁決：**先佔名額，再寫報名紀錄**，兩者包在同一個
transaction 裡。重送的請求會撞上唯一鍵，整筆 rollback，名額跟著還回去。

取消與重新報名則用條件式 UPDATE 當守衛（`WHERE status = Confirmed` /
`= Cancelled`），確保只有真正翻動狀態的那一個請求能動計數器 —— 一個名額只會被釋放
或取回一次。

因此報名有兩層保護：冪等碼擋在資料庫之前，唯一鍵是最終保證。開團沒有天然唯一鍵，
只有冪等碼這一層。

### 冪等紀錄為什麼從資料庫搬到 Redis（以及為什麼沒有全搬）

這個專案原本的判斷是：

> 刻意存在資料庫而非快取：快取會被例行清除（`cache:clear`、記憶體不足時被驅逐），
> 而這些紀錄一旦遺失，保護就會無聲無息地消失。

那句話仍然成立 —— 只是它現在只適用於**沒有第二層防線**的寫入。

搬到 Redis 換到的是兩件事：每個請求少一次 SQL 往返（`throttle:check` 量到的
[1088 → 405](#併發驗證) 裡有一部分是它），以及「過期」不再需要應用程式碼參與。資料庫
沒有 TTL，所以 `DatabaseIdempotencyStore::claim()` 必須自己判斷紀錄過期了沒、自己刪掉、
自己重試一次，還要靠 `model:prune` 每小時掃一遍；Redis 版那整段程式碼**不存在**，因為
過期是資料儲存本身在執行的。

#### 犧牲了什麼

責任沒有消失，只是換人扛 —— 而新的那個人比較容易失手：

| | 這個專案目前的狀態 |
| --- | --- |
| **崩潰遺失** | `redis:7-alpine` 只做 RDB 快照（`save 3600 1 / 300 100 / 60 10000`），`appendonly no`。最壞情況丟掉崩潰前 60 秒的紀錄。要更強就開 AOF，代價是每秒 fsync。 |
| **記憶體驅逐** | 本機 `maxmemory 0` / `maxmemory-policy noeviction`，不會驅逐。**但託管的 Redis 常預設 `allkeys-lru`** —— 那樣紀錄會在還沒到期時被安靜踢掉，正是上面那句擔憂換了個形式回來。部署前務必確認。 |
| **`cache:clear`** | 躲掉了：`Cache::flush()` 對 Redis 的實作是 `FLUSHDB`，而冪等紀錄放在獨立的 db 2，快取在 db 1。躲不掉的是 `FLUSHALL`。 |
| **Redis 不可達** | 冪等是 fail closed（回 500，寧可拒絕服務也不重複執行）；限流是 fail open（放行，因為它只是第一層過濾）。**同一個故障，兩個 middleware 刻意相反。** |

值得強調第二列：分開 db 解決的是「不要跟會被例行清空的東西共用命名空間」，**不是**
「Redis 很可靠」。驅逐與持久性那兩項還在。

#### 因此哪些能搬、哪些不能

| 寫入 | 後端 | 為什麼 |
| --- | --- | --- |
| `POST /activities` | 資料庫 | 沒有天然唯一鍵，冪等碼是**唯一**的保證。紀錄遺失 = 重試留下第二場活動，而且沒有任何東西會發現。 |
| `POST /activities/{id}/registration` | Redis | `unique(activity_id, user_id)` 在後面兜底。紀錄遺失時重播退化成重新執行，而重新執行會撞唯一鍵、整筆 rollback、名額還回去 —— 壞掉的是回應內容，不是資料正確性。 |
| `DELETE /activities/{id}/registration` | Redis | 同上，取消由 `WHERE status = Confirmed` 的條件式 UPDATE 守著。 |

判斷準則寫成一句話：**這個寫入有沒有第二層防線？有 → Redis 可以。沒有 → 必須資料庫。**

選擇寫在路由旁邊（`routes/api.php` 的 `idempotent:database` / `idempotent:redis`），而不是
藏在設定檔裡 —— 下一個加路由的人會先看到它。打錯名稱會丟例外而不是安靜地退回預設值，
`RouteStoreNamesTest` 讓這件事在部署前就被發現。

#### 結論

**Redis 是第一層過濾：快、可失效。資料庫的唯一鍵是最終保證：慢、不可失效。**

兩層的職責不同，因此判準也不同 —— 第一層可以為了可用性而放水（限流 fail open），
最終保證不行。這個系統裡同樣的結構出現了三次：限流之於報名、冪等碼之於唯一鍵、
Redis 之於資料庫。
