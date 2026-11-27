<?php

namespace App\Services\Reports;

use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SalesReportQuery (Sub-Feature #33)
 *
 * Generates date-range and branch-filtered sales analytical reports
 * with full financial reconciliation between sale headers and line items.
 */
class SalesReportQuery
{
    /**
     * Execute the sales report query with filters and branch-scope enforcement.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function execute(array $filters = [], ?User $user = null): array
    {
        $branchId = $this->resolveScopedBranchId($filters['branch_id'] ?? null, $user);

        $startDate = !empty($filters['start_date'] ?? $filters['from'] ?? $filters['date_from'] ?? null)
            ? Carbon::parse((string) ($filters['start_date'] ?? $filters['from'] ?? $filters['date_from']))->startOfDay()
            : null;

        $endDate = !empty($filters['end_date'] ?? $filters['to'] ?? $filters['date_to'] ?? null)
            ? Carbon::parse((string) ($filters['end_date'] ?? $filters['to'] ?? $filters['date_to']))->endOfDay()
            : null;

        $categoryId = !empty($filters['category_id']) ? (int) $filters['category_id'] : null;
        $productId = !empty($filters['product_id']) ? (int) $filters['product_id'] : null;
        $paymentMethod = !empty($filters['payment_method']) ? (string) $filters['payment_method'] : null;
        $status = !empty($filters['status']) ? (string) $filters['status'] : 'completed';

        $salesQuery = $this->filteredSalesQuery(
            $branchId,
            $startDate,
            $endDate,
            $categoryId,
            $productId,
            $paymentMethod,
            $status,
        );

        // Aggregate in MySQL instead of hydrating hundreds of thousands of sales
        // and sale-item models into PHP collections.
        $summary = (clone $salesQuery)
            ->selectRaw('COUNT(*) as total_transactions, COALESCE(SUM(sales.subtotal), 0) as gross_subtotal, COALESCE(SUM(sales.discount_amount), 0) as total_discount, COALESCE(SUM(sales.tax_amount), 0) as total_tax, COALESCE(SUM(sales.total_amount), 0) as net_revenue')
            ->first();

        $totalTransactions = (int) ($summary->total_transactions ?? 0);
        $grossSubtotal = round((float) ($summary->gross_subtotal ?? 0), 2);
        $totalDiscount = round((float) ($summary->total_discount ?? 0), 2);
        $totalTax = round((float) ($summary->total_tax ?? 0), 2);
        $netRevenue = round((float) ($summary->net_revenue ?? 0), 2);
        $avgOrderValue = $totalTransactions > 0 ? round($netRevenue / $totalTransactions, 2) : 0.0;

        $dailySales = (clone $salesQuery)
            ->selectRaw('DATE(COALESCE(sales.sale_date, sales.created_at)) as report_date, COUNT(*) as transactions, SUM(sales.subtotal) as subtotal, SUM(sales.discount_amount) as discount_amount, SUM(sales.tax_amount) as tax_amount, SUM(sales.total_amount) as total_revenue')
            ->groupByRaw('DATE(COALESCE(sales.sale_date, sales.created_at))')
            ->orderBy('report_date')
            ->get();

        $itemsQuery = $this->filteredItemsQuery($salesQuery, $categoryId, $productId);
        $itemSummary = (clone $itemsQuery)
            ->selectRaw('COALESCE(SUM(report_items.quantity), 0) as total_units, COALESCE(SUM(report_items.subtotal), 0) as items_subtotal')
            ->first();
        $totalUnitsSold = (int) ($itemSummary->total_units ?? 0);
        $itemsSubtotalSum = round((float) ($itemSummary->items_subtotal ?? 0), 2);

        $unitsByDay = (clone $itemsQuery)
            ->selectRaw('DATE(COALESCE(report_sales.sale_date, report_sales.created_at)) as report_date, SUM(report_items.quantity) as units_sold')
            ->groupByRaw('DATE(COALESCE(report_sales.sale_date, report_sales.created_at))')
            ->pluck('units_sold', 'report_date');

        $dailyBreakdown = $dailySales->map(fn ($row) => [
            'date' => (string) $row->report_date,
            'transactions' => (int) $row->transactions,
            'units_sold' => (int) ($unitsByDay[$row->report_date] ?? 0),
            'subtotal' => round((float) $row->subtotal, 2),
            'discount_amount' => round((float) $row->discount_amount, 2),
            'tax_amount' => round((float) $row->tax_amount, 2),
            'total_revenue' => round((float) $row->total_revenue, 2),
        ])->values()->all();

        $byBranch = (clone $salesQuery)
            ->leftJoin('branches as report_branches', 'report_branches.id', '=', 'sales.branch_id')
            ->selectRaw('sales.branch_id, COALESCE(report_branches.name, CONCAT("Branch #", sales.branch_id)) as branch_name, COUNT(*) as transactions, SUM(sales.subtotal) as subtotal, SUM(sales.discount_amount) as discount_amount, SUM(sales.tax_amount) as tax_amount, SUM(sales.total_amount) as total_revenue')
            ->groupBy('sales.branch_id', 'report_branches.name')
            ->get()
            ->map(fn ($row) => [
                'branch_id' => (int) $row->branch_id,
                'branch_name' => $row->branch_name,
                'transactions' => (int) $row->transactions,
                'subtotal' => round((float) $row->subtotal, 2),
                'discount_amount' => round((float) $row->discount_amount, 2),
                'tax_amount' => round((float) $row->tax_amount, 2),
                'total_revenue' => round((float) $row->total_revenue, 2),
            ])->values()->all();

        $byProduct = (clone $itemsQuery)
            ->join('products as report_products', 'report_products.id', '=', 'report_items.product_id')
            ->selectRaw('report_items.product_id, report_products.name as product_name, report_products.sku, SUM(report_items.quantity) as quantity_sold, SUM(report_items.subtotal) as revenue')
            ->groupBy('report_items.product_id', 'report_products.name', 'report_products.sku')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($row) => [
                'product_id' => (int) $row->product_id,
                'product_name' => $row->product_name,
                'sku' => $row->sku ?? '',
                'quantity_sold' => (int) $row->quantity_sold,
                'revenue' => round((float) $row->revenue, 2),
            ])->values()->all();

        $byPaymentMethod = (clone $salesQuery)
            ->selectRaw("LOWER(COALESCE(NULLIF(sales.payment_method, ''), 'cash')) as payment_method, COUNT(*) as transactions, SUM(sales.total_amount) as total_revenue")
            ->groupByRaw("LOWER(COALESCE(NULLIF(sales.payment_method, ''), 'cash'))")
            ->get()
            ->map(fn ($row) => [
                'payment_method' => $row->payment_method,
                'transactions' => (int) $row->transactions,
                'total_revenue' => round((float) $row->total_revenue, 2),
            ])->values()->all();

        // Reconciliation verification
        $dailyRevenueSum = round(array_sum(array_column($dailyBreakdown, 'total_revenue')), 2);
        $branchRevenueSum = round(array_sum(array_column($byBranch, 'total_revenue')), 2);
        $productUnitsSum = (int) array_sum(array_column($byProduct, 'quantity_sold'));

        return [
            'filters' => [
                'branch_id' => $branchId,
                'start_date' => $startDate?->toDateString(),
                'end_date' => $endDate?->toDateString(),
                'category_id' => $categoryId,
                'product_id' => $productId,
                'payment_method' => $paymentMethod,
            ],
            'summary' => [
                'total_sales_count' => $totalTransactions,
                'total_transactions' => $totalTransactions,
                'total_units_sold' => $totalUnitsSold,
                'gross_subtotal' => $grossSubtotal,
                'total_discount' => $totalDiscount,
                'total_tax' => $totalTax,
                'net_revenue' => $netRevenue,
                'total_revenue' => $netRevenue,
                'items_subtotal_sum' => $itemsSubtotalSum,
                'average_order_value' => $avgOrderValue,
            ],
            'reconciliation' => [
                'reconciled' => abs($netRevenue - $dailyRevenueSum) < 0.01
                    && abs($netRevenue - $branchRevenueSum) < 0.01
                    && $totalUnitsSold === $productUnitsSum,
                'daily_revenue_sum' => $dailyRevenueSum,
                'branch_revenue_sum' => $branchRevenueSum,
                'product_units_sum' => $productUnitsSum,
            ],
            'daily_breakdown' => $dailyBreakdown,
            'by_branch' => $byBranch,
            'by_product' => $byProduct,
            'by_payment_method' => $byPaymentMethod,
        ];
    }

    private function filteredSalesQuery(
        ?int $branchId,
        ?Carbon $startDate,
        ?Carbon $endDate,
        ?int $categoryId,
        ?int $productId,
        ?string $paymentMethod,
        string $status,
    ) {
        $query = Sale::query()->where('sales.status', $status);

        if ($branchId !== null) {
            $query->where('sales.branch_id', $branchId);
        }

        if ($startDate !== null) {
            $query->where(function ($q) use ($startDate) {
                $q->where('sales.sale_date', '>=', $startDate)
                    ->orWhere(fn ($q2) => $q2->whereNull('sales.sale_date')->where('sales.created_at', '>=', $startDate));
            });
        }

        if ($endDate !== null) {
            $query->where(function ($q) use ($endDate) {
                $q->where('sales.sale_date', '<=', $endDate)
                    ->orWhere(fn ($q2) => $q2->whereNull('sales.sale_date')->where('sales.created_at', '<=', $endDate));
            });
        }

        if ($paymentMethod !== null) {
            $query->where('sales.payment_method', $paymentMethod);
        }

        if ($productId !== null || $categoryId !== null) {
            $query->whereExists(function ($subquery) use ($productId, $categoryId) {
                $subquery->selectRaw('1')
                    ->from('sale_items as report_filter_items')
                    ->whereColumn('report_filter_items.sale_id', 'sales.id');

                if ($productId !== null) {
                    $subquery->where('report_filter_items.product_id', $productId);
                }

                if ($categoryId !== null) {
                    $subquery->join('products as report_filter_products', 'report_filter_products.id', '=', 'report_filter_items.product_id')
                        ->where('report_filter_products.category_id', $categoryId);
                }
            });
        }

        return $query;
    }

    private function filteredItemsQuery($salesQuery, ?int $categoryId, ?int $productId)
    {
        $query = DB::table('sale_items as report_items')
            ->join('sales as report_sales', 'report_sales.id', '=', 'report_items.sale_id')
            ->whereIn('report_sales.id', (clone $salesQuery)->select('sales.id'));

        if ($productId !== null) {
            $query->where('report_items.product_id', $productId);
        }

        if ($categoryId !== null) {
            $query->join('products as report_item_products', 'report_item_products.id', '=', 'report_items.product_id')
                ->where('report_item_products.category_id', $categoryId);
        }

        return $query;
    }

    /**
     * Enforce branch-scoped access for non-global roles.
     */
    public function resolveScopedBranchId(mixed $requestedBranchId, ?User $user = null): ?int
    {
        $reqId = ($requestedBranchId !== null && $requestedBranchId !== '') ? (int) $requestedBranchId : null;

        if (!$user) {
            return $reqId;
        }

        $roleName = strtolower((string) ($user->role?->name ?? ''));
        $isGlobalRole = $user->isAdmin() || in_array($roleName, ['admin', 'super administrator', 'super-admin', 'system analyst', 'system-analyst'], true);

        if (!$isGlobalRole && $user->branch_id !== null) {
            return (int) $user->branch_id;
        }

        return $reqId;
    }
}
