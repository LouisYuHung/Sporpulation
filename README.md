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

| Method | Endpoint | 說明 |
| --- | --- | --- |
| GET | `/api/activities` | 未開始的活動，可用 `sport_id`、`district_id` 篩選 |
| POST | `/api/activities` | 開團（需登入） |
| GET | `/api/activities/{id}` | 活動詳情 |
| POST | `/api/activities/{id}/registration` | 報名，佔一個名額 |
| DELETE | `/api/activities/{id}/registration` | 取消報名，釋放名額 |
| GET | `/api/me/registrations` | 我的報名 |

報名與取消都是冪等的：重送同一個請求不會佔到第二個名額，也不會重複釋放。
名額滿了回 409 `activity_full`，活動已開始回 409 `activity_closed`。

### 併發驗證

測試套件跑在 sqlite 上、一次只跑一個請求，驗證得了邏輯但驗證不了併發。
下面這個指令會 fork 出真正同時發生的請求，需要 MySQL：

```bash
php artisan activities:check-concurrency --capacity=7 --racers=100
```

它會寫入測試資料，只能指向可拋棄的資料庫。
