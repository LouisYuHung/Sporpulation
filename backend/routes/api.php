<?php

use App\Http\Controllers\Auth\LineAuthController;
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

Route::middleware('auth:sanctum')->get('/me', function (Request $request) {
    return $request->user();
});
