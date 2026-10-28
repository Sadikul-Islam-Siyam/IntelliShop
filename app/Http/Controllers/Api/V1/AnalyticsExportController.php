<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Read-only export endpoints returning cleaned-ready JSON (sales aggregated by
 * product-branch-day, inventory balances, batches/expiry, purchases, and promotions)
 * to the analytics service with a service token.
 */
class AnalyticsExportController extends Controller
{
    /**
     * Export aggregated daily sales series by product-branch-day with promotion
     * and stock-out indicators.
     */
    public function sales(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'window_days' => ['nullable', 'integer', 'min:7', 'max:730'],
        ]);

        $productId = isset($validated['product_id']) ? (int) $validated['product_id'] : null;
        $branchId = isset($validated['branch_id']) ? (int) $validated['branch_id'] : null;
        $windowDays = (int) ($validated['window_days'] ?? 180);

        // Determine date range from sales history or query parameters
        $maxSaleDate = DB::table('sales')->max('sale_date');
        $endDate = isset($validated['to'])
            ? CarbonImmutable::parse($validated['to'])->startOfDay()
            : ($maxSaleDate ? CarbonImmutable::parse($maxSaleDate)->startOfDay() : CarbonImmutable::today());

        $startDate = isset($validated['from'])
            ? CarbonImmutable::parse($validated['from'])->startOfDay()
            : $endDate->subDays($windowDays - 1);

        // Query raw sales joined with sale_items aggregated by product, branch, and date
        $salesQuery = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', 'completed')
            ->whereBetween('sales.sale_date', [
                $startDate->startOfDay()->toDateTimeString(),
                $endDate->endOfDay()->toDateTimeString(),
            ]);

        if ($productId !== null) {
            $salesQuery->where('sale_items.product_id', $productId);
        }
        if ($branchId !== null) {
            $salesQuery->where('sales.branch_id', $branchId);
        }

        $dailyRows = $salesQuery
            ->selectRaw('
                sale_items.product_id,
                sales.branch_id,
                DATE(sales.sale_date) as sale_day,
                SUM(sale_items.quantity) as total_quantity,
                SUM(sale_items.subtotal) as total_revenue,
                SUM(sale_items.discount) as total_discount,
                COUNT(DISTINCT sales.id) as transaction_count
            ')
            ->groupBy('sale_items.product_id', 'sales.branch_id', DB::raw('DATE(sales.sale_date)'))
            ->orderBy('sale_items.product_id')
            ->orderBy('sales.branch_id')
            ->orderBy('sale_day')
            ->get();

        // Active discounts for promotion flag lookup
        $discounts = DB::table('discounts')
            ->where('status', 'active')
            ->get(['product_id', 'category_id', 'starts_at', 'ends_at']);

        // Product category lookup
        $productCategories = DB::table('products')->pluck('category_id', 'id');

        // Inventory ledger / balances to help detect stock-out days
        $currentInventory = DB::table('inventory')
            ->get()
            ->keyBy(fn ($row) => $row->product_id . ':' . $row->branch_id);

        // Ledger end-of-day stock snapshots if recorded
        $ledgerQuery = DB::table('inventory_transactions')
            ->whereBetween('created_at', [
                $startDate->startOfDay()->toDateTimeString(),
                $endDate->endOfDay()->toDateTimeString(),
            ]);
        if ($productId !== null) {
            $ledgerQuery->where('product_id', $productId);
        }
        if ($branchId !== null) {
            $ledgerQuery->where('branch_id', $branchId);
        }
        $ledgerMovements = $ledgerQuery
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['product_id', 'branch_id', 'quantity_after', 'created_at']);

        $eodStockMap = [];
        foreach ($ledgerMovements as $movement) {
            $dayKey = CarbonImmutable::parse($movement->created_at)->toDateString();
            $pairKey = $movement->product_id . ':' . $movement->branch_id;
            $eodStockMap[$pairKey][$dayKey] = (int) $movement->quantity_after;
        }

        $series = [];
        foreach ($dailyRows as $row) {
            $pid = (int) $row->product_id;
            $bid = (int) $row->branch_id;
            $dateStr = (string) $row->sale_day;
            $discountSum = (float) $row->total_discount;

            $hasPromo = $discountSum > 0 || $this->isProductOnPromo($pid, $productCategories[$pid] ?? null, $dateStr, $discounts);
            $pairKey = $pid . ':' . $bid;
            $eodStock = $eodStockMap[$pairKey][$dateStr] ?? null;
            $stockout = $eodStock !== null && $eodStock <= 0 && (int) $row->total_quantity === 0;

            $series[] = [
                'product_id' => $pid,
                'branch_id' => $bid,
                'date' => $dateStr,
                'quantity' => (float) $row->total_quantity,
                'revenue' => round((float) $row->total_revenue, 2),
                'discount' => round($discountSum, 2),
                'transactions' => (int) $row->transaction_count,
                'promotion' => $hasPromo,
                'stockout' => $stockout,
                'closing_stock' => $eodStock ?? (isset($currentInventory[$pairKey]) ? (int) $currentInventory[$pairKey]->quantity_available : null),
            ];
        }

        return response()->json([
            'data' => $series,
            'meta' => [
                'from' => $startDate->toDateString(),
                'to' => $endDate->toDateString(),
                'window_days' => $startDate->diffInDays($endDate) + 1,
                'total_rows' => count($series),
                'filters' => [
                    'product_id' => $productId,
                    'branch_id' => $branchId,
                ],
            ],
        ]);
    }

    /**
     * Export current branch inventory balances, reorder parameters, and product metadata.
     */
    public function inventory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $query = DB::table('inventory')
            ->join('products', 'products.id', '=', 'inventory.product_id')
            ->join('branches', 'branches.id', '=', 'inventory.branch_id')
            ->select([
                'inventory.product_id',
                'products.name as product_name',
                'products.sku',
                'inventory.branch_id',
                'branches.name as branch_name',
                'inventory.quantity_available',
                'inventory.quantity_reserved',
                'inventory.max_capacity',
                'products.min_stock_level',
                'products.reorder_point',
                'products.reorder_quantity',
                'products.shelf_life_days',
                'products.is_expiry_tracked',
                'products.cost_price',
                'products.selling_price',
                'inventory.last_updated',
            ])
            ->orderBy('inventory.product_id')
            ->orderBy('inventory.branch_id');

        if (isset($validated['product_id'])) {
            $query->where('inventory.product_id', (int) $validated['product_id']);
        }
        if (isset($validated['branch_id'])) {
            $query->where('inventory.branch_id', (int) $validated['branch_id']);
        }

        $items = $query->get()->map(fn ($row) => [
            'product_id' => (int) $row->product_id,
            'product_name' => $row->product_name,
            'sku' => $row->sku,
            'branch_id' => (int) $row->branch_id,
            'branch_name' => $row->branch_name,
            'quantity_available' => (int) $row->quantity_available,
            'quantity_reserved' => (int) $row->quantity_reserved,
            'max_capacity' => $row->max_capacity !== null ? (int) $row->max_capacity : null,
            'min_stock_level' => (int) $row->min_stock_level,
            'reorder_point' => (int) $row->reorder_point,
            'reorder_quantity' => (int) $row->reorder_quantity,
            'shelf_life_days' => $row->shelf_life_days !== null ? (int) $row->shelf_life_days : null,
            'is_expiry_tracked' => (bool) $row->is_expiry_tracked,
            'cost_price' => (float) $row->cost_price,
            'selling_price' => (float) $row->selling_price,
            'last_updated' => $row->last_updated,
        ])->values();

        return response()->json([
            'data' => $items,
            'meta' => [
                'total_rows' => $items->count(),
            ],
        ]);
    }

    /**
     * Export inventory batches with expiry dates and remaining quantities for FEFO/markdown.
     */
    public function batches(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'status' => ['nullable', 'string'],
        ]);

        $query = DB::table('inventory_batches')
            ->select([
                'id as batch_id',
                'product_id',
                'branch_id',
                'purchase_id',
                'batch_no',
                'expiry_date',
                'quantity_received',
                'quantity_remaining',
                'unit_cost',
                'received_at',
                'status',
            ])
            ->orderBy('expiry_date')
            ->orderBy('received_at');

        if (isset($validated['product_id'])) {
            $query->where('product_id', (int) $validated['product_id']);
        }
        if (isset($validated['branch_id'])) {
            $query->where('branch_id', (int) $validated['branch_id']);
        }
        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $batches = $query->get()->map(fn ($row) => [
            'batch_id' => (int) $row->batch_id,
            'product_id' => (int) $row->product_id,
            'branch_id' => (int) $row->branch_id,
            'purchase_id' => $row->purchase_id !== null ? (int) $row->purchase_id : null,
            'batch_no' => $row->batch_no,
            'expiry_date' => $row->expiry_date,
            'quantity_received' => (int) $row->quantity_received,
            'quantity_remaining' => (int) $row->quantity_remaining,
            'unit_cost' => (float) $row->unit_cost,
            'received_at' => $row->received_at,
            'status' => $row->status,
        ])->values();

        return response()->json([
            'data' => $batches,
            'meta' => [
                'total_rows' => $batches->count(),
            ],
        ]);
    }

    /**
     * Export purchase orders and goods receipts for scheduled receipt projection & supplier fulfillment.
     */
    public function purchases(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
        ]);

        $query = DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->leftJoin('purchases', function ($join) {
                $join->on('purchases.purchase_order_id', '=', 'purchase_orders.id')
                    ->on('purchases.product_id', '=', 'purchase_order_items.product_id');
            })
            ->select([
                'purchase_orders.id as purchase_order_id',
                'purchase_orders.supplier_id',
                'purchase_orders.branch_id',
                'purchase_order_items.product_id',
                'purchase_orders.order_date',
                'purchase_orders.expected_date',
                'purchase_orders.status as order_status',
                'purchase_order_items.quantity as quantity_ordered',
                'purchase_order_items.unit_price',
                'purchases.id as purchase_receipt_id',
                'purchases.batch_no',
                'purchases.expiry_date',
                'purchases.quantity_received',
                'purchases.received_at',
            ])
            ->orderByDesc('purchase_orders.order_date');

        if (isset($validated['product_id'])) {
            $query->where('purchase_order_items.product_id', (int) $validated['product_id']);
        }
        if (isset($validated['branch_id'])) {
            $query->where('purchase_orders.branch_id', (int) $validated['branch_id']);
        }
        if (isset($validated['supplier_id'])) {
            $query->where('purchase_orders.supplier_id', (int) $validated['supplier_id']);
        }

        $rows = $query->get()->map(fn ($row) => [
            'purchase_order_id' => (int) $row->purchase_order_id,
            'supplier_id' => (int) $row->supplier_id,
            'branch_id' => (int) $row->branch_id,
            'product_id' => (int) $row->product_id,
            'order_date' => $row->order_date,
            'expected_date' => $row->expected_date,
            'order_status' => $row->order_status,
            'quantity_ordered' => (int) $row->quantity_ordered,
            'unit_price' => (float) $row->unit_price,
            'purchase_receipt_id' => $row->purchase_receipt_id !== null ? (int) $row->purchase_receipt_id : null,
            'batch_no' => $row->batch_no,
            'expiry_date' => $row->expiry_date,
            'quantity_received' => $row->quantity_received !== null ? (int) $row->quantity_received : 0,
            'received_at' => $row->received_at,
        ])->values();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'total_rows' => $rows->count(),
            ],
        ]);
    }

    private function isProductOnPromo(int $productId, ?int $categoryId, string $dateStr, iterable $discounts): bool
    {
        $day = CarbonImmutable::parse($dateStr);
        foreach ($discounts as $discount) {
            $matchesProduct = $discount->product_id !== null && (int) $discount->product_id === $productId;
            $matchesCategory = $discount->category_id !== null && $categoryId !== null && (int) $discount->category_id === $categoryId;
            if (!$matchesProduct && !$matchesCategory) {
                continue;
            }
            $startsOk = $discount->starts_at === null || $day->greaterThanOrEqualTo(CarbonImmutable::parse($discount->starts_at)->startOfDay());
            $endsOk = $discount->ends_at === null || $day->lessThanOrEqualTo(CarbonImmutable::parse($discount->ends_at)->endOfDay());
            if ($startsOk && $endsOk) {
                return true;
            }
        }

        return false;
    }
}
