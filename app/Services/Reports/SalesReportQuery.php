<?php

namespace App\Services\Reports;

use App\Models\Branch;
use App\Models\Sale;
use App\Models\SaleItem;
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

        $salesQuery = Sale::query()->where('sales.status', $status);

        if ($branchId !== null) {
            $salesQuery->where('sales.branch_id', $branchId);
        }

        if ($startDate !== null) {
            $salesQuery->where(function ($q) use ($startDate) {
                $q->where('sales.sale_date', '>=', $startDate)
                    ->orWhere(function ($q2) use ($startDate) {
                        $q2->whereNull('sales.sale_date')->where('sales.created_at', '>=', $startDate);
                    });
            });
        }

        if ($endDate !== null) {
            $salesQuery->where(function ($q) use ($endDate) {
                $q->where('sales.sale_date', '<=', $endDate)
                    ->orWhere(function ($q2) use ($endDate) {
                        $q2->whereNull('sales.sale_date')->where('sales.created_at', '<=', $endDate);
                    });
            });
        }

        if ($paymentMethod !== null) {
            $salesQuery->where('sales.payment_method', $paymentMethod);
        }

        if ($productId !== null || $categoryId !== null) {
            $salesQuery->whereHas('items', function ($q) use ($productId, $categoryId) {
                if ($productId !== null) {
                    $q->where('sale_items.product_id', $productId);
                }
                if ($categoryId !== null) {
                    $q->whereHas('product', fn ($pq) => $pq->where('products.category_id', $categoryId));
                }
            });
        }

        $sales = (clone $salesQuery)
            ->with(['branch:id,name', 'customer:id,name,segment', 'cashier:id,name', 'items.product:id,name,sku,category_id,cost_price,selling_price'])
            ->orderByDesc('sales.sale_date')
            ->orderByDesc('sales.id')
            ->get();

        $saleIds = $sales->pluck('id')->all();

        // Line items filtered by matching sales (and optional product/category filter)
        $itemsQuery = SaleItem::query()
            ->whereIn('sale_items.sale_id', $saleIds)
            ->with(['product:id,name,sku,category_id,cost_price,selling_price', 'sale:id,branch_id,sale_date,created_at']);

        if ($productId !== null) {
            $itemsQuery->where('sale_items.product_id', $productId);
        }
        if ($categoryId !== null) {
            $itemsQuery->whereHas('product', fn ($pq) => $pq->where('products.category_id', $categoryId));
        }

        $saleItems = empty($saleIds) ? collect() : $itemsQuery->get();

        $totalTransactions = $sales->count();
        $grossSubtotal = round((float) $sales->sum('subtotal'), 2);
        $totalDiscount = round((float) $sales->sum('discount_amount'), 2);
        $totalTax = round((float) $sales->sum('tax_amount'), 2);
        $netRevenue = round((float) $sales->sum('total_amount'), 2);
        $totalUnitsSold = (int) $saleItems->sum('quantity');
        $itemsSubtotalSum = round((float) $saleItems->sum('subtotal'), 2);
        $avgOrderValue = $totalTransactions > 0 ? round($netRevenue / $totalTransactions, 2) : 0.0;

        // Daily breakdown
        $dailyMap = [];
        foreach ($sales as $sale) {
            $day = ($sale->sale_date ?? $sale->created_at ?? now())->format('Y-m-d');
            if (!isset($dailyMap[$day])) {
                $dailyMap[$day] = [
                    'date' => $day,
                    'transactions' => 0,
                    'units_sold' => 0,
                    'subtotal' => 0.0,
                    'discount_amount' => 0.0,
                    'tax_amount' => 0.0,
                    'total_revenue' => 0.0,
                ];
            }
            $dailyMap[$day]['transactions']++;
            $dailyMap[$day]['subtotal'] = round($dailyMap[$day]['subtotal'] + (float) $sale->subtotal, 2);
            $dailyMap[$day]['discount_amount'] = round($dailyMap[$day]['discount_amount'] + (float) $sale->discount_amount, 2);
            $dailyMap[$day]['tax_amount'] = round($dailyMap[$day]['tax_amount'] + (float) $sale->tax_amount, 2);
            $dailyMap[$day]['total_revenue'] = round($dailyMap[$day]['total_revenue'] + (float) $sale->total_amount, 2);
        }

        foreach ($saleItems as $item) {
            $day = ($item->sale?->sale_date ?? $item->sale?->created_at ?? now())->format('Y-m-d');
            if (isset($dailyMap[$day])) {
                $dailyMap[$day]['units_sold'] += (int) $item->quantity;
            }
        }

        ksort($dailyMap);
        $dailyBreakdown = array_values($dailyMap);

        // By branch breakdown
        $branchMap = [];
        foreach ($sales as $sale) {
            $bId = (int) $sale->branch_id;
            if (!isset($branchMap[$bId])) {
                $branchMap[$bId] = [
                    'branch_id' => $bId,
                    'branch_name' => $sale->branch?->name ?? "Branch #{$bId}",
                    'transactions' => 0,
                    'subtotal' => 0.0,
                    'discount_amount' => 0.0,
                    'tax_amount' => 0.0,
                    'total_revenue' => 0.0,
                ];
            }
            $branchMap[$bId]['transactions']++;
            $branchMap[$bId]['subtotal'] = round($branchMap[$bId]['subtotal'] + (float) $sale->subtotal, 2);
            $branchMap[$bId]['discount_amount'] = round($branchMap[$bId]['discount_amount'] + (float) $sale->discount_amount, 2);
            $branchMap[$bId]['tax_amount'] = round($branchMap[$bId]['tax_amount'] + (float) $sale->tax_amount, 2);
            $branchMap[$bId]['total_revenue'] = round($branchMap[$bId]['total_revenue'] + (float) $sale->total_amount, 2);
        }
        $byBranch = array_values($branchMap);

        // By product breakdown
        $productMap = [];
        foreach ($saleItems as $item) {
            $pId = (int) $item->product_id;
            if (!isset($productMap[$pId])) {
                $productMap[$pId] = [
                    'product_id' => $pId,
                    'product_name' => $item->product?->name ?? "Product #{$pId}",
                    'sku' => $item->product?->sku ?? '',
                    'quantity_sold' => 0,
                    'revenue' => 0.0,
                ];
            }
            $productMap[$pId]['quantity_sold'] += (int) $item->quantity;
            $productMap[$pId]['revenue'] = round($productMap[$pId]['revenue'] + (float) $item->subtotal, 2);
        }
        usort($productMap, fn (array $a, array $b) => $b['revenue'] <=> $a['revenue']);
        $byProduct = array_values($productMap);

        // By payment method breakdown
        $paymentMap = [];
        foreach ($sales as $sale) {
            $pm = strtolower((string) ($sale->payment_method ?: 'cash'));
            if (!isset($paymentMap[$pm])) {
                $paymentMap[$pm] = [
                    'payment_method' => $pm,
                    'transactions' => 0,
                    'total_revenue' => 0.0,
                ];
            }
            $paymentMap[$pm]['transactions']++;
            $paymentMap[$pm]['total_revenue'] = round($paymentMap[$pm]['total_revenue'] + (float) $sale->total_amount, 2);
        }
        $byPaymentMethod = array_values($paymentMap);

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
