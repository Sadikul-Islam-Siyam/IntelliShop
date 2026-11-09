<?php

namespace App\Services;

use App\Contracts\AnalyticsClient;
use App\Models\AiRecommendation;
use App\Models\Branch;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\StockPrediction;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Log;

/**
 * SupplierEvaluationService (Sub-Features #47, #48, #68)
 *
 * Implements the two-stage Supplier Evaluation & Recommendation Engine:
 * - Stage 1 (#47): Eligibility Filter (ACTIVE status, required_quantity >= MOQ, lead_time_days <= max_acceptable_lead_days)
 * - Context-Aware Weight Selector (#68): NORMAL (stock > ROP), WARNING (stock <= ROP), PANIC (stock <= 10% of ROP)
 * - Stage 2 (#48): Simple Additive Weighting (SAW) normalization & ranking with lower-price tie-breaker.
 */
class SupplierEvaluationService
{
    public const ALERT_NO_ELIGIBLE_SUPPLIERS = 'no_eligible_suppliers';

    /**
     * Default weight vectors per mode (Section 4.4 / Page 14).
     *
     * @var array<string, array{price: float, speed: float, reliability: float}>
     */
    public const DEFAULT_WEIGHT_MODES = [
        'NORMAL' => [
            'price' => 0.70,
            'speed' => 0.10,
            'reliability' => 0.20,
        ],
        'WARNING' => [
            'price' => 0.30,
            'speed' => 0.30,
            'reliability' => 0.40,
        ],
        'PANIC' => [
            'price' => 0.10,
            'speed' => 0.70,
            'reliability' => 0.20,
        ],
    ];

    public function __construct(
        protected AnalyticsClient $analyticsClient,
        protected FulfillmentRateCalculator $fulfillmentCalculator,
        protected ReorderCalculator $reorderCalculator
    ) {}

