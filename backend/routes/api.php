<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\ActivityRegistrationController;
use App\Http\Controllers\Auth\EmailAuthController;
use App\Http\Controllers\Auth\LineAuthController;
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
    // 回應而不是執行第二次。建立活動完全沒有天然的唯一鍵，否則重試的請求就會留下
    // 一筆重複資料。

    // 建立活動完全沒有天然的唯一鍵，否則重試的請求就會留下一筆重複資料。
    Route::middleware('idempotent')->post('/activities', [ActivityController::class, 'store']);

    // 從呼叫者的角度看是單數：每個活動他最多只有一筆報名，因此路徑中不需要帶 id。
    Route::prefix('activities/{activity}/registration')->group(function () {
        // 順序有意義，而且只有寫在同一個 middleware([...]) 裡才保證得到 —— 群組
        // middleware 一律先於路由 middleware，所以把 idempotent 放在外層群組會讓
        // 它跑在限流之前。那樣每個被擋下的請求仍會先佔一次冪等 key 再釋放（429 在
        // RELEASED_STATUSES 裡），兩次資料庫往返全白費。
        Route::middleware(['throttle.registration', 'idempotent'])
            ->post('/', [ActivityRegistrationController::class, 'store']);

        // 取消暫不限流：正常使用者「報名後發現時間衝突」會馬上取消，不該吃掉額度。
        // 代價是報名/取消反覆刷仍然打得到資料庫 —— 那條路徑靠條件式 UPDATE 守著。
        Route::middleware('idempotent')
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
