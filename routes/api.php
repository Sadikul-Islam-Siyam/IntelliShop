<?php

use App\Http\Controllers\Api\V1\AnalyticsExportController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\ForecastController;
use App\Http\Controllers\Api\V1\SettingsController;
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
        Route::middleware('role:admin,branch-manager,sales-employee,cashier')->group(function () {
            Route::get('customers', [CustomerController::class, 'index'])->name('api.v1.customers.index');
            Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('api.v1.customers.show');
        });
        Route::middleware('role:admin,branch-manager,sales-employee')->group(function () {
            Route::post('customers', [CustomerController::class, 'store'])->name('api.v1.customers.store');
            Route::put('customers/{customer}', [CustomerController::class, 'update'])->name('api.v1.customers.update');
            Route::patch('customers/{customer}', [CustomerController::class, 'update']);
            Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->name('api.v1.customers.destroy');
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
        Route::middleware('role:admin')->group(function () {
            Route::get('settings', [SettingsController::class, 'index'])->name('api.v1.settings.index');
            Route::put('settings', [SettingsController::class, 'update'])->name('api.v1.settings.update');
        });

        // System Audit Logs endpoints (Administrator only)
        Route::get('audit-logs', [\App\Http\Controllers\Api\V1\AuditLogController::class, 'index'])
            ->middleware('role:admin,super-admin')
            ->name('api.v1.audit-logs.index');
        Route::get('audit-logs/{id}', [\App\Http\Controllers\Api\V1\AuditLogController::class, 'show'])
            ->middleware('role:admin,super-admin')
            ->name('api.v1.audit-logs.show');

        // Demand Forecasting endpoints
        Route::middleware('role:admin,super-admin,system-analyst,branch-manager,inventory-manager,purchase-manager')->group(function () {
            Route::get('forecasts', [ForecastController::class, 'index'])->name('api.v1.forecasts.index');
        });
        Route::middleware('role:admin,super-admin,system-analyst,purchase-manager,branch-manager')->group(function () {
            Route::post('forecasts/run', [ForecastController::class, 'run'])->name('api.v1.forecasts.run');
            Route::post('forecasts/run-all', [ForecastController::class, 'runAll'])->name('api.v1.forecasts.run-all');
        });
    });

    // Analytics Data Extraction API
    Route::prefix('analytics/export')->middleware('analytics.token')->group(function () {
        Route::get('sales', [AnalyticsExportController::class, 'sales'])->name('api.v1.analytics.export.sales');
        Route::get('inventory', [AnalyticsExportController::class, 'inventory'])->name('api.v1.analytics.export.inventory');
        Route::get('batches', [AnalyticsExportController::class, 'batches'])->name('api.v1.analytics.export.batches');
        Route::get('purchases', [AnalyticsExportController::class, 'purchases'])->name('api.v1.analytics.export.purchases');
    });
});

// Root /api/analytics/export/* aliases
Route::prefix('analytics/export')->middleware('analytics.token')->group(function () {
    Route::get('sales', [AnalyticsExportController::class, 'sales']);
    Route::get('inventory', [AnalyticsExportController::class, 'inventory']);
    Route::get('batches', [AnalyticsExportController::class, 'batches']);
    Route::get('purchases', [AnalyticsExportController::class, 'purchases']);
});

// Root API aliases for convenience
Route::post('auth/login', [AuthController::class, 'login'])->middleware('login.throttle');
Route::post('auth/register', [AuthController::class, 'register']);
Route::middleware('auth')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::get('dashboard', [\App\Http\Controllers\Api\V1\DashboardController::class, 'index']);
});
