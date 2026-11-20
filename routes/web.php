<?php

use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\BranchController;
use App\Http\Controllers\Web\CustomerController;
use App\Http\Controllers\Web\ForecastController;
use App\Http\Controllers\Web\SettingsController;
use App\Http\Controllers\Web\SupplierController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

// Authentication web routes
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('login.throttle')->name('login.post');
});

// Authenticated dashboard routes
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::middleware('role:admin,branch-manager')->group(function () {
        Route::get('/branches', [BranchController::class, 'index'])->name('branches.index');
    });
    Route::middleware('role:admin')->group(function () {
        Route::get('/branches/create', [BranchController::class, 'create'])->name('branches.create');
        Route::post('/branches', [BranchController::class, 'store'])->name('branches.store');
        Route::get('/branches/{branch}/edit', [BranchController::class, 'edit'])->name('branches.edit');
        Route::put('/branches/{branch}', [BranchController::class, 'update'])->name('branches.update');
        Route::delete('/branches/{branch}', [BranchController::class, 'destroy'])->name('branches.destroy');
    });
    Route::middleware('role:admin,super-admin,branch-manager,sales-employee,cashier')->group(function () {
        Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('/customers/{customer}/history', [\App\Http\Controllers\Web\CustomerHistoryController::class, 'show'])->name('customers.history');
        Route::get('/history', [\App\Http\Controllers\Web\CustomerHistoryController::class, 'index'])->name('customers.history.index');
        Route::get('/promotions', [\App\Http\Controllers\Web\PromotionController::class, 'index'])->name('promotions.index');
        Route::post('/promotions/send', [\App\Http\Controllers\Web\PromotionController::class, 'send'])->name('promotions.send');
    });
    Route::middleware('role:admin,branch-manager,sales-employee')->group(function () {
        Route::get('/customers/create', [CustomerController::class, 'create'])->name('customers.create');
        Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
        Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])->name('customers.edit');
        Route::put('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
        Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy');
    });
    Route::middleware('role:admin,super-admin,branch-manager,inventory-manager,purchase-manager,system-analyst')->group(function () {
        Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
        Route::get('/suppliers/evaluate', [\App\Http\Controllers\Web\SupplierEvaluationController::class, 'index'])->name('suppliers.evaluate');
        Route::get('/suppliers/ranking', [\App\Http\Controllers\Web\SupplierEvaluationController::class, 'index'])->name('suppliers.ranking');
    });
    Route::middleware('role:admin,purchase-manager')->group(function () {
        Route::get('/suppliers/create', [SupplierController::class, 'create'])->name('suppliers.create');
        Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
        Route::get('/suppliers/{supplier}/edit', [SupplierController::class, 'edit'])->name('suppliers.edit');
        Route::put('/suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
        Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy'])->name('suppliers.destroy');
    });
    Route::middleware('role:admin')->group(function () {
        Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
    });

    // Dashboard root landing: redirects user to their dedicated role workspace
    Route::get('/dashboard', [\App\Http\Controllers\Web\DashboardController::class, 'index'])->name('dashboard');

    // Role-specific dashboard route protected by role middleware
    Route::get('/dashboard/{role}', [\App\Http\Controllers\Web\DashboardController::class, 'show'])
        ->middleware('role')
        ->name('dashboard.role');

    // System Audit Logs page (Administrator only)
    Route::get('/audit-logs', [\App\Http\Controllers\Web\AuditLogController::class, 'index'])
        ->middleware('role:admin,super-admin')
        ->name('audit-logs.index');

    // Demand Forecasting workspace
    Route::middleware('role:admin,super-admin,system-analyst,branch-manager,inventory-manager,purchase-manager')->group(function () {
        Route::get('/forecasting', [ForecastController::class, 'index'])->name('forecasts.index');
        Route::get('/forecasting/hub', [ForecastController::class, 'index'])->name('forecasting.index');
        Route::get('/forecasts', [ForecastController::class, 'index'])->name('forecasts.list');
    });

    // Stock-Out Risk Classification workspace (#44)
    Route::middleware('role:admin,super-admin,system-analyst,branch-manager,inventory-manager,purchase-manager')->group(function () {
        Route::get('/stock-risk', [\App\Http\Controllers\Web\StockRiskController::class, 'index'])->name('stock-risk.index');
    });

    // Dynamic Replenishment & Safety Stock workspace (#45)
    Route::middleware('role:admin,super-admin,system-analyst,branch-manager,inventory-manager,purchase-manager')->group(function () {
        Route::get('/reorders', [\App\Http\Controllers\Web\ReorderController::class, 'index'])->name('reorder.index');
        Route::get('/reorder', [\App\Http\Controllers\Web\ReorderController::class, 'index'])->name('reorder.show');
        Route::post('/reorders/approve', [\App\Http\Controllers\Web\ReorderController::class, 'approve'])->name('reorder.approve');
    });

    // Slow-Moving & Overstock Alerts workspace (#50, #51)
    Route::middleware('role:admin,super-admin,system-analyst,branch-manager,inventory-manager,purchase-manager')->group(function () {
        Route::get('/overstock', [\App\Http\Controllers\Web\OverstockController::class, 'index'])->name('overstock.index');
        Route::get('/slow-moving', [\App\Http\Controllers\Web\OverstockController::class, 'index'])->name('overstock.slow-moving');
    });

    // Sales & Inventory Reports and Chart.js Visualizations workspace (#33, #34)
    Route::middleware('role:admin,super-admin,branch-manager,inventory-manager,purchase-manager,sales-employee,system-analyst')->group(function () {
        Route::get('/reports', [\App\Http\Controllers\Web\ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/sales', [\App\Http\Controllers\Web\ReportController::class, 'index'])->name('reports.sales');
        Route::get('/reports/inventory', [\App\Http\Controllers\Web\ReportController::class, 'index'])->name('reports.inventory');
    });

    // Sales Anomaly Review Workflow workspace (#54)
    Route::middleware('role:admin,super-admin,system-analyst,branch-manager,inventory-manager,purchase-manager')->group(function () {
        Route::get('/anomalies', [\App\Http\Controllers\Web\AnomalyController::class, 'index'])->name('anomalies.index');
        Route::post('/anomalies/{anomaly}/review', [\App\Http\Controllers\Web\AnomalyController::class, 'review'])->name('anomalies.review');
    });

    // Market Basket Analysis & Cross-Sell Display workspace (#56)
    Route::middleware('role:admin,super-admin,system-analyst,branch-manager,inventory-manager,purchase-manager,sales-employee,cashier')->group(function () {
        Route::get('/market-basket', [\App\Http\Controllers\Web\MarketBasketController::class, 'index'])->name('market-basket.index');
        Route::post('/market-basket/run', [\App\Http\Controllers\Web\MarketBasketController::class, 'run'])->name('market-basket.run');
    });

    // Recommendation Engine & Priority Queue workspace (#57, #58, #59)
    Route::middleware('role:admin,super-admin,system-analyst,branch-manager,inventory-manager,purchase-manager')->group(function () {
        Route::get('/recommendations', [\App\Http\Controllers\Web\RecommendationController::class, 'index'])->name('recommendations.index');
        Route::post('/recommendations/run', [\App\Http\Controllers\Web\RecommendationController::class, 'run'])->name('recommendations.run');
        Route::post('/recommendations/{id}/review', [\App\Http\Controllers\Web\RecommendationController::class, 'review'])->name('recommendations.review');
    });

    // Phantom Inventory & Cycle Count Reconciliation (#71)
    Route::middleware('role:admin,super-admin,branch-manager,inventory-manager')->group(function () {
        Route::get('/cycle-counts', [\App\Http\Controllers\Web\CycleCountController::class, 'index'])->name('cycle-counts.index');
        Route::post('/cycle-counts/{id}/count', [\App\Http\Controllers\Web\CycleCountController::class, 'recordCount'])->name('cycle-counts.count');
        Route::post('/cycle-counts/{id}/approve', [\App\Http\Controllers\Web\CycleCountController::class, 'approve'])->name('cycle-counts.approve');
        Route::post('/cycle-counts/{id}/reject', [\App\Http\Controllers\Web\CycleCountController::class, 'reject'])->name('cycle-counts.reject');
    });

    // Natural-Language Business Queries workspace (#60)
    Route::middleware('role:admin,super-admin,system-analyst,branch-manager,inventory-manager,purchase-manager,sales-employee')->group(function () {
        Route::get('/nlq', [\App\Http\Controllers\Web\NlqController::class, 'index'])->name('nlq.index');
    });
});
