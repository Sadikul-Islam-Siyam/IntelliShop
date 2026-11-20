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

        Route::middleware('role:admin,super-admin,system-analyst,branch-manager,inventory-manager,purchase-manager')->group(function () {
            Route::post('anomalies/run', [\App\Http\Controllers\Api\V1\AnomalyController::class, 'run'])->name('api.v1.anomalies.run');
            Route::get('anomalies', [\App\Http\Controllers\Api\V1\AnomalyController::class, 'index'])->name('api.v1.anomalies.index');
            Route::get('anomalies/{anomaly}', [\App\Http\Controllers\Api\V1\AnomalyController::class, 'show'])->name('api.v1.anomalies.show');
            Route::post('anomalies/{anomaly}/review', [\App\Http\Controllers\Api\V1\AnomalyController::class, 'review'])->name('api.v1.anomalies.review');
            Route::post('basket/run', [\App\Http\Controllers\Api\V1\MarketBasketController::class, 'run'])->name('api.v1.basket.run');
            Route::get('basket/rules', [\App\Http\Controllers\Api\V1\MarketBasketController::class, 'index'])->name('api.v1.basket.rules');
        });

        Route::middleware('role:admin,super-admin,branch-manager,inventory-manager')->group(function () {
            Route::post('markdown/run', [\App\Http\Controllers\Api\V1\MarkdownController::class, 'run'])->name('api.v1.markdown.run');
            Route::get('markdown/recommendations', [\App\Http\Controllers\Api\V1\MarkdownController::class, 'index'])->name('api.v1.markdown.index');
            Route::post('markdown/{recommendation}/approve', [\App\Http\Controllers\Api\V1\MarkdownController::class, 'approve'])->name('api.v1.markdown.approve');
            Route::post('markdown/{recommendation}/reject', [\App\Http\Controllers\Api\V1\MarkdownController::class, 'reject'])->name('api.v1.markdown.reject');
            Route::post('phantom/run', [\App\Http\Controllers\Api\V1\PhantomInventoryController::class, 'run'])->name('api.v1.phantom.run');
            Route::get('phantom/candidates', [\App\Http\Controllers\Api\V1\PhantomInventoryController::class, 'candidates'])->name('api.v1.phantom.candidates');
        });

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
        Route::middleware('role:admin,super-admin,branch-manager,inventory-manager,purchase-manager,sales-employee,cashier,system-analyst')->group(function () {
            Route::get('crosssell/{product}', [\App\Http\Controllers\Api\V1\CrossSellController::class, 'show'])->name('api.v1.crosssell');
            Route::get('pos/substitutes/{product}', [\App\Http\Controllers\Api\V1\ProductSubstitutionController::class, 'show'])->name('api.v1.pos.substitutes');
        });
        Route::middleware('role:admin,super-admin,branch-manager,sales-employee,cashier')->group(function () {
            Route::get('customers', [CustomerController::class, 'index'])->name('api.v1.customers.index');
            Route::get('customers/{customer}/history', [\App\Http\Controllers\Api\V1\CustomerHistoryController::class, 'show'])->name('api.v1.customers.history');
            Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('api.v1.customers.show');
            Route::get('promotions', [\App\Http\Controllers\Api\V1\PromotionController::class, 'index'])->name('api.v1.promotions.index');
            Route::post('promotions/send', [\App\Http\Controllers\Api\V1\PromotionController::class, 'send'])->name('api.v1.promotions.send');
        });
        Route::middleware('role:admin,branch-manager,sales-employee')->group(function () {
            Route::post('customers', [CustomerController::class, 'store'])->name('api.v1.customers.store');
            Route::put('customers/{customer}', [CustomerController::class, 'update'])->name('api.v1.customers.update');
            Route::patch('customers/{customer}', [CustomerController::class, 'update']);
            Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->name('api.v1.customers.destroy');
        });

        // Supplier Evaluation & Fulfillment endpoints (#47, #48, #67, #68)
        Route::middleware('role:admin,super-admin,branch-manager,inventory-manager,purchase-manager,system-analyst')->group(function () {
            Route::get('suppliers/ranking', [\App\Http\Controllers\Api\V1\SupplierRankingController::class, 'index'])->name('api.v1.suppliers.ranking.index');
            Route::post('suppliers/ranking/override', [\App\Http\Controllers\Api\V1\SupplierRankingController::class, 'override'])->name('api.v1.suppliers.ranking.override');
            Route::get('suppliers/evaluate', [\App\Http\Controllers\Api\V1\SupplierEvaluationController::class, 'index'])->name('api.v1.suppliers.evaluate.index');
            Route::post('suppliers/evaluate', [\App\Http\Controllers\Api\V1\SupplierEvaluationController::class, 'evaluate'])->name('api.v1.suppliers.evaluate');
            Route::post('suppliers/fulfillment', [\App\Http\Controllers\Api\V1\SupplierEvaluationController::class, 'recalculateFulfillment'])->name('api.v1.suppliers.fulfillment');
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

        // Stock-Out Risk Classification endpoints (#44)
        Route::middleware('role:admin,super-admin,system-analyst,branch-manager,inventory-manager,purchase-manager')->group(function () {
            Route::get('stock-risk', [\App\Http\Controllers\Api\V1\StockRiskController::class, 'index'])->name('api.v1.stock-risk.index');
        });
        Route::middleware('role:admin,super-admin,system-analyst,purchase-manager,branch-manager')->group(function () {
            Route::post('stock-risk/run', [\App\Http\Controllers\Api\V1\StockRiskController::class, 'run'])->name('api.v1.stock-risk.run');
        });

        // Dynamic Replenishment & Safety Stock endpoints (#45, #46)
        Route::middleware('role:admin,super-admin,system-analyst,branch-manager,inventory-manager,purchase-manager')->group(function () {
            Route::get('reorder', [\App\Http\Controllers\Api\V1\ReorderController::class, 'index'])->name('api.v1.reorder.index');
        });
        Route::middleware('role:admin,super-admin,purchase-manager,branch-manager,system-analyst')->group(function () {
            Route::post('reorder/calculate', [\App\Http\Controllers\Api\V1\ReorderController::class, 'calculate'])->name('api.v1.reorder.calculate');
            Route::post('reorder/{id}/review', [\App\Http\Controllers\Api\V1\RecommendationReviewController::class, 'reviewReorder'])->name('api.v1.reorder.review');
        });

        // Slow-Moving & Overstock Detection endpoints (#50, #51)
        Route::middleware('role:admin,super-admin,system-analyst,branch-manager,inventory-manager,purchase-manager')->group(function () {
            Route::post('overstock/run', [\App\Http\Controllers\Api\V1\OverstockController::class, 'run'])->name('api.v1.overstock.run');
            Route::get('overstock/alerts', [\App\Http\Controllers\Api\V1\OverstockController::class, 'alerts'])->name('api.v1.overstock.alerts');
        });

        // Sales & Inventory Reports and Chart.js Visualizations (#33, #34)
        Route::middleware('role:admin,super-admin,branch-manager,inventory-manager,purchase-manager,sales-employee,system-analyst')->group(function () {
            Route::get('reports/sales', [\App\Http\Controllers\Api\V1\ReportController::class, 'sales'])->name('api.v1.reports.sales');
            Route::get('reports/inventory', [\App\Http\Controllers\Api\V1\ReportController::class, 'inventory'])->name('api.v1.reports.inventory');
            Route::get('reports/charts/{type?}', [\App\Http\Controllers\Api\V1\ReportController::class, 'charts'])->name('api.v1.reports.charts');
        });

        // Rule-Based Recommendation Engine & Priority Queueing (#57, #58)
        Route::middleware('role:admin,super-admin,branch-manager,inventory-manager,purchase-manager,system-analyst')->group(function () {
            Route::post('recommendations/run', [\App\Http\Controllers\Api\V1\RecommendationController::class, 'run'])->name('api.v1.recommendations.run');
            Route::get('recommendations', [\App\Http\Controllers\Api\V1\RecommendationController::class, 'index'])->name('api.v1.recommendations.index');
        });

        // Natural-Language Business Queries Intent Classification & Slot Filling (#60)
        Route::middleware('role:admin,super-admin,branch-manager,inventory-manager,purchase-manager,sales-employee,system-analyst')->group(function () {
            Route::post('nlq/resolve', [\App\Http\Controllers\Api\V1\NlqController::class, 'resolve'])->name('api.v1.nlq.resolve');
            Route::post('nlq/ask', [\App\Http\Controllers\Api\V1\NlqController::class, 'resolve'])->name('api.v1.nlq.ask');
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
