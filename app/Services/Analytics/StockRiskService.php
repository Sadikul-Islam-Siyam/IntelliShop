<?php

namespace App\Services\Analytics;

use App\Contracts\AnalyticsClient;
use App\Models\AiRecommendation;
use App\Models\Branch;
use App\Models\DemandForecast;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\PurchaseOrderItem;
use App\Models\StockPrediction;
use App\Models\SystemSetting;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class StockRiskService
{
    public function __construct(
        protected AnalyticsClient $analyticsClient,
        protected DemandForecastService $forecastService
    ) {}

    /**
     * Runs the days-of-cover projection and stock-out risk classification
     * for a given product at a specific branch.
     *
     * @return array<string, mixed>
     */
    public function runStockRisk(int $productId, int $branchId, ?int $horizon = null): array
    {
        $product = Product::query()->findOrFail($productId);
        $branch = Branch::query()->findOrFail($branchId);

        // 1. Available on-hand inventory
        $inventory = Inventory::query()
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->first();

        $availableStock = $inventory
            ? (float) $inventory->quantity_available
            : 0.0;

        // 2. Lead time L from preferred/active product supplier or system settings
        $leadTime = $this->resolveLeadTime($productId);

        // 3. Review period R from system settings (default: 7 days)
        $reviewPeriod = (int) (SystemSetting::query()
            ->where('setting_key', 'replenishment_review_period_days')
            ->value('setting_value') ?? 7);

        // 4. Forecast horizon H (default: 14 days)
        $horizonDays = $horizon ?? (int) (SystemSetting::query()
            ->where('setting_key', 'forecast_horizon_days')
            ->value('setting_value') ?? 14);

        // 5. Retrieve or generate demand forecasts
        $forecastRows = $this->getOrGenerateForecasts($productId, $branchId, $horizonDays);

        $forecastPayload = $forecastRows->map(function ($row) {
            $date = $row->forecast_date instanceof Carbon
                ? $row->forecast_date->format('Y-m-d')
                : Carbon::parse($row->forecast_date)->format('Y-m-d');

            return [
                'date' => $date,
                'predicted_demand' => (float) $row->predicted_demand,
            ];
        })->values()->all();

        // 6. Retrieve scheduled receipts (open PO items arriving for this product & branch)
        $scheduledReceipts = $this->resolveScheduledReceipts($productId, $branchId, $leadTime);

        // 7. Invoke analytics service /projection endpoint
        $projectionPayload = [
            'available_stock' => $availableStock,
            'forecasts' => $forecastPayload,
            'lead_time_days' => $leadTime,
            'review_period_days' => $reviewPeriod,
            'scheduled_receipts' => $scheduledReceipts,
            'start_date' => now()->toDateString(),
        ];

        $projectionResult = $this->analyticsClient->call('/projection', $projectionPayload);

        // 8. Evaluate and enforce mathematical risk classification rule
        // No ESD in horizon -> LOW
        // ESD <= today + L  -> HIGH (cannot replenish in time)
        // ESD <= today + L + R -> MEDIUM (order this review cycle)
        // Otherwise -> LOW
        $esd = $projectionResult['estimated_stockout_date'] ?? $projectionResult['esd'] ?? null;
        $riskLevel = $this->classifyRisk($esd, $leadTime, $reviewPeriod);

        $totalDemand = (float) ($projectionResult['total_predicted_demand']
            ?? $forecastRows->sum('predicted_demand'));

        // 9. Persist to stock_predictions table
        $prediction = StockPrediction::query()->updateOrCreate(
            [
                'product_id' => $productId,
                'branch_id' => $branchId,
            ],
            [
                'predicted_stockout_date' => $esd,
                'current_stock' => $availableStock,
                'predicted_demand' => $totalDemand,
                'recommended_reorder_qty' => 0, // Calculated downstream in #45
                'risk_level' => $riskLevel,
                'safety_stock' => null, // Calculated downstream in #45
                'reorder_point' => null, // Calculated downstream in #45
                'lead_time_days' => $leadTime,
                'generated_at' => now(),
            ]
        );

        // 10. Surface recommendation in ai_recommendations table
        $recommendation = $this->syncAiRecommendation(
            $product,
            $branch,
            $riskLevel,
            $esd,
            $availableStock,
            $leadTime,
            $reviewPeriod
        );

        return [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
            ],
            'branch' => [
                'id' => $branch->id,
                'name' => $branch->name,
                'code' => $branch->code,
            ],
            'prediction' => [
                'id' => $prediction->id,
                'product_id' => $prediction->product_id,
                'branch_id' => $prediction->branch_id,
                'current_stock' => (float) $prediction->current_stock,
                'predicted_demand' => (float) $prediction->predicted_demand,
                'predicted_stockout_date' => $prediction->predicted_stockout_date?->format('Y-m-d'),
                'risk_level' => $prediction->risk_level,
                'lead_time_days' => $prediction->lead_time_days,
                'days_of_cover' => $projectionResult['days_of_cover'] ?? null,
                'days_to_stockout' => $projectionResult['days_to_stockout'] ?? null,
                'generated_at' => $prediction->generated_at?->toIso8601String(),
            ],
            'projection' => $projectionResult['projected_stock'] ?? [],
            'recommendation' => $recommendation ? [
                'id' => $recommendation->id,
                'type' => $recommendation->type,
                'priority' => $recommendation->priority,
                'text' => $recommendation->recommendation_text,
                'status' => $recommendation->status,
            ] : null,
        ];
    }

    /**
     * Ensures all active catalog products have up-to-date predictions for a branch.
     * Automatically runs upon user login or page visit so data is never empty or stale.
     *
     * @return array<int, array<string, mixed>>
     */
    public function ensureBranchPredictions(int $branchId, bool $force = false, ?int $horizon = null): array
    {
        $products = Product::query()->where('status', 'active')->get();
        $results = [];

        foreach ($products as $product) {
            $existing = StockPrediction::query()
                ->where('product_id', $product->id)
                ->where('branch_id', $branchId)
                ->first();

            $needsRun = $force
                || !$existing
                || !$existing->generated_at
                || $existing->generated_at->isBefore(now()->startOfDay());

            if ($needsRun) {
                try {
                    $results[] = $this->runStockRisk($product->id, $branchId, $horizon);
                } catch (\Throwable $e) {
                    Log::warning("Auto-run stock risk failed for product {$product->id}: " . $e->getMessage());
                }
            }
        }

        return $results;
    }

    /**
     * Runs stock risk assessment for all active catalog products at a branch.
     *
     * @return array<int, array<string, mixed>>
     */
    public function runAllForBranch(int $branchId, ?int $horizon = null): array
    {
        $products = Product::query()->where('status', 'active')->get();
        $results = [];

        foreach ($products as $product) {
            try {
                $results[] = $this->runStockRisk($product->id, $branchId, $horizon);
            } catch (\Throwable $e) {
                Log::warning("Failed stock risk run for product {$product->id}: " . $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * Resolves the supplier lead time in days.
     */
    protected function resolveLeadTime(int $productId): int
    {
        $preferred = ProductSupplier::query()
            ->where('product_id', $productId)
            ->where('is_preferred', true)
            ->first();

        if ($preferred && $preferred->lead_time_days !== null) {
            return (int) $preferred->lead_time_days;
        }

        $anySupplier = ProductSupplier::query()
            ->where('product_id', $productId)
            ->whereNotNull('lead_time_days')
            ->first();

        if ($anySupplier && $anySupplier->lead_time_days !== null) {
            return (int) $anySupplier->lead_time_days;
        }

        return (int) (SystemSetting::query()
            ->where('setting_key', 'supplier_max_acceptable_lead_days')
            ->value('setting_value') ?? 7);
    }

    /**
     * Retrieves existing active forecasts or triggers a new forecast run.
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

        if ($existing->count() >= $horizon) {
            return $existing;
        }

        // Trigger forecast run to refresh forecasts
        $this->forecastService->runForecast($productId, $branchId, $horizon);

        return DemandForecast::query()
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->where('forecast_date', '>=', $today)
            ->orderBy('forecast_date')
            ->take($horizon)
            ->get();
    }

    /**
     * Resolves scheduled receipts from open purchase orders.
     *
     * @return array<string, float>
     */
    protected function resolveScheduledReceipts(int $productId, int $branchId, int $leadTime): array
    {
        $receipts = [];

        if (!class_exists(PurchaseOrderItem::class)) {
            return $receipts;
        }

        $items = PurchaseOrderItem::query()
            ->where('product_id', $productId)
            ->whereHas('purchaseOrder', function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)
                    ->whereIn('status', ['ordered', 'partially_received', 'pending']);
            })
            ->with('purchaseOrder')
            ->get();

        foreach ($items as $item) {
            $po = $item->purchaseOrder;
            if (!$po) {
                continue;
            }

            $date = $po->expected_delivery_date
                ? Carbon::parse($po->expected_delivery_date)->format('Y-m-d')
                : now()->addDays($leadTime)->format('Y-m-d');

            $remaining = max(0, (int) $item->quantity_ordered - (int) ($item->quantity_received ?? 0));
            if ($remaining > 0) {
                $receipts[$date] = ($receipts[$date] ?? 0.0) + $remaining;
            }
        }

        return $receipts;
    }

    /**
     * Classifies risk into HIGH, MEDIUM, or LOW based on ESD, lead time, and review period.
     */
    public function classifyRisk(?string $esd, int $leadTime, int $reviewPeriod): string
    {
        if ($esd === null || trim($esd) === '') {
            return 'LOW';
        }

        $today = now()->startOfDay();
        $esdDate = Carbon::parse($esd)->startOfDay();
        $daysToEsd = (int) $today->diffInDays($esdDate, false);

        if ($daysToEsd <= $leadTime) {
            return 'HIGH';
        }

        if ($daysToEsd <= ($leadTime + $reviewPeriod)) {
            return 'MEDIUM';
        }

        return 'LOW';
    }

    /**
     * Synchronizes stock-out risk alerts in ai_recommendations.
     */
    protected function syncAiRecommendation(
        Product $product,
        Branch $branch,
        string $riskLevel,
        ?string $esd,
        float $availableStock,
        int $leadTime,
        int $reviewPeriod
    ): ?AiRecommendation {
        if (!in_array($riskLevel, ['HIGH', 'MEDIUM'], true)) {
            // Dismiss or close existing recommendation if risk drops to LOW
            AiRecommendation::query()
                ->where('type', 'STOCK_OUT_RISK')
                ->where('related_entity_type', 'Product')
                ->where('related_entity_id', $product->id)
                ->where('status', 'open')
                ->update(['status' => 'dismissed']);

            return null;
        }

        $text = sprintf(
            'Product %s is at %s risk of stock-out at %s. Expected stock-out date: %s (Lead time: %d days, Review period: %d days). Current stock: %s units.',
            $product->name,
            $riskLevel,
            $branch->name,
            $esd ?? 'Within horizon',
            $leadTime,
            $reviewPeriod,
            number_format($availableStock, 0)
        );

        return AiRecommendation::query()->updateOrCreate(
            [
                'type' => 'STOCK_OUT_RISK',
                'related_entity_type' => 'Product',
                'related_entity_id' => $product->id,
            ],
            [
                'recommendation_text' => $text,
                'priority' => strtolower($riskLevel),
                'priority_score' => $riskLevel === 'HIGH' ? 0.95 : 0.70,
                'status' => 'open',
                'factors' => [
                    'product_id' => $product->id,
                    'branch_id' => $branch->id,
                    'available_stock' => $availableStock,
                    'lead_time_days' => $leadTime,
                    'review_period_days' => $reviewPeriod,
                    'esd' => $esd,
                    'risk_level' => $riskLevel,
                ],
                'generated_at' => now(),
            ]
        );
    }
}
