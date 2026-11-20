<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Category;
use App\Services\Reports\InventoryReportQuery;
use App\Services\Reports\ReportChartService;
use App\Services\Reports\SalesReportQuery;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function __construct(
        protected SalesReportQuery $salesReportQuery,
        protected InventoryReportQuery $inventoryReportQuery,
        protected ReportChartService $reportChartService
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $branches = Branch::query()->where('status', 'active')->orderBy('name')->get();
        $categories = Category::query()->orderBy('name')->get();

        $filters = $request->only([
            'branch_id',
            'start_date',
            'end_date',
            'category_id',
            'product_id',
            'payment_method',
            'low_stock_only',
        ]);

        $salesReport = $this->salesReportQuery->execute($filters, $user);
        $inventoryReport = $this->inventoryReportQuery->execute($filters, $user);
        $charts = $this->reportChartService->buildCharts($filters, $user, null, $salesReport, $inventoryReport);

        return view('reports.index', [
            'user' => $user,
            'branches' => $branches,
            'categories' => $categories,
            'filters' => $salesReport['filters'],
            'salesReport' => $salesReport,
            'inventoryReport' => $inventoryReport,
            'charts' => $charts['charts'] ?? [],
        ]);
    }
}