    /**
     * Evaluate and rank suppliers for a product at a branch.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function evaluate(int $productId, int $branchId, array $overrides = []): array
    {
        $product = Product::query()->find($productId);
        $branch = Branch::query()->find($branchId);

        // 1. Resolve current stock
        if (array_key_exists('stock', $overrides) && $overrides['stock'] !== null) {
            $stock = (float) $overrides['stock'];
        } else {
            $inventory = Inventory::query()
                ->where('product_id', $productId)
                ->where('branch_id', $branchId)
                ->first();
            $stock = $inventory ? (float) $inventory->quantity_available : 0.0;
        }

        // 2. Resolve ROP and required_quantity
        $prediction = StockPrediction::query()
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->first();

        if (
            (!array_key_exists('rop', $overrides) && !array_key_exists('reorder_point', $overrides))
            || !array_key_exists('required_quantity', $overrides)
        ) {
            if ((!$prediction || $prediction->reorder_point === null) && $product && $branch) {
                try {
                    $this->reorderCalculator->calculate($productId, $branchId);
                    $prediction = StockPrediction::query()
                        ->where('product_id', $productId)
                        ->where('branch_id', $branchId)
                        ->first();
                } catch (\Throwable $e) {
                    Log::warning("Reorder calculation inside SupplierEvaluationService failed: " . $e->getMessage());
                }
            }
        }

        $rop = array_key_exists('rop', $overrides) && $overrides['rop'] !== null
            ? (float) $overrides['rop']
            : (array_key_exists('reorder_point', $overrides) && $overrides['reorder_point'] !== null
                ? (float) $overrides['reorder_point']
                : ($prediction && $prediction->reorder_point !== null ? (float) $prediction->reorder_point : 0.0));

        $requiredQuantity = array_key_exists('required_quantity', $overrides) && $overrides['required_quantity'] !== null
            ? (float) $overrides['required_quantity']
            : ($prediction && (int) $prediction->recommended_reorder_qty > 0
                ? (float) $prediction->recommended_reorder_qty
                : 10.0);

        // 3. Resolve settings
        $maxAcceptableLeadDays = array_key_exists('max_acceptable_lead_days', $overrides) && $overrides['max_acceptable_lead_days'] !== null
            ? (int) $overrides['max_acceptable_lead_days']
            : $this->resolveMaxAcceptableLeadDays();

        $panicRatio = array_key_exists('panic_ratio_of_rop', $overrides) && $overrides['panic_ratio_of_rop'] !== null
            ? (float) $overrides['panic_ratio_of_rop']
            : $this->resolvePanicRatio();

        $modeOverride = isset($overrides['mode']) && is_string($overrides['mode'])
            ? strtoupper(trim($overrides['mode']))
            : null;

        // 4. Resolve candidate suppliers
        $candidates = array_key_exists('candidates', $overrides) && is_array($overrides['candidates'])
            ? $overrides['candidates']
            : $this->resolveProductCandidates($productId, $product ? (float) ($product->cost_price ?? 0.0) : 0.0);

        $payload = [
            'product_id' => $productId,
            'branch_id' => $branchId,
            'required_quantity' => $requiredQuantity,
            'stock' => $stock,
            'available_stock' => $stock,
            'rop' => $rop,
            'reorder_point' => $rop,
            'max_acceptable_lead_days' => $maxAcceptableLeadDays,
            'panic_ratio_of_rop' => $panicRatio,
            'candidates' => $candidates,
        ];

        if ($modeOverride) {
            $payload['mode'] = $modeOverride;
        }
        if (isset($overrides['weights_override']) && is_array($overrides['weights_override'])) {
            $payload['weights_override'] = $overrides['weights_override'];
        }

        // 5. Call Python analytics service POST /suppliers/evaluate with PHP fallback (Rule #6)
        try {
            $serviceResult = $this->analyticsClient->call('/suppliers/evaluate', $payload);
        } catch (\Throwable $e) {
            Log::warning('Analytics /suppliers/evaluate failed, using PHP mathematical fallback: ' . $e->getMessage());
            $serviceResult = $this->evaluateFallback($payload);
        }

        $fallbackResult = $this->evaluateFallback($payload);
        $merged = array_merge($fallbackResult, $serviceResult);

        if (isset($merged['alert']) && is_string($merged['alert']) && trim($merged['alert']) !== '') {
            $merged['alert'] = [
                'code' => self::ALERT_NO_ELIGIBLE_SUPPLIERS,
                'message' => $merged['alert'],
            ];
        }

        // 6. Sync alert in ai_recommendations if no suppliers are eligible
        if ($product && $branch) {
            $this->syncIneligibleAlert($product, $branch, $merged);
        }

        return $merged;
    }

    /**
     * Pure PHP mathematical implementation of Stage 1 Eligibility (#47),
     * Context-Aware Weight Selector (#68), and Stage 2 SAW Scoring (#48).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function evaluateFallback(array $payload): array
    {
        $productId = (int) ($payload['product_id'] ?? 0);
        $branchId = (int) ($payload['branch_id'] ?? 0);
        $requiredQuantity = (float) ($payload['required_quantity'] ?? 0.0);
        $stock = (float) ($payload['stock'] ?? 0.0);
        $rop = (float) ($payload['reorder_point'] ?? $payload['rop'] ?? 0.0);
        $maxAcceptableLeadDays = isset($payload['max_acceptable_lead_days']) && $payload['max_acceptable_lead_days'] !== null
            ? (int) $payload['max_acceptable_lead_days']
            : 14;
        $panicRatio = isset($payload['panic_ratio_of_rop']) && $payload['panic_ratio_of_rop'] !== null
            ? (float) $payload['panic_ratio_of_rop']
            : 0.10;
        $modeOverride = isset($payload['mode']) && is_string($payload['mode']) && trim($payload['mode']) !== ''
            ? strtoupper(trim($payload['mode']))
            : null;
        $weightsOverride = isset($payload['weights_override']) && is_array($payload['weights_override'])
            ? $payload['weights_override']
            : null;

        // 1. Select Weight Mode (#68)
        $modeResult = $this->selectWeightMode($stock, $rop, $panicRatio, $modeOverride, $weightsOverride);
        $mode = $modeResult['mode'];
        $weights = $modeResult['weights'];

        // 2. Stage 1: Eligibility Filter (#47)
        $candidates = is_array($payload['candidates'] ?? null) ? $payload['candidates'] : [];
        $eligibilityResult = $this->filterEligibleSuppliers($candidates, $requiredQuantity, $maxAcceptableLeadDays);
        $eligible = $eligibilityResult['eligible'];
        $ineligible = $eligibilityResult['ineligible'];

        // 3. Stage 2: SAW Weighted Scoring & Ranking (#48)
        $ranking = $this->scoreAndRankSuppliers($eligible, $weights);
        $recommended = $ranking[0] ?? null;

        return [
            'product_id' => $productId,
            'branch_id' => $branchId,
            'required_quantity' => $requiredQuantity,
            'stock' => $stock,
            'rop' => $rop,
            'panic_threshold' => $modeResult['panic_threshold'],
            'max_acceptable_lead_days' => $maxAcceptableLeadDays,
            'mode' => $mode,
            'weights' => $weights,
            'eligible' => $eligible,
            'ineligible' => $ineligible,
            'ranking' => $ranking,
            'recommended_supplier' => $recommended,
            'alert' => $eligibilityResult['alert'],
            'candidates_count' => $eligibilityResult['candidates_count'],
            'eligible_count' => $eligibilityResult['eligible_count'],
        ];
    }

    /**
     * Context-Aware Weight Selector (#68).
     *
     * Rules:
     * - stock > ROP           -> NORMAL  (price: 0.70, speed: 0.10, reliability: 0.20)
     * - stock <= ROP          -> WARNING (price: 0.30, speed: 0.30, reliability: 0.40)
     * - stock <= 10% of ROP   -> PANIC   (price: 0.10, speed: 0.70, reliability: 0.20)
     *
     * @param  array<string, mixed>|null  $weightsOverride
     * @return array{mode: string, weights: array{price: float, speed: float, reliability: float}, stock: float, rop: float, panic_threshold: float}
     */
    public function selectWeightMode(
        float $stock,
        float $rop,
        float $panicRatio = 0.10,
        ?string $modeOverride = null,
        ?array $weightsOverride = null
    ): array {
        $panicRatio = max(0.0, min(1.0, $panicRatio));
        $panicThreshold = max(0.0, $rop) * $panicRatio;

        if ($modeOverride && isset(self::DEFAULT_WEIGHT_MODES[strtoupper($modeOverride)])) {
            $mode = strtoupper($modeOverride);
        } elseif ($rop > 0 && $stock <= $panicThreshold) {
            $mode = 'PANIC';
        } elseif ($rop <= 0 && $stock <= 0) {
            $mode = 'PANIC';
        } elseif ($stock <= $rop) {
            $mode = 'WARNING';
        } else {
            $mode = 'NORMAL';
        }

        $baseWeights = $this->resolveConfiguredWeightsForMode($mode);
        if (is_array($weightsOverride) && isset($weightsOverride[$mode]) && is_array($weightsOverride[$mode])) {
            $baseWeights = array_merge($baseWeights, $weightsOverride[$mode]);
        }

        $wp = max(0.0, (float) ($baseWeights['price'] ?? 0.0));
        $wl = max(0.0, (float) ($baseWeights['speed'] ?? $baseWeights['lead_time'] ?? 0.0));
        $wr = max(0.0, (float) ($baseWeights['reliability'] ?? $baseWeights['fulfillment'] ?? 0.0));
        $total = $wp + $wl + $wr;

        if ($total > 0 && abs($total - 1.0) > 1e-6) {
            $wp /= $total;
            $wl /= $total;
            $wr /= $total;
        } elseif ($total <= 0) {
            $defaults = self::DEFAULT_WEIGHT_MODES[$mode];
            $wp = $defaults['price'];
            $wl = $defaults['speed'];
            $wr = $defaults['reliability'];
        }

        return [
            'mode' => $mode,
            'weights' => [
                'price' => round($wp, 4),
                'speed' => round($wl, 4),
                'reliability' => round($wr, 4),
            ],
            'stock' => round($stock, 2),
            'rop' => round($rop, 2),
            'panic_threshold' => round($panicThreshold, 2),
        ];
    }

