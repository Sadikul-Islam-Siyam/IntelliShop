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
    // Public authentication endpoints
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('login.throttle')
        ->name('api.v1.auth.login');
    Route::post('auth/register', [AuthController::class, 'register'])->name('api.v1.auth.register');

    // Authenticated endpoints
    Route::middleware('auth')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.v1.auth.logout');
        Route::get('auth/me', [AuthController::class, 'me'])->name('api.v1.auth.me');

        // Dashboard workspace metadata
        Route::get('dashboard', [\App\Http\Controllers\Api\V1\DashboardController::class, 'index'])->name('api.v1.dashboard');
        Route::get('dashboard/{role}', [\App\Http\Controllers\Api\V1\DashboardController::class, 'show'])
            ->middleware('role')
            ->name('api.v1.dashboard.role');

        // User management CRUD
        Route::apiResource('users', UserController::class)->names('api.v1.users');

        // System Audit Logs endpoints (Administrator only)
        Route::get('audit-logs', [\App\Http\Controllers\Api\V1\AuditLogController::class, 'index'])
            ->middleware('role:admin,super-admin')
            ->name('api.v1.audit-logs.index');
        Route::get('audit-logs/{id}', [\App\Http\Controllers\Api\V1\AuditLogController::class, 'show'])
            ->middleware('role:admin,super-admin')
            ->name('api.v1.audit-logs.show');
    });
});

// Root API aliases for convenience
Route::post('auth/login', [AuthController::class, 'login'])->middleware('login.throttle');
Route::post('auth/register', [AuthController::class, 'register']);
Route::middleware('auth')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::get('dashboard', [\App\Http\Controllers\Api\V1\DashboardController::class, 'index']);
});
