<?php

namespace App\Services;

use App\Contracts\SettingsService;
use App\Models\CycleCount;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\SaleItem;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PhantomInventoryService
{
    public function __construct(
        protected SettingsService $settings
    ) {}

    /**
     * Run phantom inventory detection across inventory records.
     *
     * @param int|null $branchId
     * @param int|null $productId
     * @return Collection<CycleCount>
     */
    public function runDetection(?int $branchId = null, ?int $productId = null): Collection
    {
        $baselineDays = (int) ($this->settings->get('phantom_baseline_days', 30) ?? 30);
        $zThreshold = (float) ($this->settings->get('phantom_z_threshold', -2.0) ?? -2.0);
        $persistenceDays = (int) ($this->settings->get('phantom_persistence_days', 3) ?? 3);
        $minHistoryDays = (int) ($this->settings->get('phantom_min_history_days', 30) ?? 30);

        // Fetch inventory where recorded stock > 0
        $query = Inventory::query()->where('quantity_available', '>', 0);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }
        if ($productId) {
            $query->where('product_id', $productId);
        }

        $inventories = $query->with('product')->get();
        $createdTasks = collect();

        $totalWindowDays = $baselineDays + $persistenceDays;
        $startDate = Carbon::today()->subDays($totalWindowDays - 1)->toDateString();

        foreach ($inventories as $inv) {
            $pId = $inv->product_id;
            $bId = $inv->branch_id;
            $stock = (int) $inv->quantity_available;

            // Fetch daily sales series
            $dailySalesRecords = SaleItem::query()
                ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
                ->where('sales.status', 'completed')
                ->where('sales.branch_id', $bId)
                ->where('sale_items.product_id', $pId)
                ->whereDate('sales.created_at', '>=', $startDate)
                ->select([
                    DB::raw('DATE(sales.created_at) as sale_date'),
                    DB::raw('SUM(sale_items.quantity) as daily_qty'),
                ])
                ->groupBy(DB::raw('DATE(sales.created_at)'))
                ->pluck('daily_qty', 'sale_date')
                ->toArray();

            // Build full chronological day series
            $series = [];
            for ($d = $totalWindowDays - 1; $d >= 0; $d--) {
                $curDate = Carbon::today()->subDays($d)->toDateString();
                $series[] = (float) ($dailySalesRecords[$curDate] ?? 0.0);
            }

            // History guard
            if (count($series) < $minHistoryDays) {
                continue;
            }

            // Partition into baseline and recent persistence window
            $baseline = array_slice($series, 0, -$persistenceDays);
            $recent = array_slice($series, -$persistenceDays);

            if (empty($baseline)) {
                continue;
            }

            $mu = array_sum($baseline) / count($baseline);
            $variance = array_sum(array_map(fn($x) => ($x - $mu) ** 2, $baseline)) / count($baseline);
            $sigma = sqrt($variance);

            if ($sigma <= 1e-9) {
                continue;
            }

            // Evaluate z-scores over recent persistence window
            $recentZScores = array_map(fn($x) => ($x - $mu) / $sigma, $recent);
            $allExceed = true;
            foreach ($recentZScores as $z) {
                if ($z > $zThreshold) {
                    $allExceed = false;
                    break;
                }
            }

            if ($allExceed) {
                $avgZ = array_sum($recentZScores) / count($recentZScores);

                // Check if open cycle count already exists
                $existing = CycleCount::where('product_id', $pId)
                    ->where('branch_id', $bId)
                    ->where('status', 'open')
                    ->first();

                if (!$existing) {
                    $task = CycleCount::create([
                        'product_id' => $pId,
                        'branch_id' => $bId,
                        'trigger_source' => 'phantom_inventory',
                        'baseline_mean' => round($mu, 2),
                        'baseline_std' => round($sigma, 2),
                        'z_score' => round($avgZ, 3),
                        'system_quantity' => $stock,
                        'status' => 'open',
                    ]);
                    $createdTasks->push($task);
                } else {
                    $createdTasks->push($existing);
                }
            }
        }

        return $createdTasks;
    }
}