    /**
     * Stage 1: Eligibility Filter (#47).
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    public function filterEligibleSuppliers(
        array $candidates,
        float $requiredQuantity,
        int $maxAcceptableLeadDays = 14
    ): array {
        $eligible = [];
        $ineligible = [];

        foreach ($candidates as $cand) {
            $reasons = [];

            $rawStatus = $cand['status'] ?? null;
            $isActive = array_key_exists('is_active', $cand)
                ? (bool) $cand['is_active']
                : ($rawStatus === null ? true : in_array(strtolower(trim((string) $rawStatus)), ['active', '1', 'true'], true));

            if (!$isActive) {
                $reasons[] = 'inactive_supplier';
            }

            $moq = max(0, (int) ($cand['moq'] ?? $cand['min_order_qty'] ?? 0));
            if ($requiredQuantity < $moq) {
                $reasons[] = sprintf('below_moq (required %d < MOQ %d)', (int) $requiredQuantity, $moq);
            }

            $leadTime = (int) ($cand['lead_time_days'] ?? 0);
            if ($leadTime <= 0) {
                $reasons[] = 'invalid_lead_time';
            } elseif ($leadTime > $maxAcceptableLeadDays) {
                $reasons[] = sprintf('lead_time_exceeded (%dd > max %dd)', $leadTime, $maxAcceptableLeadDays);
            }

            $price = (float) ($cand['price'] ?? $cand['supply_price'] ?? $cand['unit_price'] ?? 0.0);
            if ($price <= 0) {
                $reasons[] = 'invalid_price';
            }

            $fulfillment = $cand['fulfillment_rate'] ?? null;
            $fulfillmentVal = $fulfillment === null ? 0.90 : max(0.0, min(1.0, (float) $fulfillment));

            $normalized = array_merge($cand, [
                'supplier_id' => $cand['supplier_id'] ?? $cand['id'] ?? null,
                'supplier_name' => $cand['supplier_name'] ?? $cand['name'] ?? null,
                'status' => $isActive ? 'active' : 'inactive',
                'price' => round($price, 2),
                'lead_time_days' => $leadTime,
                'moq' => $moq,
                'order_multiple' => max(1, (int) ($cand['order_multiple'] ?? 1)),
                'fulfillment_rate' => round($fulfillmentVal, 4),
            ]);

            if (empty($reasons)) {
                $eligible[] = $normalized;
            } else {
                $normalized['ineligible_reasons'] = $reasons;
                $ineligible[] = $normalized;
            }
        }

        $alert = null;
        if (empty($eligible)) {
            $alert = [
                'code' => self::ALERT_NO_ELIGIBLE_SUPPLIERS,
                'message' => sprintf(
                    'No eligible suppliers meet all Stage 1 requirements (active status, required_quantity >= MOQ, lead_time_days <= %d).',
                    $maxAcceptableLeadDays
                ),
            ];
        }

        return [
            'eligible' => $eligible,
            'ineligible' => $ineligible,
            'alert' => $alert,
            'candidates_count' => count($candidates),
            'eligible_count' => count($eligible),
        ];
    }

    /**
     * Stage 2: Simple Additive Weighting (SAW) Scoring & Ranking (#48).
     *
     * Formulas:
     * - Cost criterion (price, lead_time_days): r = min(x_j) / x_ij
     * - Benefit criterion (fulfillment_rate):   r = x_ij / max(x_j)
     * - Composite score: S_i = w_p * r_price + w_l * r_leadtime + w_r * r_fulfillment
     * - Rank descending by S_i; break ties by lower price.
     *
     * @param  array<int, array<string, mixed>>  $eligibleSuppliers
     * @param  array<string, float>  $weights
     * @return array<int, array<string, mixed>>
     */
    public function scoreAndRankSuppliers(array $eligibleSuppliers, array $weights): array
    {
        if (empty($eligibleSuppliers)) {
            return [];
        }

        $wp = (float) ($weights['price'] ?? 0.70);
        $wl = (float) ($weights['speed'] ?? $weights['lead_time'] ?? 0.10);
        $wr = (float) ($weights['reliability'] ?? $weights['fulfillment'] ?? 0.20);

        $prices = array_map(fn ($s) => max(1e-9, (float) ($s['price'] ?? $s['supply_price'] ?? 0.0)), $eligibleSuppliers);
        $leadTimes = array_map(fn ($s) => max(1e-9, (float) ($s['lead_time_days'] ?? 1.0)), $eligibleSuppliers);
        $fulfillments = array_map(fn ($s) => max(0.0, (float) ($s['fulfillment_rate'] ?? 0.90)), $eligibleSuppliers);

        $minPrice = min($prices);
        $minLeadTime = min($leadTimes);
        $maxFulfillment = max($fulfillments);

        $scored = [];
        foreach ($eligibleSuppliers as $s) {
            $price = max(1e-9, (float) ($s['price'] ?? $s['supply_price'] ?? 0.0));
            $leadTime = max(1e-9, (float) ($s['lead_time_days'] ?? 1.0));
            $fulfillment = max(0.0, (float) ($s['fulfillment_rate'] ?? 0.90));

            $rPrice = $minPrice / $price;
            $rLeadTime = $minLeadTime / $leadTime;
            $rFulfillment = $maxFulfillment > 0 ? ($fulfillment / $maxFulfillment) : 1.0;

            $rawScore = ($wp * $rPrice) + ($wl * $rLeadTime) + ($wr * $rFulfillment);

            $scored[] = array_merge($s, [
                'normalized' => [
                    'r_price' => round($rPrice, 4),
                    'r_leadtime' => round($rLeadTime, 4),
                    'r_fulfillment' => round($rFulfillment, 4),
                ],
                'weighted_components' => [
                    'price_component' => round($wp * $rPrice, 4),
                    'speed_component' => round($wl * $rLeadTime, 4),
                    'reliability_component' => round($wr * $rFulfillment, 4),
                ],
                'score' => round($rawScore, 3),
                'score_precise' => round($rawScore, 6),
            ]);
        }

        // Sort descending by score; break ties by lower price, then shorter lead time, then supplier_id
        usort($scored, function (array $a, array $b): int {
            $scoreCmp = $b['score'] <=> $a['score'];
            if ($scoreCmp !== 0) {
                return $scoreCmp;
            }

            $priceCmp = ((float) $a['price']) <=> ((float) $b['price']);
            if ($priceCmp !== 0) {
                return $priceCmp;
            }

            $leadCmp = ((int) $a['lead_time_days']) <=> ((int) $b['lead_time_days']);
            if ($leadCmp !== 0) {
                return $leadCmp;
            }

            return ((int) ($a['supplier_id'] ?? 0)) <=> ((int) ($b['supplier_id'] ?? 0));
        });

        foreach ($scored as $idx => &$item) {
            $item['rank'] = $idx + 1;
            $item['is_recommended'] = ($idx === 0);
        }
        unset($item);

        return $scored;
    }

