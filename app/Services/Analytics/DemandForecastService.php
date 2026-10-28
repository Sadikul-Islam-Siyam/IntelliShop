<?php

namespace App\Services\Analytics;

use App\Contracts\AnalyticsClient;
use App\Contracts\SettingsService;
use App\Models\Branch;
use App\Models\DemandForecast;
use App\Models\ModelRun;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates the demand forecasting pipeline, model run evaluation storage,
 * and horizon forecast persistence.
 */
class DemandForecastService
{
    public function __construct(
        private readonly AnalyticsClient $analyticsClient,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Runs the demand forecasting pipeline for a (product_id, branch_id) pair,
     * persists ModelRun and DemandForecast records, and returns the complete payload.
     *
     * @return array<string, mixed>
     */
    public function runForecast(
        int $productId,
        int $branchId,
        ?int $horizonDays = null,
        ?string $preferredModel = null,
    ): array {
        $horizon = $horizonDays ?? (int) $this->settings->get('forecast_horizon_days', 14);
        $horizon = max(1, min(60, $horizon));

        $serviceLevel = (float) $this->settings->get('replenishment_service_level', 0.95);
        $zValue = $this->zScoreForServiceLevel($serviceLevel);

        $history = $this->extractDailyHistory($productId, $branchId, 180);

        $response = $this->analyticsClient->call('/forecast', [
            'product_id' => $productId,
            'branch_id' => $branchId,
            'horizon' => $horizon,
            'history' => $history,
            'service_level_z' => $zValue,
            'preferred_model' => $preferredModel,
        ]);

        $metrics = $response['metrics'] ?? ['mae' => 0.0, 'rmse' => 0.0, 'smape' => 0.0, 'mape' => 0.0];
        $modelUsed = (string) ($response['model_used'] ?? 'seasonal_naive');
        $baselineModel = (string) ($response['baseline_model'] ?? 'seasonal_naive');
        $baselineSmape = (float) ($response['baseline_smape'] ?? ($metrics['smape'] ?? 0.0));
        $beatsBaseline = (bool) ($response['beats_baseline'] ?? false);
        $confidenceScore = (float) ($response['confidence_score'] ?? max(5.0, 100.0 - min(95.0, ((float) ($metrics['smape'] ?? 0.0)) / 2.0)));
        $sigmaD = (float) ($response['sigma_d'] ?? ($metrics['rmse'] ?? 0.0));
        $trainEndDate = $response['train_end_date'] ?? ($history !== [] ? end($history)['date'] : now()->toDateString());
        $folds = (int) ($response['folds'] ?? 1);
        $candidates = $response['candidates'] ?? [];

        return DB::transaction(function () use (
            $productId,
            $branchId,
            $horizon,
            $zValue,
            $history,
            $response,
            $metrics,
            $modelUsed,
            $baselineModel,
            $baselineSmape,
            $beatsBaseline,
            $confidenceScore,
            $sigmaD,
            $trainEndDate,
            $folds,
            $candidates
        ): array {
            // Unmark previous selected model_runs for this product-branch
            ModelRun::query()
                ->where('product_id', $productId)
                ->where('branch_id', $branchId)
                ->where('selected', true)
                ->update(['selected' => false]);

            // Persist candidate model_runs rows (non-selected candidates + selected model run)
            $selectedRun = null;
            if (is_array($candidates) && count($candidates) > 0) {
                foreach ($candidates as $cand) {
                    $isSelected = (string) ($cand['model'] ?? '') === $modelUsed;
                    $run = ModelRun::create([
                        'product_id' => $productId,
                        'branch_id' => $branchId,
                        'model_used' => (string) ($cand['model'] ?? $modelUsed),
                        'trained_at' => now(),
                        'train_end_date' => $trainEndDate,
                        'folds' => $folds,
                        'mae' => (float) ($cand['mae'] ?? $metrics['mae'] ?? 0.0),
                        'rmse' => (float) ($cand['rmse'] ?? $metrics['rmse'] ?? 0.0),
                        'smape' => (float) ($cand['smape'] ?? $metrics['smape'] ?? 0.0),
                        'baseline_model' => $baselineModel,
                        'baseline_smape' => $baselineSmape,
                        'beats_baseline' => (bool) ($cand['beats_baseline'] ?? false),
                        'selected' => $isSelected,
                        'params' => array_merge(
                            (array) ($cand['params'] ?? []),
                            [
                                'sigma_d' => $sigmaD,
                                'service_level_z' => $zValue,
                                'mape' => (float) ($cand['mape'] ?? $metrics['mape'] ?? 0.0),
                                'horizon' => $horizon,
                            ]
                        ),
                    ]);
                    if ($isSelected) {
                        $selectedRun = $run;
                    }
                }
            }

            if ($selectedRun === null) {
                $selectedRun = ModelRun::create([
                    'product_id' => $productId,
                    'branch_id' => $branchId,
                    'model_used' => $modelUsed,
                    'trained_at' => now(),
                    'train_end_date' => $trainEndDate,
                    'folds' => $folds,
                    'mae' => (float) ($metrics['mae'] ?? 0.0),
                    'rmse' => (float) ($metrics['rmse'] ?? 0.0),
                    'smape' => (float) ($metrics['smape'] ?? 0.0),
                    'baseline_model' => $baselineModel,
                    'baseline_smape' => $baselineSmape,
                    'beats_baseline' => $beatsBaseline,
                    'selected' => true,
                    'params' => array_merge(
                        (array) ($response['params'] ?? []),
                        [
                            'sigma_d' => $sigmaD,
                            'service_level_z' => $zValue,
                            'mape' => (float) ($metrics['mape'] ?? 0.0),
                            'horizon' => $horizon,
                        ]
                    ),
                ]);
            }

            // Replace existing future forecast rows for this product-branch
            DemandForecast::query()
                ->where('product_id', $productId)
                ->where('branch_id', $branchId)
                ->delete();

            $storedForecasts = [];
            $forecastList = (array) ($response['forecast'] ?? []);
            foreach ($forecastList as $idx => $fRow) {
                $step = (int) ($fRow['step'] ?? ($idx + 1));
                $pred = (float) ($fRow['predicted_demand'] ?? 0.0);
                $halfWidth = $zValue * $sigmaD * sqrt(max(1, $step));
                $low = isset($fRow['lower_bound']) ? (float) $fRow['lower_bound'] : max(0.0, round($pred - $halfWidth, 2));
                $high = isset($fRow['upper_bound']) ? (float) $fRow['upper_bound'] : round($pred + $halfWidth, 2);
                $rowConfidence = (float) ($fRow['confidence_score'] ?? $confidenceScore);

                $df = DemandForecast::create([
                    'product_id' => $productId,
                    'branch_id' => $branchId,
                    'forecast_date' => (string) $fRow['date'],
                    'predicted_demand' => $pred,
                    'model_used' => $modelUsed,
                    'model_run_id' => $selectedRun->id,
                    'confidence_score' => $rowConfidence,
                    'generated_at' => now(),
                ]);

                $storedForecasts[] = [
                    'id' => $df->id,
                    'date' => (string) $fRow['date'],
                    'weekday' => CarbonImmutable::parse((string) $fRow['date'])->format('D'),
                    'step' => $step,
                    'predicted_demand' => round($pred, 2),
                    'lower_bound' => round($low, 2),
                    'upper_bound' => round($high, 2),
                    'model_used' => $modelUsed,
                    'model_run_id' => $selectedRun->id,
                    'confidence_score' => round($rowConfidence, 2),
                ];
            }

            return [
                'product' => Product::query()->find($productId, ['id', 'name', 'sku', 'barcode', 'selling_price']),
                'branch' => Branch::query()->find($branchId, ['id', 'name']),
                'model_run' => $selectedRun,
                'model_used' => $modelUsed,
                'confidence_score' => round($confidenceScore, 2),
                'sigma_d' => round($sigmaD, 4),
                'service_level_z' => $zValue,
                'horizon' => $horizon,
                'metrics' => $metrics,
                'baseline_model' => $baselineModel,
                'baseline_smape' => $baselineSmape,
                'beats_baseline' => $beatsBaseline,
                'candidates' => $candidates,
                'weekday_indices' => $response['weekday_indices'] ?? [],
                'pipeline_summary' => $response['pipeline_summary'] ?? [],
                'history' => array_slice($history, -42), // recent 6 weeks for charting
                'forecasts' => $storedForecasts,
            ];
        });
    }

    /**
     * Extracts product-branch daily sales series for the forecasting pipeline.
     *
     * @return list<array<string, mixed>>
     */
    public function extractDailyHistory(int $productId, int $branchId, int $windowDays = 180): array
    {
        $maxSaleDate = DB::table('sales')
            ->where('branch_id', $branchId)
            ->max('sale_date');

        $endDate = $maxSaleDate
            ? CarbonImmutable::parse($maxSaleDate)->startOfDay()
            : CarbonImmutable::today();
        $startDate = $endDate->subDays(max(14, $windowDays) - 1);

        $rows = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', 'completed')
            ->where('sale_items.product_id', $productId)
            ->where('sales.branch_id', $branchId)
            ->whereBetween('sales.sale_date', [
                $startDate->startOfDay()->toDateTimeString(),
                $endDate->endOfDay()->toDateTimeString(),
            ])
            ->selectRaw('
                DATE(sales.sale_date) as sale_day,
                SUM(sale_items.quantity) as total_quantity,
                SUM(sale_items.discount) as total_discount
            ')
            ->groupBy(DB::raw('DATE(sales.sale_date)'))
            ->orderBy('sale_day')
            ->get()
            ->keyBy('sale_day');

        $ledgerClosing = DB::table('inventory_transactions')
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->whereBetween('created_at', [
                $startDate->startOfDay()->toDateTimeString(),
                $endDate->endOfDay()->toDateTimeString(),
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['quantity_after', 'created_at']);

        $eodStock = [];
        foreach ($ledgerClosing as $tx) {
            $dayStr = CarbonImmutable::parse($tx->created_at)->toDateString();
            $eodStock[$dayStr] = (int) $tx->quantity_after;
        }

        $series = [];
        for ($d = 0; $d < $windowDays; $d++) {
            $dateObj = $startDate->addDays($d);
            $dateStr = $dateObj->toDateString();
            $row = $rows[$dateStr] ?? null;
            $qty = $row ? (float) $row->total_quantity : 0.0;
            $disc = $row ? (float) $row->total_discount : 0.0;
            $closing = $eodStock[$dateStr] ?? null;
            $stockout = $closing !== null && $closing <= 0 && $qty == 0.0;

            $series[] = [
                'product_id' => $productId,
                'branch_id' => $branchId,
                'date' => $dateStr,
                'quantity' => $qty,
                'promotion' => $disc > 0,
                'stockout' => $stockout,
                'closing_stock' => $closing,
            ];
        }

        return $series;
    }

    private function zScoreForServiceLevel(float $serviceLevel): float
    {
        if ($serviceLevel >= 0.99) {
            return 2.326;
        }
        if ($serviceLevel >= 0.975) {
            return 1.960;
        }
        if ($serviceLevel >= 0.95) {
            return 1.645;
        }
        if ($serviceLevel >= 0.90) {
            return 1.282;
        }
        return 1.000;
    }

    /**
     * Automatically ensures all active products have up-to-date demand forecasts
     * for the given branch and returns a structured summary of upcoming required units.
     *
     * @return list<array<string, mixed>>
     */
    public function ensureBranchForecasts(int $branchId, ?int $horizonDays = null): array
    {
        $horizon = $horizonDays ?? (int) $this->settings->get('forecast_horizon_days', 14);
        $horizon = max(1, min(60, $horizon));

        $products = Product::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'barcode', 'selling_price']);

        $existingByProduct = DemandForecast::query()
            ->where('branch_id', $branchId)
            ->orderBy('forecast_date')
            ->get()
            ->groupBy('product_id');

        $summaryRows = [];

        foreach ($products as $product) {
            $productForecasts = $existingByProduct->get($product->id);

            if ($productForecasts === null || $productForecasts->count() < $horizon) {
                $this->runForecast($product->id, $branchId, $horizon);
                $productForecasts = DemandForecast::query()
                    ->where('product_id', $product->id)
                    ->where('branch_id', $branchId)
                    ->orderBy('forecast_date')
                    ->get();
            }

            $dailySchedule = [];
            $totalUnits = 0;
            $next7Units = 0;

            foreach ($productForecasts->values() as $idx => $fRow) {
                $units = (int) ceil((float) $fRow->predicted_demand);
                $totalUnits += $units;
                if ($idx < 7) {
                    $next7Units += $units;
                }
                $dateStr = $fRow->forecast_date instanceof \DateTimeInterface
                    ? $fRow->forecast_date->format('Y-m-d')
                    : (string) $fRow->forecast_date;

                $dailySchedule[] = [
                    'date' => $dateStr,
                    'day' => CarbonImmutable::parse($dateStr)->format('D, M j'),
                    'units' => $units,
                ];
            }

            $daysCount = max(1, count($dailySchedule));
            $summaryRows[] = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'sku' => $product->sku,
                'next_day_units' => $dailySchedule[0]['units'] ?? 0,
                'next_7_days_units' => $next7Units,
                'total_horizon_units' => $totalUnits,
                'avg_daily_units' => (int) ceil($totalUnits / $daysCount),
                'horizon_days' => $daysCount,
                'daily_schedule' => $dailySchedule,
            ];
        }

        return $summaryRows;
    }
}
