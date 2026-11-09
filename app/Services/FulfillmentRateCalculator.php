<?php

namespace App\Services;

use App\Models\ProductSupplier;
use App\Models\Purchase;
use App\Models\PurchaseOrderItem;
use App\Models\SystemSetting;
use Carbon\Carbon;

class FulfillmentRateCalculator
{
    public const NEUTRAL_FULFILLMENT_RATE = 0.9000;

    /**
     * Calculate and persist fulfillment_rate for a single ProductSupplier record
     * over the rolling window (default 90 days from supplier_fulfillment_window_days).
     *
     * Formula:
     *   fulfillment_rate = sum(quantity_received) / sum(quantity_ordered)
     *   If no order history exists in the window -> neutral value (0.9000).
     */
    public function calculateForProductSupplier(
        ProductSupplier $productSupplier,
        ?int $windowDays = null,
        float $neutralRate = self::NEUTRAL_FULFILLMENT_RATE
    ): float {
        $days = $windowDays ?? $this->resolveWindowDays();
        $cutoffDate = Carbon::today()->subDays($days)->toDateString();

        $orderItems = PurchaseOrderItem::query()
            ->where('product_id', $productSupplier->product_id)
            ->whereHas('purchaseOrder', function ($query) use ($productSupplier, $cutoffDate) {
                $query->where('supplier_id', $productSupplier->supplier_id)
                    ->whereDate('order_date', '>=', $cutoffDate)
                    ->whereNotIn('status', ['cancelled', 'rejected', 'draft']);
            })
            ->get();

        $totalOrdered = (int) $orderItems->sum('quantity');

        if ($totalOrdered <= 0) {
            $rate = round($neutralRate, 4);
            $productSupplier->update(['fulfillment_rate' => $rate]);

            return $rate;
        }

        $poIds = $orderItems->pluck('purchase_order_id')->unique()->values()->all();

        $totalReceived = (int) Purchase::query()
            ->whereIn('purchase_order_id', $poIds)
            ->where('product_id', $productSupplier->product_id)
            ->sum('quantity_received');

        $rawRate = $totalReceived / $totalOrdered;
        $clampedRate = round(max(0.0, min(1.0, $rawRate)), 4);

        $productSupplier->update(['fulfillment_rate' => $clampedRate]);

        return $clampedRate;
    }

    /**
     * Recalculate fulfillment_rate for all product_suppliers (or filtered by product/supplier).
     *
     * @return array<int, array{id: int, product_id: int, supplier_id: int, fulfillment_rate: float}>
     */
    public function recalculateAll(?int $productId = null, ?int $supplierId = null, ?int $windowDays = null): array
    {
        $days = $windowDays ?? $this->resolveWindowDays();
        $query = ProductSupplier::query();

        if ($productId !== null) {
            $query->where('product_id', $productId);
        }
        if ($supplierId !== null) {
            $query->where('supplier_id', $supplierId);
        }

        $records = $query->get();
        $results = [];

        foreach ($records as $record) {
            $rate = $this->calculateForProductSupplier($record, $days);
            $results[] = [
                'id' => $record->id,
                'product_id' => $record->product_id,
                'supplier_id' => $record->supplier_id,
                'fulfillment_rate' => $rate,
            ];
        }

        return $results;
    }

    /**
     * Resolve lookback window in days from system_settings (default 90).
     */
    public function resolveWindowDays(): int
    {
        $val = SystemSetting::query()
            ->where('setting_key', 'supplier_fulfillment_window_days')
            ->value('setting_value');

        return $val !== null && (int) $val > 0 ? (int) $val : 90;
    }
}