    /**
     * Resolve candidate suppliers for a product from product_suppliers.
     *
     * @return array<int, array<string, mixed>>
     */
    public function resolveProductCandidates(int $productId, float $fallbackPrice = 0.0): array
    {
        $records = ProductSupplier::query()
            ->with('supplier')
            ->where('product_id', $productId)
            ->get();

        $candidates = [];
        foreach ($records as $record) {
            $fulfillmentRate = $record->fulfillment_rate;
            if ($fulfillmentRate === null) {
                $fulfillmentRate = $this->fulfillmentCalculator->calculateForProductSupplier(
                    $productId,
                    (int) $record->supplier_id
                );
            }

            $candidates[] = [
                'supplier_id' => (int) $record->supplier_id,
                'supplier_name' => $record->supplier?->name ?? ('Supplier #' . $record->supplier_id),
                'status' => strtolower((string) ($record->supplier?->status ?? 'inactive')),
                'price' => (float) ($record->supply_price ?: ($fallbackPrice > 0 ? $fallbackPrice : 1.0)),
                'lead_time_days' => (int) ($record->lead_time_days ?? 7),
                'moq' => (int) ($record->min_order_qty ?? 0),
                'order_multiple' => max(1, (int) ($record->order_multiple ?? 1)),
                'fulfillment_rate' => round((float) $fulfillmentRate, 4),
                'is_preferred' => (bool) $record->is_preferred,
            ];
        }

        return $candidates;
    }

