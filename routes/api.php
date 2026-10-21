<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\SupplierController;
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

        Route::middleware('role:admin,branch-manager')->group(function () {
            Route::get('branches', [BranchController::class, 'index'])->name('api.v1.branches.index');
            Route::get('branches/{branch}', [BranchController::class, 'show'])->name('api.v1.branches.show');
        });
        Route::middleware('role:admin')->group(function () {
            Route::post('branches', [BranchController::class, 'store'])->name('api.v1.branches.store');
            Route::put('branches/{branch}', [BranchController::class, 'update'])->name('api.v1.branches.update');
            Route::patch('branches/{branch}', [BranchController::class, 'update']);
            Route::delete('branches/{branch}', [BranchController::class, 'destroy'])->name('api.v1.branches.destroy');
        });

        Route::middleware('role:admin,branch-manager,inventory-manager,purchase-manager,system-analyst')->group(function () {
            Route::get('suppliers', [SupplierController::class, 'index'])->name('api.v1.suppliers.index');
            Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->name('api.v1.suppliers.show');
        });
        Route::middleware('role:admin,purchase-manager')->group(function () {
            Route::post('suppliers', [SupplierController::class, 'store'])->name('api.v1.suppliers.store');
            Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->name('api.v1.suppliers.update');
            Route::patch('suppliers/{supplier}', [SupplierController::class, 'update']);
            Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])->name('api.v1.suppliers.destroy');
        });

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
