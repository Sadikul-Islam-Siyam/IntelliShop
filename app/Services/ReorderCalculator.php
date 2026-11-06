<?php

namespace App\Services;

use App\Contracts\AnalyticsClient;
use App\Models\AiRecommendation;
use App\Models\Branch;
use App\Models\DemandForecast;
use App\Models\Inventory;
use App\Models\ModelRun;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\PurchaseOrderItem;
use App\Models\SalesItem;
use App\Models\StockPrediction;
use App\Models\SystemSetting;
use App\Services\Analytics\DemandForecastService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ReorderCalculator
{
    public const CONFLICT_FLAG_MESSAGE = 'cannot satisfy MOQ within capacity';

    public function __construct(
        protected AnalyticsClient $analyticsClient,
        protected DemandForecastService $forecastService
    ) {}

    /**
     * Calculate dynamic replenishment parameters (SS, ROP, Target Stock, Raw Qty,
     * and Final Recommended Reorder Qty after sequential constraints) for a product at a branch,
     * persisting results to stock_predictions and ai_recommendations.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function calculate(int $productId, int $branchId, array $overrides = []): array
    {
        $product = Product::query()->findOrFail($productId);
        $branch = Branch::query()->findOrFail($branchId);

        // 1. Inventory (on-hand, reserved, max_capacity)
        $inventory = Inventory::query()
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->first();

        $onHandStock = array_key_exists('stock', $overrides)
            ? (float) $overrides['stock']
            : ($inventory ? (float) $inventory->quantity_available : 0.0);

        $reservedStock = array_key_exists('reserved', $overrides)
            ? (float) $overrides['reserved']
            : ($inventory ? (float) ($inventory->quantity_reserved ?? 0.0) : 0.0);

        $maxCapacity = array_key_exists('capacity', $overrides)
            ? ($overrides['capacity'] !== null ? (float) $overrides['capacity'] : null)
            : ($inventory && $inventory->max_capacity !== null ? (float) $inventory->max_capacity : null);

        // 2. Open PO quantity (on_order)
        $onOrderQty = array_key_exists('on_order', $overrides)
            ? (float) $overrides['on_order']
            : $this->resolveOpenPoQuantity($productId, $branchId);

        // 3. Supplier terms from product_suppliers (lead_time_days L, min_order_qty MOQ, order_multiple)
        $supplierTerms = $this->resolveSupplierTerms($productId);

        $leadTimeDays = array_key_exists('lead_time_days', $overrides)
            ? (int) $overrides['lead_time_days']
            : $supplierTerms['lead_time_days'];

        $moq = array_key_exists('moq', $overrides)
            ? (int) $overrides['moq']
            : $supplierTerms['moq'];

        $orderMultiple = array_key_exists('order_multiple', $overrides)
            ? max(1, (int) $overrides['order_multiple'])
            : $supplierTerms['order_multiple'];

        // 4. Perishable shelf-life limit from product
        $shelfLifeDays = array_key_exists('shelf_life_days', $overrides)
            ? ($overrides['shelf_life_days'] !== null ? (int) $overrides['shelf_life_days'] : null)
            : $this->resolveShelfLifeDays($product);

        // 5. System settings: review period R and service level z
        $reviewPeriodDays = array_key_exists('review_period_days', $overrides)
            ? (int) $overrides['review_period_days']
            : $this->resolveReviewPeriodDays();

        $serviceLevelZ = array_key_exists('service_level_z', $overrides)
            ? (float) $overrides['service_level_z']
            : $this->resolveServiceLevelZ($overrides['service_level'] ?? null);

        // 6. Forecast demand (d_bar) and standard deviation of daily demand (sigma_d)
        $horizonDays = max(14, $leadTimeDays + $reviewPeriodDays);
        $forecastRows = $this->getOrGenerateForecasts($productId, $branchId, $horizonDays);

        $avgDailyDemand = array_key_exists('forecast_demand', $overrides)
            ? (float) $overrides['forecast_demand']
            : $this->resolveAverageDailyDemand($forecastRows, $reviewPeriodDays);

        $demandStdDev = array_key_exists('demand_stddev', $overrides)
            ? (float) $overrides['demand_stddev']
            : $this->resolveDemandStdDev($productId, $branchId, $forecastRows);

        // 7. Call Python analytics service POST /reorder with PHP fallback (Rule #6)
        $payload = [
            'forecast_demand' => $avgDailyDemand,
            'demand_stddev' => $demandStdDev,
            'service_level_z' => $serviceLevelZ,
            'lead_time_days' => $leadTimeDays,
            'review_period_days' => $reviewPeriodDays,
            'stock' => $onHandStock,
            'reserved' => $reservedStock,
            'on_order' => $onOrderQty,
            'moq' => $moq,
            'order_multiple' => $orderMultiple,
            'capacity' => $maxCapacity,
            'shelf_life_days' => $shelfLifeDays,
        ];

        try {
            $calcResult = $this->analyticsClient->call('/reorder', $payload);
        } catch (\Throwable $e) {
            Log::warning('Analytics /reorder endpoint failed, using PHP mathematical fallback: ' . $e->getMessage());
            $calcResult = $this->calculateFallback($payload);
        }

        // Ensure extended transparency keys are always populated even if a mock client returns only the 6 required keys
        $fullResult = array_merge($this->calculateFallback($payload), $calcResult);

        // 8. Persist to stock_predictions table
        $existingPrediction = StockPrediction::query()
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->first();

        $availableStock = (float) ($fullResult['available_stock'] ?? ($onHandStock - $reservedStock + $onOrderQty));
        $predictedDemandTotal = $existingPrediction && (float) $existingPrediction->predicted_demand > 0
            ? (float) $existingPrediction->predicted_demand
            : round((float) $forecastRows->sum('predicted_demand'), 2);

        $riskLevel = $existingPrediction?->risk_level
            ?? $this->inferRiskLevel($availableStock, (float) $fullResult['reorder_point'], (float) $fullResult['safety_stock']);

        $prediction = StockPrediction::query()->updateOrCreate(
            [
                'product_id' => $productId,
                'branch_id' => $branchId,
            ],
            [
                'current_stock' => $onHandStock,
                'predicted_demand' => $predictedDemandTotal,
                'recommended_reorder_qty' => (int) $fullResult['final_quantity'],
                'risk_level' => $riskLevel,
                'safety_stock' => (float) $fullResult['safety_stock'],
                'reorder_point' => (float) $fullResult['reorder_point'],
                'lead_time_days' => $leadTimeDays,
                'generated_at' => now(),
            ]
        );

        // 9. Update AI Recommendation with full replenishment & constraint details
        $recommendation = $this->syncReplenishmentRecommendation(
            $product,
            $branch,
            $prediction,
            $fullResult,
            $supplierTerms['supplier_id'] ?? null,
            $supplierTerms['supplier_name'] ?? null
        );

        return [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'is_expiry_tracked' => (bool) $product->is_expiry_tracked,
                'shelf_life_days' => $shelfLifeDays,
                'cost_price' => (float) ($product->cost_price ?? 0.0),
                'selling_price' => (float) ($product->selling_price ?? 0.0),
                'unit_price' => (float) ($supplierTerms['supply_price'] ?: ($product->cost_price ?: $product->selling_price)),
            ],
            'branch' => [
                'id' => $branch->id,
                'name' => $branch->name,
                'code' => $branch->code,
            ],
            'supplier' => [
                'id' => $supplierTerms['supplier_id'],
                'name' => $supplierTerms['supplier_name'],
                'lead_time_days' => $leadTimeDays,
                'moq' => $moq,
                'order_multiple' => $orderMultiple,
                'supply_price' => (float) ($supplierTerms['supply_price'] ?? 0.0),
            ],
            'parameters' => [
                'avg_daily_demand' => (float) $fullResult['avg_daily_demand'],
                'demand_stddev' => (float) $fullResult['demand_stddev'],
                'service_level_z' => (float) $fullResult['service_level_z'],
                'lead_time_days' => (int) $fullResult['lead_time_days'],
                'review_period_days' => (int) $fullResult['review_period_days'],
                'stock' => (float) $fullResult['stock'],
                'reserved' => (float) $fullResult['reserved'],
                'on_order' => (float) $fullResult['on_order'],
                'available_stock' => (float) $fullResult['available_stock'],
                'moq' => (int) $fullResult['moq'],
                'order_multiple' => (int) $fullResult['order_multiple'],
                'capacity' => $fullResult['capacity'],
                'shelf_life_days' => $fullResult['shelf_life_days'],
            ],
            'calculation' => [
                'lead_time_demand' => (float) $fullResult['lead_time_demand'],
                'safety_stock' => (float) $fullResult['safety_stock'],
                'reorder_point' => (float) $fullResult['reorder_point'],
                'forecast_demand_l_plus_r' => (float) $fullResult['forecast_demand_l_plus_r'],
                'target_stock' => (float) $fullResult['target_stock'],
                'raw_quantity' => (float) $fullResult['raw_quantity'],
                'after_moq' => (float) $fullResult['after_moq'],
                'after_multiple' => (int) $fullResult['after_multiple'],
                'after_capacity' => (int) $fullResult['after_capacity'],
                'after_shelf_life' => (int) $fullResult['after_shelf_life'],
                'final_quantity' => (int) $fullResult['final_quantity'],
                'capped_quantity' => (int) $fullResult['capped_quantity'],
                'constraints' => $fullResult['constraints'],
                'conflict' => (bool) $fullResult['conflict'],
                'conflict_flag' => $fullResult['conflict_flag'],
            ],
            'prediction' => [
                'id' => $prediction->id,
                'product_id' => $prediction->product_id,
                'branch_id' => $prediction->branch_id,
                'current_stock' => (float) $prediction->current_stock,
                'predicted_demand' => (float) $prediction->predicted_demand,
                'predicted_stockout_date' => $prediction->predicted_stockout_date?->format('Y-m-d'),
                'safety_stock' => (float) $prediction->safety_stock,
                'reorder_point' => (float) $prediction->reorder_point,
                'recommended_reorder_qty' => (int) $prediction->recommended_reorder_qty,
                'risk_level' => $prediction->risk_level,
                'lead_time_days' => (int) $prediction->lead_time_days,
                'generated_at' => $prediction->generated_at?->toIso8601String(),
            ],
            'recommendation' => $recommendation ? [
                'id' => $recommendation->id,
                'type' => $recommendation->type,
                'priority' => $recommendation->priority,
                'text' => $recommendation->recommendation_text,
                'status' => $recommendation->status,
                'factors' => $recommendation->factors,
            ] : null,
        ];
    }

    /**
     * Pure PHP mathematical implementation of Section 4.3 formulas & sequential constraints.
     * Ensures 100% resilience when the Python microservice is offline (Rule #6).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function calculateFallback(array $payload): array
    {
        $avgDailyDemand = max(0.0, (float) ($payload['forecast_demand'] ?? 0.0));
        $sigmaD = max(0.0, (float) ($payload['demand_stddev'] ?? 0.0));
        $z = max(0.0, (float) ($payload['service_level_z'] ?? 1.645));
        $leadTime = max(0, (int) ($payload['lead_time_days'] ?? 1));
        $reviewPeriod = max(0, (int) ($payload['review_period_days'] ?? 7));

        $stock = (float) ($payload['stock'] ?? 0.0);
        $reserved = max(0.0, (float) ($payload['reserved'] ?? 0.0));
        $onOrder = max(0.0, (float) ($payload['on_order'] ?? 0.0));
        $available = isset($payload['available_stock']) && is_numeric($payload['available_stock'])
            ? (float) $payload['available_stock']
            : ($stock - $reserved + $onOrder);

        $moq = max(0, (int) ($payload['moq'] ?? 0));
        $multiple = max(1, (int) ($payload['order_multiple'] ?? 1));

        $capacity = isset($payload['capacity']) && $payload['capacity'] !== null && is_numeric($payload['capacity'])
            ? (float) $payload['capacity']
            : null;
        $shelfLifeDays = isset($payload['shelf_life_days']) && $payload['shelf_life_days'] !== null && is_numeric($payload['shelf_life_days']) && (int) $payload['shelf_life_days'] > 0
            ? (int) $payload['shelf_life_days']
            : null;

        // 1. Lead Time Demand = d_bar * L
        $leadTimeDemand = $avgDailyDemand * $leadTime;

        // 2. Safety Stock (SS) = z * sigma_d * sqrt(L)
        $safetyStock = $z * $sigmaD * sqrt((float) $leadTime);

        // 3. Reorder Point (ROP) = d_bar * L + SS
        $reorderPoint = $leadTimeDemand + $safetyStock;

        // 4. Target Stock = Forecast Demand(L + R) + SS
        $demandLPlusR = isset($payload['forecast_demand_l_plus_r']) && is_numeric($payload['forecast_demand_l_plus_r'])
            ? (float) $payload['forecast_demand_l_plus_r']
            : ($avgDailyDemand * ($leadTime + $reviewPeriod));
        $targetStock = $demandLPlusR + $safetyStock;

        // 5. Raw Order Quantity = max(0, Target Stock - Available)
        $rawQty = max(0.0, $targetStock - $available);

        // 6. Sequential Constraint Pipeline
        $constraints = [];
        $afterMoq = 0.0;
        $afterMultiple = 0;
        $afterCapacity = 0;
        $afterShelfLife = 0;
        $capacityLimit = $capacity !== null ? max(0, (int) floor($capacity - $available)) : null;
        $shelfLifeLimit = $shelfLifeDays !== null ? max(0, (int) floor($avgDailyDemand * $shelfLifeDays)) : null;
        $conflict = false;
        $conflictFlag = null;

        if ($rawQty > 0) {
            // (1) MOQ
            $q = max($rawQty, (float) $moq);
            if ((float) $moq > $rawQty) {
                $constraints[] = 'moq';
            }
            $afterMoq = round($q, 2);

            // (2) Order Multiple
            $qMultiple = (int) (ceil($q / $multiple) * $multiple);
            if ($qMultiple != $afterMoq && $multiple > 1) {
                $constraints[] = 'order_multiple';
            }
            $q = (float) $qMultiple;
            $afterMultiple = $qMultiple;

            // (3) Max Warehouse Capacity
            $cappedByUpperBound = false;
            if ($capacityLimit !== null) {
                if ($q > $capacityLimit) {
                    $q = (float) $capacityLimit;
                    $constraints[] = 'capacity';
                    $cappedByUpperBound = true;
                }
            }
            $afterCapacity = (int) $q;

            // (4) Perishable Shelf-Life Limit
            if ($shelfLifeLimit !== null) {
                if ($q > $shelfLifeLimit) {
                    $q = (float) $shelfLifeLimit;
                    $constraints[] = 'shelf_life';
                    $cappedByUpperBound = true;
                }
            }
            $afterShelfLife = (int) $q;

            // Conflict Check
            if ($cappedByUpperBound && $moq > 0 && $q < $moq) {
                $conflict = true;
                $conflictFlag = self::CONFLICT_FLAG_MESSAGE;
                $constraints[] = self::CONFLICT_FLAG_MESSAGE;
                $finalQty = 0;
                $cappedQty = (int) $q;
            } else {
                $finalQty = (int) $q;
                $cappedQty = (int) $q;
            }
        } else {
            $finalQty = 0;
            $cappedQty = 0;
        }

        return [
            'safety_stock' => round($safetyStock, 2),
            'reorder_point' => round($reorderPoint, 2),
            'target_stock' => round($targetStock, 2),
            'raw_quantity' => round($rawQty, 2),
            'final_quantity' => $finalQty,
            'constraints' => $constraints,
            'avg_daily_demand' => round($avgDailyDemand, 2),
            'demand_stddev' => round($sigmaD, 2),
            'service_level_z' => round($z, 4),
            'lead_time_days' => $leadTime,
            'review_period_days' => $reviewPeriod,
            'lead_time_demand' => round($leadTimeDemand, 2),
            'forecast_demand_l_plus_r' => round($demandLPlusR, 2),
            'available_stock' => round($available, 2),
            'stock' => round($stock, 2),
            'reserved' => round($reserved, 2),
            'on_order' => round($onOrder, 2),
            'moq' => $moq,
            'order_multiple' => $multiple,
            'capacity' => $capacity,
            'capacity_headroom' => $capacityLimit,
            'shelf_life_days' => $shelfLifeDays,
            'shelf_life_limit' => $shelfLifeLimit,
            'after_moq' => $afterMoq,
            'after_multiple' => $afterMultiple,
            'after_capacity' => $afterCapacity,
            'after_shelf_life' => $afterShelfLife,
            'capped_quantity' => $cappedQty,
            'conflict' => $conflict,
            'conflict_flag' => $conflictFlag,
        ];
    }

    /**
     * Calculate reorder recommendations for all active products at a branch.
     *
     * @return array<int, array<string, mixed>>
     */
    public function calculateAllForBranch(int $branchId): array
    {
        $products = Product::query()->where('status', 'active')->orderBy('name')->get();
        $results = [];

        foreach ($products as $product) {
            try {
                $results[] = $this->calculate($product->id, $branchId);
            } catch (\Throwable $e) {
                Log::warning("Reorder calculation failed for product {$product->id} at branch {$branchId}: " . $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * Ensure all active products at a branch have up-to-date safety stock, ROP, and reorder quantities.
     *
     * @return array<int, array<string, mixed>>
     */
    public function ensureBranchCalculations(int $branchId, bool $force = false): array
    {
        $products = Product::query()->where('status', 'active')->orderBy('name')->get();
        $results = [];

        foreach ($products as $product) {
            $existing = StockPrediction::query()
                ->where('product_id', $product->id)
                ->where('branch_id', $branchId)
                ->first();

            $needsRun = $force
                || !$existing
                || $existing->safety_stock === null
                || $existing->reorder_point === null
                || !$existing->generated_at
                || $existing->generated_at->isBefore(now()->startOfDay());

            if ($needsRun) {
                try {
                    $results[] = $this->calculate($product->id, $branchId);
                } catch (\Throwable $e) {
                    Log::warning("Auto reorder calculation failed for product {$product->id}: " . $e->getMessage());
                }
            } else {
                $results[] = $this->buildViewRecordFromPrediction($product, $branchId, $existing);
            }
        }

        return $results;
    }

    /**
     * Reconstructs the full transparent parameter & constraint breakdown for a product at a branch.
     *
     * @return array<string, mixed>
     */
    public function buildViewRecordFromPrediction(Product $product, int $branchId, ?StockPrediction $prediction = null): array
    {
        return $this->calculate($product->id, $branchId);
    }

    /**
     * Resolve open purchase order quantity for a product at a branch.
     */
    public function resolveOpenPoQuantity(int $productId, int $branchId): float
    {
        if (!class_exists(PurchaseOrderItem::class)) {
            return 0.0;
        }

        $items = PurchaseOrderItem::query()
            ->where('product_id', $productId)
            ->whereHas('purchaseOrder', function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)
                    ->whereIn('status', ['ordered', 'partially_received', 'pending', 'approved']);
            })
            ->get();

        $totalOpen = 0.0;
        foreach ($items as $item) {
            $ordered = (float) ($item->quantity_ordered ?? $item->quantity ?? 0);
            $received = (float) ($item->quantity_received ?? 0);
            $totalOpen += max(0.0, $ordered - $received);
        }

        return $totalOpen;
    }

    /**
     * Resolve supplier terms (lead_time_days, moq, order_multiple) from product_suppliers.
     *
     * @return array{supplier_id: int|null, supplier_name: string|null, lead_time_days: int, moq: int, order_multiple: int}
     */
    public function resolveSupplierTerms(int $productId): array
    {
        $preferred = ProductSupplier::query()
            ->with('supplier')
            ->where('product_id', $productId)
            ->where('is_preferred', true)
            ->first();

        $record = $preferred ?? ProductSupplier::query()
            ->with('supplier')
            ->where('product_id', $productId)
            ->orderByDesc('is_preferred')
            ->orderBy('lead_time_days')
            ->first();

        if ($record) {
            return [
                'supplier_id' => $record->supplier_id,
                'supplier_name' => $record->supplier?->name ?? 'Preferred Supplier',
                'lead_time_days' => max(1, (int) ($record->lead_time_days ?? 7)),
                'moq' => max(0, (int) ($record->min_order_qty ?? 0)),
                'order_multiple' => max(1, (int) ($record->order_multiple ?? 1)),
                'supply_price' => (float) ($record->supply_price ?? 0.0),
            ];
        }

        $defaultLeadTime = (int) (SystemSetting::query()
            ->where('setting_key', 'supplier_max_acceptable_lead_days')
            ->value('setting_value') ?? 7);

        return [
            'supplier_id' => null,
            'supplier_name' => 'Standard Default',
            'lead_time_days' => max(1, $defaultLeadTime),
            'moq' => 1,
            'order_multiple' => 1,
            'supply_price' => 0.0,
        ];
    }

    /**
     * Resolve perishable shelf-life days for a product, or null if non-perishable.
     */
    protected function resolveShelfLifeDays(Product $product): ?int
    {
        if ($product->shelf_life_days !== null && (int) $product->shelf_life_days > 0) {
            return (int) $product->shelf_life_days;
        }

        return null;
    }

    /**
     * Resolve replenishment review period R in days from system_settings (default 7).
     */
    public function resolveReviewPeriodDays(): int
    {
        $val = SystemSetting::query()
            ->where('setting_key', 'replenishment_review_period_days')
            ->value('setting_value');

        return $val !== null && (int) $val > 0 ? (int) $val : 7;
    }

    /**
     * Resolve z-score from replenishment_service_level in system_settings (default 0.95 -> 1.645).
     */
    public function resolveServiceLevelZ(mixed $overrideServiceLevel = null): float
    {
        $raw = $overrideServiceLevel ?? SystemSetting::query()
            ->where('setting_key', 'replenishment_service_level')
            ->value('setting_value');

        if ($raw === null) {
            return 1.645;
        }

        $value = (float) $raw;

        // If already provided as a z-score (e.g., 1.645)
        if ($value > 1.0 && $value <= 4.0) {
            return round($value, 4);
        }

        // Convert percentage (e.g., 95) to probability (0.95)
        $prob = $value > 1.0 ? ($value / 100.0) : $value;

        // Standard exact lookup table
        if (abs($prob - 0.90) < 0.001) {
            return 1.2816;
        }
        if (abs($prob - 0.95) < 0.001) {
            return 1.645;
        }
        if (abs($prob - 0.975) < 0.001) {
            return 1.96;
        }
        if (abs($prob - 0.98) < 0.001) {
            return 2.0537;
        }
        if (abs($prob - 0.99) < 0.001) {
            return 2.3263;
        }

        return 1.645;
    }

    /**
     * Retrieve or generate demand forecasts for the product and branch.
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
            Log::warning("Forecast generation inside ReorderCalculator failed: " . $e->getMessage());
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
     * Compute average daily demand d_bar over the review period R.
     *
     * @param  Collection<int, DemandForecast>  $forecastRows
     */
    protected function resolveAverageDailyDemand(Collection $forecastRows, int $reviewPeriodDays): float
    {
        if ($forecastRows->isEmpty()) {
            return 0.0;
        }

        $windowRows = $forecastRows->take(max(1, $reviewPeriodDays));
        $avg = (float) $windowRows->avg('predicted_demand');

        return round(max(0.0, $avg), 2);
    }

    /**
     * Resolve daily demand standard deviation (sigma_d) from model_runs or sales history.
     *
     * @param  Collection<int, DemandForecast>  $forecastRows
     */
    public function resolveDemandStdDev(int $productId, int $branchId, Collection $forecastRows): float
    {
        // 1. Check latest selected ModelRun for this product & branch
        $modelRun = ModelRun::query()
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->orderByDesc('selected')
            ->orderByDesc('trained_at')
            ->orderByDesc('id')
            ->first();

        if ($modelRun) {
            $params = is_array($modelRun->params) ? $modelRun->params : [];
            if (isset($params['sigma_d']) && is_numeric($params['sigma_d']) && (float) $params['sigma_d'] > 0) {
                return round((float) $params['sigma_d'], 2);
            }
            if ($modelRun->rse !== null && (float) $modelRun->rse > 0) {
                return round((float) $modelRun->rse, 2);
            }
        }

        // 2. Fallback: compute sample standard deviation from recent daily sales history
        $historyStart = Carbon::today()->subDays(30)->toDateString();
        $dailySales = SalesItem::query()
            ->selectRaw('DATE(sales.sale_date) as day, SUM(sales_items.quantity) as total_qty')
            ->join('sales', 'sales.id', '=', 'sales_items.sale_id')
            ->where('sales_items.product_id', $productId)
            ->where('sales.branch_id', $branchId)
            ->where('sales.status', 'completed')
            ->whereDate('sales.sale_date', '>=', $historyStart)
            ->groupByRaw('DATE(sales.sale_date)')
            ->pluck('total_qty')
            ->map(fn ($v) => (float) $v)
            ->values()
            ->all();

        if (count($dailySales) >= 2) {
            $mean = array_sum($dailySales) / count($dailySales);
            $sumSq = 0.0;
            foreach ($dailySales as $val) {
                $sumSq += ($val - $mean) ** 2;
            }
            $sampleStd = sqrt($sumSq / (count($dailySales) - 1));
            if ($sampleStd > 0) {
                return round($sampleStd, 2);
            }
        }

        // 3. Fallback from forecast interval width or forecast variation if available
        if ($forecastRows->count() >= 2) {
            $first = $forecastRows->first();
            if ($first && $first->upper_bound !== null && $first->predicted_demand !== null) {
                $halfWidth = max(0.0, (float) $first->upper_bound - (float) $first->predicted_demand);
                if ($halfWidth > 0) {
                    return round($halfWidth / 1.96, 2);
                }
            }
        }

        return 0.0;
    }

    /**
     * Infer stock-out risk level when no prior StockPrediction exists.
     */
    protected function inferRiskLevel(float $availableStock, float $reorderPoint, float $safetyStock): string
    {
        if ($reorderPoint <= 0) {
            return 'LOW';
        }
        if ($availableStock <= $safetyStock) {
            return 'HIGH';
        }
        if ($availableStock <= $reorderPoint) {
            return 'MEDIUM';
        }

        return 'LOW';
    }

    /**
     * Synchronize replenishment recommendation and constraint conflict flags in ai_recommendations.
     *
     * @param  array<string, mixed>  $calc
     */
    protected function syncReplenishmentRecommendation(
        Product $product,
        Branch $branch,
        StockPrediction $prediction,
        array $calc,
        ?int $supplierId,
        ?string $supplierName
    ): ?AiRecommendation {
        $finalQty = (int) ($calc['final_quantity'] ?? 0);
        $rawQty = (float) ($calc['raw_quantity'] ?? 0.0);
        $conflict = (bool) ($calc['conflict'] ?? false);
        $conflictFlag = $calc['conflict_flag'] ?? null;

        if ($rawQty <= 0 && !$conflict) {
            return null;
        }

        if ($conflict) {
            $text = sprintf(
                'Replenishment conflict for %s at %s: %s (MOQ: %d, Capped Qty: %d, Target: %s, Available: %s). Order not placed automatically.',
                $product->name,
                $branch->name,
                $conflictFlag ?? self::CONFLICT_FLAG_MESSAGE,
                (int) $calc['moq'],
                (int) $calc['capped_quantity'],
                number_format((float) $calc['target_stock'], 2),
                number_format((float) $calc['available_stock'], 2)
            );
            $priority = 'high';
            $priorityScore = 0.95;
        } else {
            $text = sprintf(
                'Recommended reorder of %d units for %s at %s (ROP: %s, Safety Stock: %s, Target: %s, Available: %s).',
                $finalQty,
                $product->name,
                $branch->name,
                number_format((float) $calc['reorder_point'], 2),
                number_format((float) $calc['safety_stock'], 2),
                number_format((float) $calc['target_stock'], 2),
                number_format((float) $calc['available_stock'], 2)
            );
            $priority = $prediction->risk_level === 'HIGH' ? 'high' : 'medium';
            $priorityScore = $prediction->risk_level === 'HIGH' ? 0.90 : 0.70;
        }

        return AiRecommendation::query()->updateOrCreate(
            [
                'type' => 'REORDER_RECOMMENDATION',
                'related_entity_type' => 'Product',
                'related_entity_id' => $product->id,
            ],
            [
                'recommendation_text' => $text,
                'priority' => $priority,
                'priority_score' => $priorityScore,
                'status' => 'open',
                'factors' => [
                    'product_id' => $product->id,
                    'branch_id' => $branch->id,
                    'supplier_id' => $supplierId,
                    'supplier_name' => $supplierName,
                    'avg_daily_demand' => $calc['avg_daily_demand'],
                    'demand_stddev' => $calc['demand_stddev'],
                    'service_level_z' => $calc['service_level_z'],
                    'lead_time_days' => $calc['lead_time_days'],
                    'review_period_days' => $calc['review_period_days'],
                    'safety_stock' => $calc['safety_stock'],
                    'reorder_point' => $calc['reorder_point'],
                    'target_stock' => $calc['target_stock'],
                    'available_stock' => $calc['available_stock'],
                    'raw_quantity' => $calc['raw_quantity'],
                    'moq' => $calc['moq'],
                    'order_multiple' => $calc['order_multiple'],
                    'capacity' => $calc['capacity'],
                    'shelf_life_days' => $calc['shelf_life_days'],
                    'final_quantity' => $calc['final_quantity'],
                    'constraints' => $calc['constraints'],
                    'conflict' => $conflict,
                    'conflict_flag' => $conflictFlag,
                ],
                'generated_at' => now(),
            ]
        );
    }
}

