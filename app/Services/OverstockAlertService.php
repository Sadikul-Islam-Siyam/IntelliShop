<?php

namespace App\Services;

use App\Contracts\AnalyticsClient;
use App\Models\AiRecommendation;
use App\Models\Branch;
use App\Models\DemandForecast;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\SystemSetting;
use App\Services\Analytics\DemandForecastService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * OverstockAlertService (Sub-Features #50 & #51)
 *
 * Implements:
 * - #50 Slow-Moving Detection Rules:
 *   Flags products whose sell-through rate over the rolling window (default 30 days)
 *   is below the slow-moving threshold (default 0.20).
 * - #51 Overstock Alerts:
 *   Flags stock whose on-hand quantity exceeds cumulative forecast demand over the
 *   cover horizon (default 14 days), persisting alerts in `ai_recommendations`.
 */
class OverstockAlertService
{
    public const DEFAULT_SLOW_MOVING_THRESHOLD = 0.20;
    public const DEFAULT_WINDOW_DAYS = 30;
    public const DEFAULT_HORIZON_DAYS = 14;

    public function __construct(
        protected AnalyticsClient $analyticsClient,
        protected DemandForecastService $forecastService
    ) {}

    /**
     * Run slow-moving and overstock evaluation for a single product at a branch.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function runForProduct(int $productId, int $branchId, array $overrides = []): array
    {
        $product = Product::query()->findOrFail($productId);
        $branch = Branch::query()->findOrFail($branchId);

        // 1. Available on-hand stock
        if (array_key_exists('available_stock', $overrides) && $overrides['available_stock'] !== null) {
            $availableStock = (float) $overrides['available_stock'];
        } elseif (array_key_exists('stock', $overrides) && $overrides['stock'] !== null) {
            $availableStock = (float) $overrides['stock'];
        } else {
            $inventory = Inventory::query()
                ->where('product_id', $productId)
                ->where('branch_id', $branchId)
                ->first();
            $availableStock = $inventory ? (float) $inventory->quantity_available : 0.0;
        }

        // 2. Settings: window_days, slow_moving_threshold, horizon_days
        $windowDays = array_key_exists('window_days', $overrides) && $overrides['window_days'] !== null
            ? max(1, (int) $overrides['window_days'])
            : $this->resolveWindowDays();

        $slowMovingThreshold = array_key_exists('slow_moving_threshold', $overrides) && $overrides['slow_moving_threshold'] !== null
            ? (float) $overrides['slow_moving_threshold']
            : $this->resolveSlowMovingThreshold();

        $horizonDays = array_key_exists('horizon_days', $overrides) && $overrides['horizon_days'] !== null
            ? max(1, (int) $overrides['horizon_days'])
            : $this->resolveHorizonDays();

        // 3. Total units sold over the rolling window (default 30 days)
        if (array_key_exists('total_sold', $overrides) && $overrides['total_sold'] !== null) {
            $totalSold = (float) $overrides['total_sold'];
        } elseif (array_key_exists('sales_history', $overrides) && is_array($overrides['sales_history'])) {
            $totalSold = $this->sumSalesHistory($overrides['sales_history']);
        } else {
            $totalSold = $this->resolveTotalSoldInWindow($productId, $branchId, $windowDays);
        }

        // 4. Cumulative forecast demand over cover horizon (default 14 days)
        if (array_key_exists('forecast_demand_horizon', $overrides) && $overrides['forecast_demand_horizon'] !== null) {
            $forecastDemandHorizon = (float) $overrides['forecast_demand_horizon'];
        } elseif (array_key_exists('forecasts', $overrides) && is_array($overrides['forecasts'])) {
            $forecastDemandHorizon = $this->sumForecastHorizon($overrides['forecasts'], $horizonDays);
        } else {
            $forecastRows = $this->getOrGenerateForecasts($productId, $branchId, $horizonDays);
            $forecastDemandHorizon = round((float) $forecastRows->sum('predicted_demand'), 2);
        }

        // 5. Call Python analytics service POST /overstock/run with PHP fallback (Rule #6)
        $payload = [
            'product_id' => $productId,
            'branch_id' => $branchId,
            'available_stock' => $availableStock,
            'total_sold' => $totalSold,
            'window_days' => $windowDays,
            'slow_moving_threshold' => $slowMovingThreshold,
            'forecast_demand_horizon' => $forecastDemandHorizon,
            'horizon_days' => $horizonDays,
        ];

        try {
            $serviceResult = $this->analyticsClient->call('/overstock/run', $payload);
        } catch (\Throwable $e) {
            Log::warning('Analytics /overstock/run failed, using PHP mathematical fallback: ' . $e->getMessage());
            $serviceResult = $this->evaluateFallback($payload);
        }

        $fallbackResult = $this->evaluateFallback($payload);
        $evaluation = array_merge($fallbackResult, $serviceResult);

        // 6. Persist or dismiss alert in ai_recommendations (#51)
        $recommendation = $this->syncOverstockRecommendation($product, $branch, $evaluation);

        return array_merge($evaluation, [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'cost_price' => (float) ($product->cost_price ?? 0.0),
                'selling_price' => (float) ($product->selling_price ?? 0.0),
            ],
            'branch' => [
                'id' => $branch->id,
                'name' => $branch->name,
                'code' => $branch->code,
            ],
            'recommendation' => $recommendation ? [
                'id' => $recommendation->id,
                'type' => $recommendation->type,
                'priority' => $recommendation->priority,
                'priority_score' => (float) $recommendation->priority_score,
                'text' => $recommendation->recommendation_text,
                'status' => $recommendation->status,
                'factors' => $recommendation->factors,
                'generated_at' => $recommendation->generated_at?->toIso8601String(),
            ] : null,
        ]);
    }

    /**
     * Pure PHP mathematical implementation of Slow-Moving (#50) and Overstock (#51) detection.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function evaluateFallback(array $payload): array
    {
        $productId = (int) ($payload['product_id'] ?? 0);
        $branchId = (int) ($payload['branch_id'] ?? 0);
        $availableStock = max(0.0, (float) ($payload['available_stock'] ?? $payload['stock'] ?? 0.0));
        $windowDays = max(1, (int) ($payload['window_days'] ?? self::DEFAULT_WINDOW_DAYS));
        $slowThreshold = max(0.0, min(1.0, (float) ($payload['slow_moving_threshold'] ?? self::DEFAULT_SLOW_MOVING_THRESHOLD)));
        $horizonDays = max(1, (int) ($payload['horizon_days'] ?? self::DEFAULT_HORIZON_DAYS));

        if (isset($payload['total_sold']) && $payload['total_sold'] !== null) {
            $totalSold = max(0.0, (float) $payload['total_sold']);
        } elseif (isset($payload['sales_history']) && is_array($payload['sales_history'])) {
            $totalSold = $this->sumSalesHistory($payload['sales_history']);
        } else {
            $totalSold = 0.0;
        }

        // 1. Sell-through rate = total_sold / (total_sold + available_stock)
        $denominator = $totalSold + $availableStock;
        $sellThroughRate = $denominator > 0 ? ($totalSold / $denominator) : 0.0;
        $avgDailySales = $totalSold / (float) $windowDays;

        // Flag slow-moving if stock > 0 and sell_through_rate < threshold
        $isSlowMoving = $availableStock > 0 && $sellThroughRate < $slowThreshold;

        // 2. Overstock check against cumulative forecast demand over cover horizon
        if (isset($payload['forecast_demand_horizon']) && $payload['forecast_demand_horizon'] !== null) {
            $forecastHorizonDemand = max(0.0, (float) $payload['forecast_demand_horizon']);
        } elseif (isset($payload['forecasts']) && is_array($payload['forecasts'])) {
            $forecastHorizonDemand = $this->sumForecastHorizon($payload['forecasts'], $horizonDays);
        } else {
            $forecastHorizonDemand = round($avgDailySales * $horizonDays, 2);
        }

        $isOverstock = $availableStock > 0 && $availableStock > $forecastHorizonDemand;
        $overstockUnits = max(0.0, $availableStock - $forecastHorizonDemand);

        if ($forecastHorizonDemand > 0) {
            $overstockRatio = $availableStock / $forecastHorizonDemand;
            $avgForecastDaily = $forecastHorizonDemand / (float) $horizonDays;
            $daysOfCover = $avgForecastDaily > 0 ? round($availableStock / $avgForecastDaily, 1) : null;
        } else {
            $overstockRatio = $availableStock > 0 ? $availableStock : 0.0;
            $daysOfCover = $avgDailySales > 0 ? round($availableStock / $avgDailySales, 1) : null;
        }

        if ($isOverstock && $isSlowMoving) {
            $severity = 'high';
        } elseif ($isOverstock && $overstockRatio >= 2.0) {
            $severity = 'high';
        } elseif ($isOverstock || $isSlowMoving) {
            $severity = 'medium';
        } else {
            $severity = 'low';
        }

        return [
            'product_id' => $productId,
            'branch_id' => $branchId,
            'available_stock' => round($availableStock, 2),
            'total_sold' => round($totalSold, 2),
            'window_days' => $windowDays,
            'avg_daily_sales' => round($avgDailySales, 2),
            'sell_through_rate' => round($sellThroughRate, 4),
            'slow_moving_threshold' => round($slowThreshold, 4),
            'is_slow_moving' => $isSlowMoving,
            'horizon_days' => $horizonDays,
            'forecast_demand_horizon' => round($forecastHorizonDemand, 2),
            'is_overstock' => $isOverstock,
            'overstock_units' => round($overstockUnits, 2),
            'overstock_ratio' => round($overstockRatio, 2),
            'days_of_cover' => $daysOfCover,
            'severity' => $severity,
            'requires_alert' => ($isOverstock || $isSlowMoving),
        ];
    }

    /**
     * Run slow-moving and overstock evaluation for all active products at a branch.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<int, array<string, mixed>>
     */
    public function runAllForBranch(int $branchId, array $overrides = []): array
    {
        $products = Product::query()->where('status', 'active')->orderBy('name')->get();
        $results = [];

        foreach ($products as $product) {
            try {
                $results[] = $this->runForProduct($product->id, $branchId, $overrides);
            } catch (\Throwable $e) {
                Log::warning("Overstock evaluation failed for product {$product->id} at branch {$branchId}: " . $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * Ensure overstock & slow-moving alerts are evaluated for a branch and return all open alerts.
     *
     * @return Collection<int, AiRecommendation>
     */
    public function getAlertsForBranch(?int $branchId = null, bool $autoRefresh = true): Collection
    {
        if ($autoRefresh && $branchId !== null) {
            $hasRecent = AiRecommendation::query()
                ->whereIn('type', ['OVERSTOCK_ALERT', 'SLOW_MOVING_ALERT'])
                ->where('generated_at', '>=', now()->startOfDay())
                ->exists();

            if (!$hasRecent) {
                $this->runAllForBranch($branchId);
            }
        }

        $query = AiRecommendation::query()
            ->whereIn('type', ['OVERSTOCK_ALERT', 'SLOW_MOVING_ALERT'])
            ->where('status', 'open')
            ->orderByDesc('priority_score')
            ->orderByDesc('generated_at');

        $alerts = $query->get();

        if ($branchId !== null) {
            $alerts = $alerts->filter(function (AiRecommendation $rec) use ($branchId) {
                $factors = is_array($rec->factors) ? $rec->factors : [];
                return !isset($factors['branch_id']) || (int) $factors['branch_id'] === $branchId;
            })->values();
        }

        return $alerts;
    }

    /**
     * Resolve total units sold in the rolling window (default 30 days) for a product at a branch.
     */
    public function resolveTotalSoldInWindow(int $productId, int $branchId, int $windowDays = 30): float
    {
        $cutoffDate = Carbon::today()->subDays(max(1, $windowDays))->startOfDay();

        $total = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sale_items.product_id', $productId)
            ->where('sales.branch_id', $branchId)
            ->where('sales.status', 'completed')
            ->where('sales.sale_date', '>=', $cutoffDate)
            ->sum('sale_items.quantity');

        return max(0.0, (float) $total);
    }

    /**
     * Retrieve or generate demand forecasts for the product and branch over the cover horizon.
     *
     * @return Collection<int, DemandForecast>
     */
    protected function getOrGenerateForecasts(int $productId, int $branchId, int $horizon): Collection
    {
        $today = now()->toDateString();
        $existing = DemandForecast::query()
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->where('forecast_date', '>=', $today)
            ->orderBy('forecast_date')
            ->take($horizon)
            ->get();

        if ($existing->isNotEmpty()) {
            return $existing;
        }

        try {
            $this->forecastService->runForecast($productId, $branchId, $horizon);
        } catch (\Throwable $e) {
            Log::warning("Forecast generation inside OverstockAlertService failed: " . $e->getMessage());
        }

        return DemandForecast::query()
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->where('forecast_date', '>=', $today)
            ->orderBy('forecast_date')
            ->take($horizon)
            ->get();
    }

    /**
     * Synchronize Overstock & Slow-Moving alert row in ai_recommendations (#51).
     *
     * @param  array<string, mixed>  $evaluation
     */
    protected function syncOverstockRecommendation(
        Product $product,
        Branch $branch,
        array $evaluation
    ): ?AiRecommendation {
        $isOverstock = (bool) ($evaluation['is_overstock'] ?? false);
        $isSlowMoving = (bool) ($evaluation['is_slow_moving'] ?? false);

        if (!$isOverstock && !$isSlowMoving) {
            AiRecommendation::query()
                ->whereIn('type', ['OVERSTOCK_ALERT', 'SLOW_MOVING_ALERT'])
                ->where('related_entity_type', 'Product')
                ->where('related_entity_id', $product->id)
                ->where('status', 'open')
                ->get()
                ->each(function (AiRecommendation $rec) use ($branch) {
                    $factors = is_array($rec->factors) ? $rec->factors : [];
                    if (!isset($factors['branch_id']) || (int) $factors['branch_id'] === $branch->id) {
                        $rec->update(['status' => 'dismissed']);
                    }
                });

            return null;
        }

        $availableStock = (float) ($evaluation['available_stock'] ?? 0.0);
        $forecastDemand = (float) ($evaluation['forecast_demand_horizon'] ?? 0.0);
        $overstockUnits = (float) ($evaluation['overstock_units'] ?? 0.0);
        $sellThrough = (float) ($evaluation['sell_through_rate'] ?? 0.0);
        $horizonDays = (int) ($evaluation['horizon_days'] ?? 14);
        $windowDays = (int) ($evaluation['window_days'] ?? 30);
        $severity = (string) ($evaluation['severity'] ?? 'medium');

        if ($isOverstock && $isSlowMoving) {
            $subtype = 'OVERSTOCK_AND_SLOW_MOVING';
            $text = sprintf(
                'Overstock & Slow-Moving Alert: %s at %s has %s units on hand vs %s units forecast demand over %d days (+%s surplus units), with a %s%% sell-through rate over %d days.',
                $product->name,
                $branch->name,
                number_format($availableStock, 0),
                number_format($forecastDemand, 1),
                $horizonDays,
                number_format($overstockUnits, 1),
                number_format($sellThrough * 100, 1),
                $windowDays
            );
        } elseif ($isOverstock) {
            $subtype = 'OVERSTOCK';
            $text = sprintf(
                'Overstock Alert: %s at %s has %s units on hand exceeding %d-day forecast demand of %s units by %s units.',
                $product->name,
                $branch->name,
                number_format($availableStock, 0),
                $horizonDays,
                number_format($forecastDemand, 1),
                number_format($overstockUnits, 1)
            );
        } else {
            $subtype = 'SLOW_MOVING';
            $text = sprintf(
                'Slow-Moving Alert: %s at %s has a %s%% sell-through rate over the last %d days (below %s%% threshold) with %s units on hand.',
                $product->name,
                $branch->name,
                number_format($sellThrough * 100, 1),
                $windowDays,
                number_format(((float) ($evaluation['slow_moving_threshold'] ?? 0.20)) * 100, 0),
                number_format($availableStock, 0)
            );
        }

        $priorityScore = match ($severity) {
            'high' => 0.90,
            'medium' => 0.70,
            default => 0.50,
        };

        $existing = AiRecommendation::query()
            ->where('type', 'OVERSTOCK_ALERT')
            ->where('related_entity_type', 'Product')
            ->where('related_entity_id', $product->id)
            ->get()
            ->first(function (AiRecommendation $rec) use ($branch) {
                $factors = is_array($rec->factors) ? $rec->factors : [];
                return !isset($factors['branch_id']) || (int) $factors['branch_id'] === $branch->id;
            });

        $factorsPayload = [
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'branch_id' => $branch->id,
            'branch_name' => $branch->name,
            'alert_subtype' => $subtype,
            'available_stock' => $availableStock,
            'total_sold' => (float) ($evaluation['total_sold'] ?? 0.0),
            'window_days' => $windowDays,
            'sell_through_rate' => $sellThrough,
            'slow_moving_threshold' => (float) ($evaluation['slow_moving_threshold'] ?? 0.20),
            'is_slow_moving' => $isSlowMoving,
            'horizon_days' => $horizonDays,
            'forecast_demand_horizon' => $forecastDemand,
            'is_overstock' => $isOverstock,
            'overstock_units' => $overstockUnits,
            'overstock_ratio' => (float) ($evaluation['overstock_ratio'] ?? 0.0),
            'days_of_cover' => $evaluation['days_of_cover'] ?? null,
            'severity' => $severity,
        ];

        if ($existing) {
            $existing->update([
                'recommendation_text' => $text,
                'priority' => $severity,
                'priority_score' => $priorityScore,
                'status' => 'open',
                'factors' => $factorsPayload,
                'generated_at' => now(),
            ]);

            return $existing->refresh();
        }

        return AiRecommendation::query()->create([
            'type' => 'OVERSTOCK_ALERT',
            'related_entity_type' => 'Product',
            'related_entity_id' => $product->id,
            'recommendation_text' => $text,
            'priority' => $severity,
            'priority_score' => $priorityScore,
            'status' => 'open',
            'factors' => $factorsPayload,
            'generated_at' => now(),
        ]);
    }

    /**
     * Resolve slow-moving threshold from system_settings (default 0.20).
     */
    public function resolveSlowMovingThreshold(): float
    {
        $val = SystemSetting::query()
            ->where('setting_key', 'slow_moving_sell_through_threshold')
            ->value('setting_value');

        if ($val !== null && is_numeric($val)) {
            $num = (float) $val;
            return $num > 1.0 ? ($num / 100.0) : max(0.0, $num);
        }

        return self::DEFAULT_SLOW_MOVING_THRESHOLD;
    }

    /**
     * Resolve slow-moving window days from system_settings (default 30).
     */
    public function resolveWindowDays(): int
    {
        $val = SystemSetting::query()
            ->where('setting_key', 'slow_moving_window_days')
            ->value('setting_value');

        return $val !== null && (int) $val > 0 ? (int) $val : self::DEFAULT_WINDOW_DAYS;
    }

    /**
     * Resolve forecast cover horizon days from system_settings (default 14).
     */
    public function resolveHorizonDays(): int
    {
        $val = SystemSetting::query()
            ->where('setting_key', 'forecast_horizon_days')
            ->value('setting_value');

        return $val !== null && (int) $val > 0 ? (int) $val : self::DEFAULT_HORIZON_DAYS;
    }

    /**
     * Sum sales history array of numbers or dicts.
     *
     * @param  array<int, mixed>  $history
     */
    protected function sumSalesHistory(array $history): float
    {
        $total = 0.0;
        foreach ($history as $item) {
            if (is_array($item)) {
                $total += max(0.0, (float) ($item['quantity'] ?? $item['qty'] ?? 0.0));
            } elseif (is_numeric($item)) {
                $total += max(0.0, (float) $item);
            }
        }

        return $total;
    }

    /**
     * Sum forecast horizon array of numbers or dicts.
     *
     * @param  array<int, mixed>  $forecasts
     */
    protected function sumForecastHorizon(array $forecasts, int $horizonDays): float
    {
        $slice = array_slice($forecasts, 0, max(1, $horizonDays));
        $total = 0.0;
        foreach ($slice as $f) {
            if (is_array($f)) {
                $total += max(0.0, (float) ($f['predicted_demand'] ?? $f['demand'] ?? 0.0));
            } elseif (is_numeric($f)) {
                $total += max(0.0, (float) $f);
            }
        }

        return $total;
    }
}

