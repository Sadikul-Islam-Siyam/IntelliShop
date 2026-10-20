<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| IntelliShop REST API Routes (Version 1 - /api/v1)
|--------------------------------------------------------------------------
| All routes under /api/v1 sit behind REST API standards (Section 20.2).
*/

Route::prefix('v1')->group(function () {
    // Public Authentication Endpoints (Sub-Feature #2 & #3 - Public throttled)
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('login.throttle')
        ->name('api.v1.auth.login');
    Route::post('auth/register', [AuthController::class, 'register'])->name('api.v1.auth.register');

    // Authenticated Endpoints
    Route::middleware('auth')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.v1.auth.logout');
        Route::get('auth/me', [AuthController::class, 'me'])->name('api.v1.auth.me');

        // User Management CRUD (Sub-Feature #2)
        Route::apiResource('users', UserController::class)->names('api.v1.users');
    });
});

// Root API aliases for convenience
Route::post('auth/login', [AuthController::class, 'login'])->middleware('login.throttle');
Route::post('auth/register', [AuthController::class, 'register']);
Route::middleware('auth')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);
});
