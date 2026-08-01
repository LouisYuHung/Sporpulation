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
    Route::middleware('idempotent')->group(function () {
        Route::post('/activities', [ActivityController::class, 'store']);

        // 從呼叫者的角度看是單數：每個活動他最多只有一筆報名，因此路徑中不需要
        // 帶 id。
        Route::prefix('activities/{activity}/registration')->group(function () {
            Route::post('/', [ActivityRegistrationController::class, 'store']);
            Route::delete('/', [ActivityRegistrationController::class, 'destroy']);
        });
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
