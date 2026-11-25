<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Reports\InventoryReportQuery;
use App\Services\Reports\ReportChartService;
use App\Services\Reports\SalesReportQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ReportController (Sub-Features #33 & #34)
 *
 * Exposes Sales & Inventory analytical reports and Chart.js visualization JSON endpoints.
 */
class ReportController extends Controller
{
    public function __construct(
        protected SalesReportQuery $salesReportQuery,
        protected InventoryReportQuery $inventoryReportQuery,
        protected ReportChartService $reportChartService
    ) {}

    /**
     * GET /api/v1/reports/sales
     * Generate sales report with date-range & branch filters, reconciliation, and Chart.js data.
     */
    public function sales(Request $request): JsonResponse
    {
        $filters = $request->only([
            'branch_id',
            'start_date',
            'end_date',
            'from',
            'to',
            'date_from',
            'date_to',
            'category_id',
            'product_id',
            'payment_method',
            'status',
        ]);

        $report = $this->salesReportQuery->execute($filters, $request->user());
        $chart = $this->reportChartService->buildSalesChart($filters, $request->user());

        return response()->json([
            'data' => array_merge($report, ['chart' => $chart]),
            'filters' => $report['filters'],
            'summary' => $report['summary'],
            'reconciliation' => $report['reconciliation'],
            'daily_breakdown' => $report['daily_breakdown'],
            'by_branch' => $report['by_branch'],
            'by_product' => $report['by_product'],
            'by_payment_method' => $report['by_payment_method'],
            'chart' => $chart,
        ]);
    }

    /**
     * GET /api/v1/reports/inventory
     * Generate inventory stock balance, valuation, low-stock, and batch report with Chart.js data.
     */
    public function inventory(Request $request): JsonResponse
    {
        $filters = $request->only([
            'branch_id',
            'category_id',
            'product_id',
            'low_stock_only',
        ]);

        $report = $this->inventoryReportQuery->execute($filters, $request->user());
        $chart = $this->reportChartService->buildInventoryChart($filters, $request->user());

        return response()->json([
            'data' => array_merge($report, ['chart' => $chart]),
            'filters' => $report['filters'],
            'summary' => $report['summary'],
            'reconciliation' => $report['reconciliation'],
            'items' => $report['items'],
            'by_branch' => $report['by_branch'],
            'chart' => $chart,
        ]);
    }

    /**
     * GET /api/v1/reports/charts/{type?}
     * Return Chart.js dataset payloads for dashboards and report visualizations (#34).
     */
    public function charts(Request $request, ?string $type = null): JsonResponse
    {
        $filters = $request->only([
            'branch_id',
            'start_date',
            'end_date',
            'from',
            'to',
            'category_id',
            'product_id',
        ]);

        $requestedType = $type ?? $request->query('type');
        $chartPayload = $this->reportChartService->buildCharts($filters, $request->user(), $requestedType);

        return response()->json(array_merge([
            'data' => $chartPayload,
        ], $chartPayload));
    }
}
