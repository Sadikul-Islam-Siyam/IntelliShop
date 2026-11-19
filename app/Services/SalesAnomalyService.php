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
