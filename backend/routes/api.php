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

    // Throttled by hand: these are the only unauthenticated endpoints where
    // guessing repeatedly pays off.
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('/register', [EmailAuthController::class, 'register']);
        Route::post('/login', [EmailAuthController::class, 'login']);
    });

    Route::middleware('auth:sanctum')->post('/logout', [EmailAuthController::class, 'logout']);
});

Route::get('/regions', [RegionController::class, 'index']);
Route::get('/sports', [SportController::class, 'index']);

// Browsable without logging in; joining is not.
Route::get('/activities', [ActivityController::class, 'index']);
Route::get('/activities/{activity}', [ActivityController::class, 'show']);

Route::middleware('auth:sanctum')->group(function () {
    // `idempotent` honours an Idempotency-Key header when the client sends
    // one, replaying the first response instead of acting twice. Creating an
    // activity has no natural key at all, so a retried request would otherwise
    // leave a duplicate behind.
    Route::middleware('idempotent')->group(function () {
        Route::post('/activities', [ActivityController::class, 'store']);

        // Singular from the caller's point of view: they have at most one
        // registration per activity, so there is no id in the path.
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