    /**
     * Resolve max acceptable lead days from system_settings (default 14).
     */
    public function resolveMaxAcceptableLeadDays(): int
    {
        $val = SystemSetting::query()
            ->where('setting_key', 'supplier_max_acceptable_lead_days')
            ->value('setting_value');

        return $val !== null && (int) $val > 0 ? (int) $val : 14;
    }

    /**
     * Resolve panic ratio of ROP from system_settings (default 0.10).
     */
    public function resolvePanicRatio(): float
    {
        $val = SystemSetting::query()
            ->where('setting_key', 'supplier_panic_ratio_of_rop')
            ->value('setting_value');

        if ($val !== null && is_numeric($val)) {
            $num = (float) $val;
            return $num > 1.0 ? ($num / 100.0) : max(0.0, $num);
        }

        return 0.10;
    }

    /**
     * Resolve configured weights for a given mode from system_settings if present.
     *
     * @return array{price: float, speed: float, reliability: float}
     */
    protected function resolveConfiguredWeightsForMode(string $mode): array
    {
        $settingKey = 'supplier_weights_' . strtolower($mode);
        $raw = SystemSetting::query()
            ->where('setting_key', $settingKey)
            ->value('setting_value');

        $defaults = self::DEFAULT_WEIGHT_MODES[$mode] ?? self::DEFAULT_WEIGHT_MODES['NORMAL'];

        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return [
                    'price' => (float) ($decoded['price'] ?? $defaults['price']),
                    'speed' => (float) ($decoded['speed'] ?? $decoded['lead_time'] ?? $defaults['speed']),
                    'reliability' => (float) ($decoded['reliability'] ?? $decoded['fulfillment'] ?? $defaults['reliability']),
                ];
            }
        }

        return $defaults;
    }

    /**
     * Synchronize an AI recommendation alert when no supplier passes Stage 1 eligibility.
     *
     * @param  array<string, mixed>  $evaluation
     */
    protected function syncIneligibleAlert(Product $product, Branch $branch, array $evaluation): void
    {
        $alert = $evaluation['alert'] ?? null;

        if ($alert === null) {
            AiRecommendation::query()
                ->where('type', 'SUPPLIER_INELIGIBLE')
                ->where('related_entity_type', 'Product')
                ->where('related_entity_id', $product->id)
                ->where('status', 'open')
                ->update(['status' => 'dismissed']);

            return;
        }

        $text = sprintf(
            'No eligible suppliers available for %s at %s (Required Qty: %s, Max Acceptable Lead Time: %d days). Manual procurement review required.',
            $product->name,
            $branch->name,
            number_format((float) ($evaluation['required_quantity'] ?? 0), 0),
            (int) ($evaluation['max_acceptable_lead_days'] ?? 14)
        );

        AiRecommendation::query()->updateOrCreate(
            [
                'type' => 'SUPPLIER_INELIGIBLE',
                'related_entity_type' => 'Product',
                'related_entity_id' => $product->id,
            ],
            [
                'recommendation_text' => $text,
                'priority' => 'high',
                'priority_score' => 0.90,
                'status' => 'open',
                'factors' => [
                    'product_id' => $product->id,
                    'branch_id' => $branch->id,
                    'required_quantity' => $evaluation['required_quantity'] ?? 0,
                    'max_acceptable_lead_days' => $evaluation['max_acceptable_lead_days'] ?? 14,
                    'ineligible' => $evaluation['ineligible'] ?? [],
                ],
                'generated_at' => now(),
            ]
        );
    }
}

