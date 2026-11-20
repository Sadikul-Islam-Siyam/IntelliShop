<?php

namespace App\Services;

use App\Contracts\SettingsService;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\SalesAnomaly;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SalesAnomalyService
{
    public function __construct(
        protected SettingsService $settings
    ) {}

    /**
     * Run anomaly detection for products across branches.
     *
     * @param int|null $productId
     * @param int|null $branchId
     * @param int $lookbackDays
     * @return Collection<SalesAnomaly>
     */
    public function runDetection(?int $productId = null, ?int $branchId = null, int $lookbackDays = 60): Collection
    {
        $madThreshold = (float) ($this->settings->get('anomaly_mad_threshold', 3.5) ?? 3.5);
        $iqrK = (float) ($this->settings->get('anomaly_iqr_k', 1.5) ?? 1.5);

        $cutoff = Carbon::today()->subDays($lookbackDays)->toDateString();

        $query = SaleItem::query()
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.status', 'completed')
            ->whereDate('sales.created_at', '>=', $cutoff)
            ->select([
                'sale_items.product_id',
                'sales.branch_id',
                DB::raw('DATE(sales.created_at) as sale_date'),
                DB::raw('SUM(sale_items.quantity) as daily_quantity'),
            ])
            ->groupBy('sale_items.product_id', 'sales.branch_id', DB::raw('DATE(sales.created_at)'));

        if ($productId) {
            $query->where('sale_items.product_id', $productId);
        }
        if ($branchId) {
            $query->where('sales.branch_id', $branchId);
        }

        $records = $query->get()->groupBy(fn($r) => "{$r->product_id}_{$r->branch_id}");
        $savedAnomalies = collect();

        foreach ($records as $key => $dailyPoints) {
            if ($dailyPoints->count() < 3) {
                continue;
            }

            [$pId, $bId] = explode('_', $key);
            $values = $dailyPoints->pluck('daily_quantity')->map(fn($v) => (float) $v)->toArray();
            $dates = $dailyPoints->pluck('sale_date')->toArray();

            $median = $this->calculateMedian($values);
            $absDevs = array_map(fn($v) => abs($v - $median), $values);
            $mad = $this->calculateMedian($absDevs);

            sort($values);
            $q1 = $this->calculatePercentile($values, 0.25);
            $q3 = $this->calculatePercentile($values, 0.75);
            $iqr = max(0.0, $q3 - $q1);
            $lowerFence = $q1 - ($iqrK * $iqr);
            $upperFence = $q3 + ($iqrK * $iqr);

            foreach ($dailyPoints as $point) {
                $qty = (float) $point->daily_quantity;
                $date = $point->sale_date;

                if ($mad > 0) {
                    $modZ = 0.6745 * ($qty - $median) / $mad;
                } else {
                    $mean = array_sum($values) / count($values);
                    $variance = array_sum(array_map(fn($v) => ($v - $mean) ** 2, $values)) / count($values);
                    $std = sqrt($variance);
                    $modZ = $std > 0 ? ($qty - $mean) / $std : 0.0;
                }

                $isMadAnomaly = abs($modZ) > $madThreshold;
                $isIqrAnomaly = ($qty < $lowerFence) || ($qty > $upperFence);

                if ($isMadAnomaly || $isIqrAnomaly) {
                    $notes = [];
                    if ($isMadAnomaly) {
                        $notes[] = sprintf('Modified Z-Score: %.2f (> %.1f)', $modZ, $madThreshold);
                    }
                    if ($isIqrAnomaly) {
                        $notes[] = sprintf('IQR Fence [%.1f, %.1f]', $lowerFence, $upperFence);
                    }

                    $anomaly = SalesAnomaly::updateOrCreate(
                        [
                            'product_id' => (int) $pId,
                            'branch_id' => (int) $bId,
                            'detected_date' => $date,
                        ],
                        [
                            'expected_value' => round($median, 2),
                            'actual_value' => round($qty, 2),
                            'deviation_score' => round($modZ, 3),
                            'status' => 'pending',
                            'notes' => implode('; ', $notes),
                        ]
                    );

                    $savedAnomalies->push($anomaly);
                }
            }
        }

        return $savedAnomalies;
    }

    /**
     * Build explanatory operational context (stock-outs, active discounts, promotions, holidays/weekends)
     * around an anomaly's detected_date (#54 Anomaly Review Workflow).
     *
     * @return array<string, mixed>
     */
    public function buildContextForAnomaly(SalesAnomaly $anomaly): array
    {
        $date = $anomaly->detected_date ? Carbon::parse($anomaly->detected_date) : Carbon::today();
        $dateStr = $date->toDateString();
        $product = $anomaly->product;

        // 1. Check stock-outs or zero-inventory transactions near detected_date
        $inventory = \App\Models\Inventory::query()
            ->where('product_id', $anomaly->product_id)
            ->where('branch_id', $anomaly->branch_id)
            ->first();

        $stockOutTxCount = \App\Models\InventoryTransaction::query()
            ->where('product_id', $anomaly->product_id)
            ->where('branch_id', $anomaly->branch_id)
            ->whereDate('created_at', '>=', $date->copy()->subDays(2)->toDateString())
            ->whereDate('created_at', '<=', $date->copy()->addDay()->toDateString())
            ->where(function ($q) {
                $q->where('quantity_after', '<=', 0)
                    ->orWhere('transaction_type', 'stock_out');
            })
            ->count();

        $hadStockOut = ($inventory && (int) $inventory->quantity_available <= 0) || $stockOutTxCount > 0;

        // 2. Active discounts on detected_date
        $discounts = \App\Models\Discount::query()
            ->where('status', 'active')
            ->where(function ($q) use ($anomaly, $product) {
                $q->where('product_id', $anomaly->product_id);
                if ($product && $product->category_id) {
                    $q->orWhere('category_id', $product->category_id);
                }
            })
            ->where(function ($q) use ($date) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $date->copy()->endOfDay());
            })
            ->where(function ($q) use ($date) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $date->copy()->startOfDay());
            })
            ->get(['id', 'name', 'type', 'value'])
            ->map(fn ($d) => [
                'id' => $d->id,
                'name' => $d->name,
                'type' => $d->type,
                'value' => (float) $d->value,
            ])
            ->values()
            ->all();

        // 3. Customer promotion emails dispatched near detected_date
        $promotionsCount = \App\Models\ProductPromotion::query()
            ->where('product_id', $anomaly->product_id)
            ->whereBetween('created_at', [$date->copy()->subDays(7)->startOfDay(), $date->copy()->endOfDay()])
            ->count();

        // 4. Calendar / holiday / weekend context
        $dayOfWeek = $date->format('l');
        $isWeekend = in_array($date->dayOfWeekIso, [5, 6, 7], true); // Fri/Sat/Sun retail peak

        return [
            'detected_date' => $dateStr,
            'day_of_week' => $dayOfWeek,
            'is_weekend_or_holiday' => $isWeekend,
            'calendar_note' => $isWeekend ? "Weekend/Peak trading day ({$dayOfWeek})" : "Standard trading weekday ({$dayOfWeek})",
            'stock_out_detected' => $hadStockOut,
            'current_stock' => $inventory ? (int) $inventory->quantity_available : null,
            'active_discounts' => $discounts,
            'has_active_discount' => !empty($discounts),
            'recent_promotions_sent' => $promotionsCount,
            'signal_notice' => 'Alert is a statistical screening signal, not proof of error or fraud.',
        ];
    }

    private function calculateMedian(array $numbers): float
    {
        if (empty($numbers)) {
            return 0.0;
        }
        sort($numbers);
        $count = count($numbers);
        $mid = intdiv($count, 2);
        return $count % 2 === 1 ? (float) $numbers[$mid] : ($numbers[$mid - 1] + $numbers[$mid]) / 2.0;
    }

    private function calculatePercentile(array $sorted, float $p): float
    {
        $n = count($sorted);
        if ($n === 0) return 0.0;
        if ($n === 1) return (float) $sorted[0];
        $rank = $p * ($n - 1);
        $k = (int) floor($rank);
        $d = $rank - $k;
        if ($k + 1 < $n) {
            return (float) $sorted[$k] + $d * ((float) $sorted[$k + 1] - (float) $sorted[$k]);
        }
        return (float) $sorted[$k];
    }
}
