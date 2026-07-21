<?php

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
});

Route::get('/regions', [RegionController::class, 'index']);
Route::get('/sports', [SportController::class, 'index']);

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('me')->group(function () {
        Route::get('/', function (Request $request) {
            return new UserResource(
                $request->user()->load(['areas.city', 'areas.postalCode', 'sports'])
            );
        });

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
