<?php

namespace App\Services\Reports;

use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\User;

/**
 * InventoryReportQuery (Sub-Feature #33)
 *
 * Generates branch-filtered inventory balance, valuation, low-stock, and batch
 * reconciliation reports.
 */
class InventoryReportQuery
{
    public function __construct(
        protected SalesReportQuery $salesReportQuery
    ) {}

    /**
     * Execute the inventory report query with filters and branch-scope enforcement.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function execute(array $filters = [], ?User $user = null): array
    {
        $branchId = $this->salesReportQuery->resolveScopedBranchId($filters['branch_id'] ?? null, $user);
        $categoryId = !empty($filters['category_id']) ? (int) $filters['category_id'] : null;
        $productId = !empty($filters['product_id']) ? (int) $filters['product_id'] : null;
        $lowStockOnly = !empty($filters['low_stock_only']) && filter_var($filters['low_stock_only'], FILTER_VALIDATE_BOOLEAN);

        $invQuery = Inventory::query()
            ->with(['product:id,name,sku,category_id,cost_price,selling_price,min_stock_level,reorder_point,status', 'branch:id,name']);

        if ($branchId !== null) {
            $invQuery->where('inventory.branch_id', $branchId);
        }
        if ($productId !== null) {
            $invQuery->where('inventory.product_id', $productId);
        }
        if ($categoryId !== null) {
            $invQuery->whereHas('product', fn ($pq) => $pq->where('products.category_id', $categoryId));
        }

        $inventories = $invQuery->orderBy('branch_id')->orderBy('product_id')->get();

        // Active batches grouped by product_id and branch_id
        $batchQuery = InventoryBatch::query()
            ->where('status', 'active')
            ->where('quantity_remaining', '>', 0);

        if ($branchId !== null) {
            $batchQuery->where('branch_id', $branchId);
        }
        if ($productId !== null) {
            $batchQuery->where('product_id', $productId);
        }

        $batches = $batchQuery->get();
        $batchesByKey = $batches->groupBy(fn (InventoryBatch $b) => "{$b->product_id}_{$b->branch_id}");

        $items = [];
        $branchTotals = [];
        $totalUnitsOnHand = 0;
        $totalUnitsReserved = 0;
        $totalCostValuation = 0.0;
        $totalRetailValuation = 0.0;
        $lowStockCount = 0;
        $outOfStockCount = 0;

        foreach ($inventories as $inv) {
            $product = $inv->product;
            if (!$product) {
                continue;
            }

            $qtyAvailable = (int) $inv->quantity_available;
            $qtyReserved = (int) ($inv->quantity_reserved ?? 0);
            $reorderPoint = (int) ($product->reorder_point ?? 0);
            $isLowStock = $qtyAvailable <= $reorderPoint;
            $isOutOfStock = $qtyAvailable <= 0;

            if ($lowStockOnly && !$isLowStock) {
                continue;
            }

            $costPrice = (float) ($product->cost_price ?? 0.0);
            $sellingPrice = (float) ($product->selling_price ?? 0.0);
            $costValuation = round($qtyAvailable * $costPrice, 2);
            $retailValuation = round($qtyAvailable * $sellingPrice, 2);

            $key = "{$inv->product_id}_{$inv->branch_id}";
            $productBatches = $batchesByKey->get($key, collect());
            $batchRemainingSum = (int) $productBatches->sum('quantity_remaining');

            $items[] = [
                'inventory_id' => $inv->id,
                'product_id' => (int) $product->id,
                'product_name' => $product->name,
                'sku' => $product->sku,
                'category_id' => $product->category_id,
                'branch_id' => (int) $inv->branch_id,
                'branch_name' => $inv->branch?->name ?? "Branch #{$inv->branch_id}",
                'quantity_available' => $qtyAvailable,
                'quantity_reserved' => $qtyReserved,
                'max_capacity' => $inv->max_capacity !== null ? (int) $inv->max_capacity : null,
                'reorder_point' => $reorderPoint,
                'is_low_stock' => $isLowStock,
                'is_out_of_stock' => $isOutOfStock,
                'cost_price' => $costPrice,
                'selling_price' => $sellingPrice,
                'cost_valuation' => $costValuation,
                'retail_valuation' => $retailValuation,
                'active_batches_count' => $productBatches->count(),
                'batch_quantity_remaining' => $batchRemainingSum,
            ];

            $totalUnitsOnHand += $qtyAvailable;
            $totalUnitsReserved += $qtyReserved;
            $totalCostValuation = round($totalCostValuation + $costValuation, 2);
            $totalRetailValuation = round($totalRetailValuation + $retailValuation, 2);

            if ($isLowStock) {
                $lowStockCount++;
            }
            if ($isOutOfStock) {
                $outOfStockCount++;
            }

            $bId = (int) $inv->branch_id;
            if (!isset($branchTotals[$bId])) {
                $branchTotals[$bId] = [
                    'branch_id' => $bId,
                    'branch_name' => $inv->branch?->name ?? "Branch #{$bId}",
                    'sku_count' => 0,
                    'units_on_hand' => 0,
                    'units_reserved' => 0,
                    'cost_valuation' => 0.0,
                    'retail_valuation' => 0.0,
                    'low_stock_count' => 0,
                ];
            }
            $branchTotals[$bId]['sku_count']++;
            $branchTotals[$bId]['units_on_hand'] += $qtyAvailable;
            $branchTotals[$bId]['units_reserved'] += $qtyReserved;
            $branchTotals[$bId]['cost_valuation'] = round($branchTotals[$bId]['cost_valuation'] + $costValuation, 2);
            $branchTotals[$bId]['retail_valuation'] = round($branchTotals[$bId]['retail_valuation'] + $retailValuation, 2);
            if ($isLowStock) {
                $branchTotals[$bId]['low_stock_count']++;
            }
        }

        $itemsUnitsSum = (int) array_sum(array_column($items, 'quantity_available'));
        $itemsCostSum = round(array_sum(array_column($items, 'cost_valuation')), 2);
        $itemsRetailSum = round(array_sum(array_column($items, 'retail_valuation')), 2);

        return [
            'filters' => [
                'branch_id' => $branchId,
                'category_id' => $categoryId,
                'product_id' => $productId,
                'low_stock_only' => $lowStockOnly,
            ],
            'summary' => [
                'total_skus' => count(array_unique(array_column($items, 'product_id'))),
                'total_inventory_rows' => count($items),
                'total_units_on_hand' => $totalUnitsOnHand,
                'total_units_reserved' => $totalUnitsReserved,
                'total_cost_valuation' => $totalCostValuation,
                'total_retail_valuation' => $totalRetailValuation,
                'low_stock_count' => $lowStockCount,
                'out_of_stock_count' => $outOfStockCount,
                'active_batches_count' => $batches->count(),
            ],
            'reconciliation' => [
                'reconciled' => $totalUnitsOnHand === $itemsUnitsSum
                    && abs($totalCostValuation - $itemsCostSum) < 0.01
                    && abs($totalRetailValuation - $itemsRetailSum) < 0.01,
                'items_units_sum' => $itemsUnitsSum,
                'items_cost_valuation_sum' => $itemsCostSum,
                'items_retail_valuation_sum' => $itemsRetailSum,
            ],
            'items' => $items,
            'by_branch' => array_values($branchTotals),
        ];
    }
}
