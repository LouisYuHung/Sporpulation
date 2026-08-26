<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\ActivityRegistrationController;
use App\Http\Controllers\Auth\EmailAuthController;
use App\Http\Controllers\Auth\LineAuthController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\RegionController;
use App\Http\Controllers\SportController;
use App\Http\Controllers\UserAreaController;
use App\Http\Controllers\UserSportController;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/ping', function () {
    return response()->json([
        'message' => 'pong',
        'timestamp' => now()->toIso8601String(),
    ]);
});

// 給 Prometheus 抓的。刻意不套用任何冪等或限流中介層 —— 抓取本身不改變狀態，
// 而被自己的限流擋下的監控端點是最沒有用的那種監控。
Route::get('/metrics', MetricsController::class);

Route::prefix('auth')->group(function () {
    Route::prefix('line')->group(function () {
        Route::get('/redirect', [LineAuthController::class, 'redirect']);
        Route::get('/callback', [LineAuthController::class, 'callback']);
    });

    // 手動加上流量限制：這是唯一一組「反覆猜測有利可圖」的未驗證端點。
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('/register', [EmailAuthController::class, 'register']);
        Route::post('/login', [EmailAuthController::class, 'login']);
    });

    Route::middleware('auth:sanctum')->post('/logout', [EmailAuthController::class, 'logout']);
});

Route::get('/regions', [RegionController::class, 'index']);
Route::get('/sports', [SportController::class, 'index']);

// 未登入也能瀏覽；但報名不行。
Route::get('/activities', [ActivityController::class, 'index']);
Route::get('/activities/{activity}', [ActivityController::class, 'show']);

Route::middleware('auth:sanctum')->group(function () {
    // 當用戶端有送 Idempotency-Key 標頭時，`idempotent` 會遵循它，重播第一次的
    // 回應而不是執行第二次。以路由為單位逐條掛而非包成群組 - 見下方報名那條，
    // 群組會奪走對執行順序的控制權。

    // 建立活動完全沒有天然的唯一鍵：冪等碼是它唯一的保證，因此必須留在不會失效的
    // 資料庫。Redis 的紀錄可能因為崩潰或記憶體壓力而提前消失，那樣重試就會留下
    // 第二場活動，而且沒有任何東西會發現。
    Route::middleware('idempotent:database')->post('/activities', [ActivityController::class, 'store']);

    // 從呼叫者的角度看是單數：每個活動他最多只有一筆報名，因此路徑中不需要帶 id。
    Route::prefix('activities/{activity}/registration')->group(function () {
        // 報名可以接受 Redis：unique(activity_id, user_id) 是最終保證。Redis 的紀錄
        // 若遺失，重試會退化成「重新執行」而不是「重播」—— 而重新執行會撞上唯一鍵、
        // 整筆 rollback、名額還回去。壞掉的是回應的內容，不是資料的正確性。
        //
        // 兩層職責不同：Redis 是快而可失效的第一層過濾，唯一鍵是慢而不可失效的
        // 最終保證。
        //
        // （陣列順序即執行順序，限流必須排在前面：反過來的話每個被擋下的請求仍會
        // 先佔一次冪等 key 再釋放，兩次資料庫往返全白費。
        // ThrottleRegistrationTest::a_throttled_request_never_reaches_the_idempotency_store
        // 用 DB::listen 盯著這件事。）
        Route::middleware(['metrics.registration', 'throttle.registration', 'idempotent:redis'])
            ->post('/', [ActivityRegistrationController::class, 'store']);

        // 取消同樣有守衛：WHERE status = Confirmed 的條件式 UPDATE 保證一個名額
        // 只會被釋放一次。
        Route::middleware('idempotent:redis')
            ->delete('/', [ActivityRegistrationController::class, 'destroy']);
    });

    Route::prefix('me')->group(function () {
        Route::get('/', function (Request $request) {
            return new UserResource(
                $request->user()->load(['areas.city', 'areas.postalCode', 'sports'])
            );
        });

        Route::get('/registrations', [ActivityRegistrationController::class, 'index']);

        Route::prefix('areas')->group(function () {
            Route::get('/', [UserAreaController::class, 'index']);
            Route::post('/', [UserAreaController::class, 'store']);
            Route::delete('/{district}', [UserAreaController::class, 'destroy']);
        });

        Route::prefix('sports')->group(function () {
            Route::get('/', [UserSportController::class, 'index']);
            Route::post('/', [UserSportController::class, 'store']);
            Route::patch('/{sport}', [UserSportController::class, 'update']);
            Route::delete('/{sport}', [UserSportController::class, 'destroy']);
        });
    });
});
