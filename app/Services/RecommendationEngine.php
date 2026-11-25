<?php

namespace App\Services;

use App\Models\AiRecommendation;
use App\Models\Branch;
use App\Models\CycleCount;
use App\Models\MarkdownRecommendation;
use App\Models\Product;
use App\Models\SalesAnomaly;
use App\Models\StockPrediction;
use Illuminate\Support\Collection;

/**
 * RecommendationEngine (Sub-Feature #57)
 *
 * Rule-Based Expert System (Forward-Chaining IF-THEN rules) that synthesizes:
 *  1. Stock-Out Risk & Dynamic Replenishment (#44, #45) + Supplier SAW Scoring (#48, #68)
 *  2. Slow-Moving & Overstock Alerts (#50, #51)
 *  3. Zero-Waste Expiring Batch Markdown Recommendations (#69)
 *  4. Statistical Sales Anomaly Detection (#52)
 *  5. Phantom Inventory Detection & Cycle-Count Screening (#70)
 *
 * Produces explainable AI recommendations with structured `factors` JSON
 * ({ rule, inputs, rationale, action }) and weighted `priority_score` (#58).
 */
class RecommendationEngine
{
    public function __construct(
        protected PriorityScorer $priorityScorer,
        protected SupplierEvaluationService $supplierEvaluationService,
        protected OverstockAlertService $overstockAlertService
    ) {}

    /**
     * Evaluate forward-chaining IF-THEN rules on an in-memory facts payload.
     * Ideal for deterministic unit testing and dry-run evaluations.
     *
     * @param  array<string, mixed>  $facts
     * @return array<int, array<string, mixed>>
     */
    public function evaluateForwardChainingRules(array $facts): array
    {
        $generated = [];

        // RULE 1: IF stock risk is HIGH/MEDIUM or reorder quantity > 0 -> THEN recommend reorder with top SAW supplier
        if (!empty($facts['stock_risk']) && is_array($facts['stock_risk'])) {
            $sr = $facts['stock_risk'];
            $riskLevel = strtoupper((string) ($sr['risk_level'] ?? 'LOW'));
            $reorderQty = (int) ($sr['recommended_reorder_qty'] ?? $sr['final_quantity'] ?? 0);
            $currentStock = (float) ($sr['current_stock'] ?? 0.0);
            $rop = (float) ($sr['reorder_point'] ?? 0.0);

            if (in_array($riskLevel, ['HIGH', 'MEDIUM', 'CRITICAL'], true) || $reorderQty > 0 || ($rop > 0 && $currentStock <= $rop)) {
                $supplier = $facts['supplier_evaluation']['recommended_supplier']
                    ?? $sr['supplier']
                    ?? ['supplier_id' => $sr['supplier_id'] ?? null, 'supplier_name' => $sr['supplier_name'] ?? 'Preferred Supplier', 'score' => $sr['supplier_score'] ?? 0.90, 'price' => $sr['unit_price'] ?? 10.0];

                $weightMode = $facts['supplier_evaluation']['mode']
                    ?? ($rop > 0 && $currentStock <= 0.10 * $rop ? 'PANIC' : ($currentStock <= $rop ? 'WARNING' : 'NORMAL'));

                $unitPrice = (float) ($supplier['price'] ?? $sr['unit_price'] ?? 10.0);
                $valueAtStake = max($reorderQty, (int) ceil(max(1.0, $rop - $currentStock))) * max(1.0, $unitPrice);
                $leadTime = (int) ($sr['lead_time_days'] ?? $supplier['lead_time_days'] ?? 5);

                $scoring = $this->priorityScorer->score([
                    'risk_level' => $weightMode === 'PANIC' ? 'CRITICAL' : $riskLevel,
                    'value_at_stake' => $valueAtStake,
                    'days_remaining' => $riskLevel === 'HIGH' ? max(0, $leadTime - 2) : $leadTime,
                    'horizon_days' => 14,
                ]);

                $productName = (string) ($sr['product_name'] ?? 'Product #' . ($sr['product_id'] ?? 1));
                $rationale = sprintf(
                    'IF stock risk is %s (stock %.0f vs ROP %.1f) THEN reorder %d units from top SAW supplier %s (mode: %s, score: %.3f).',
                    $riskLevel,
                    $currentStock,
                    $rop,
                    $reorderQty,
                    (string) ($supplier['supplier_name'] ?? 'Preferred Supplier'),
                    $weightMode,
                    (float) ($supplier['score'] ?? 0.0)
                );

                $generated[] = [
                    'type' => 'reorder',
                    'related_entity_type' => 'Product',
                    'related_entity_id' => (int) ($sr['product_id'] ?? 0),
                    'recommendation_text' => $rationale,
                    'priority' => $scoring['priority'],
                    'priority_score' => $scoring['priority_score'],
                    'status' => 'open',
                    'factors' => [
                        'rule' => 'IF_STOCK_RISK_THEN_REORDER_WITH_SAW_SUPPLIER',
                        'branch_id' => $sr['branch_id'] ?? null,
                        'product_id' => $sr['product_id'] ?? null,
                        'product_name' => $productName,
                        'inputs' => [
                            'risk_level' => $riskLevel,
                            'current_stock' => $currentStock,
                            'reorder_point' => $rop,
                            'safety_stock' => (float) ($sr['safety_stock'] ?? 0.0),
                            'recommended_reorder_qty' => $reorderQty,
                            'lead_time_days' => $leadTime,
                            'supplier_mode' => $weightMode,
                            'recommended_supplier' => $supplier,
                            'value_at_stake' => round($valueAtStake, 2),
                        ],
                        'priority_breakdown' => $scoring,
                        'rationale' => $rationale,
                        'action' => 'draft_purchase_order',
                    ],
                ];
            }
        }

        // RULE 2: IF overstock or slow-moving -> THEN recommend inventory reduction / promotional push
        if (!empty($facts['overstock']) && is_array($facts['overstock'])) {
            $ov = $facts['overstock'];
            $isOverstock = (bool) ($ov['is_overstock'] ?? false);
            $isSlow = (bool) ($ov['is_slow_moving'] ?? false);

            if ($isOverstock || $isSlow) {
                $surplusUnits = (float) ($ov['overstock_units'] ?? max(0.0, (float) ($ov['available_stock'] ?? 0) - (float) ($ov['forecast_demand_horizon'] ?? 0)));
                $unitCost = (float) ($ov['unit_cost'] ?? $ov['cost_price'] ?? 8.0);
                $valueAtStake = max(10.0, $surplusUnits * max(1.0, $unitCost));
                $severity = strtoupper((string) ($ov['severity'] ?? ($isOverstock && $isSlow ? 'HIGH' : 'MEDIUM')));

                $scoring = $this->priorityScorer->score([
                    'risk_level' => $severity,
                    'value_at_stake' => $valueAtStake,
                    'urgency_score' => $isOverstock && $isSlow ? 70.0 : 50.0,
                ]);

                $productName = (string) ($ov['product_name'] ?? 'Product #' . ($ov['product_id'] ?? 1));
                $rationale = sprintf(
                    'IF product %s has %s (on-hand: %.0f, 14d forecast: %.1f, sell-through: %.1f%%) THEN pause replenishment and run promotional campaign.',
                    $productName,
                    $isOverstock && $isSlow ? 'Overstock & Slow-Moving velocity' : ($isOverstock ? 'Overstock surplus' : 'Slow-Moving sell-through'),
                    (float) ($ov['available_stock'] ?? 0),
                    (float) ($ov['forecast_demand_horizon'] ?? 0),
                    ((float) ($ov['sell_through_rate'] ?? 0)) * 100.0
                );

                $generated[] = [
                    'type' => 'overstock',
                    'related_entity_type' => 'Product',
                    'related_entity_id' => (int) ($ov['product_id'] ?? 0),
                    'recommendation_text' => $rationale,
                    'priority' => $scoring['priority'],
                    'priority_score' => $scoring['priority_score'],
                    'status' => 'open',
                    'factors' => [
                        'rule' => 'IF_OVERSTOCK_OR_SLOW_MOVING_THEN_OPTIMIZE_STOCK',
                        'branch_id' => $ov['branch_id'] ?? null,
                        'product_id' => $ov['product_id'] ?? null,
                        'inputs' => [
                            'available_stock' => (float) ($ov['available_stock'] ?? 0),
                            'forecast_demand_horizon' => (float) ($ov['forecast_demand_horizon'] ?? 0),
                            'overstock_units' => round($surplusUnits, 2),
                            'sell_through_rate' => (float) ($ov['sell_through_rate'] ?? 0),
                            'is_overstock' => $isOverstock,
                            'is_slow_moving' => $isSlow,
                            'value_at_stake' => round($valueAtStake, 2),
                        ],
                        'priority_breakdown' => $scoring,
                        'rationale' => $rationale,
                        'action' => 'review_overstock_or_promote',
                    ],
                ];
            }
        }

        // RULE 3: IF expiring batch has surplus > 0 -> THEN recommend Zero-Waste Markdown
        if (!empty($facts['markdown']) && is_array($facts['markdown'])) {
            $md = $facts['markdown'];
            $surplusQty = (float) ($md['surplus_qty'] ?? 0.0);
            $markdownPct = (float) ($md['suggested_markdown_pct'] ?? $md['markdown_pct'] ?? 0.0);
            $daysRemaining = (int) ($md['days_remaining'] ?? 0);

            if ($surplusQty > 0 && $markdownPct > 0 && $daysRemaining > 0) {
                $unitPrice = (float) ($md['selling_price'] ?? $md['unit_cost'] ?? 10.0);
                $valueAtStake = $surplusQty * max(1.0, $unitPrice);

                $scoring = $this->priorityScorer->score([
                    'risk_level' => $daysRemaining <= 5 ? 'HIGH' : 'MEDIUM',
                    'value_at_stake' => $valueAtStake,
                    'days_remaining' => $daysRemaining,
                    'horizon_days' => (int) ($md['horizon_days'] ?? 14),
                ]);

                $batchNo = (string) ($md['batch_no'] ?? 'Batch #' . ($md['batch_id'] ?? 1));
                $rationale = sprintf(
                    'IF batch %s has %.1f surplus units with %d days remaining before expiry THEN apply %.0f%% zero-waste markdown (authority: %s).',
                    $batchNo,
                    $surplusQty,
                    $daysRemaining,
                    $markdownPct,
                    (string) ($md['approval_authority'] ?? 'Branch Manager')
                );

                $generated[] = [
                    'type' => 'markdown',
                    'related_entity_type' => 'MarkdownRecommendation',
                    'related_entity_id' => (int) ($md['id'] ?? $md['batch_id'] ?? 0),
                    'recommendation_text' => $rationale,
                    'priority' => $scoring['priority'],
                    'priority_score' => $scoring['priority_score'],
                    'status' => 'open',
                    'factors' => [
                        'rule' => 'IF_EXPIRING_BATCH_SURPLUS_THEN_MARKDOWN',
                        'branch_id' => $md['branch_id'] ?? null,
                        'product_id' => $md['product_id'] ?? null,
                        'batch_id' => $md['batch_id'] ?? null,
                        'markdown_recommendation_id' => $md['id'] ?? null,
                        'inputs' => [
                            'batch_no' => $batchNo,
                            'days_remaining' => $daysRemaining,
                            'available_qty' => (int) ($md['available_qty'] ?? 0),
                            'projected_sales' => (float) ($md['projected_sales'] ?? 0.0),
                            'surplus_qty' => $surplusQty,
                            'suggested_markdown_pct' => $markdownPct,
                            'approval_authority' => $md['approval_authority'] ?? 'Branch Manager',
                            'value_at_stake' => round($valueAtStake, 2),
                        ],
                        'priority_breakdown' => $scoring,
                        'rationale' => $rationale,
                        'action' => 'approve_batch_markdown',
                    ],
                ];
            }
        }

        // RULE 4: IF statistical sales anomaly detected -> THEN recommend anomaly investigation
        if (!empty($facts['anomaly']) && is_array($facts['anomaly'])) {
            $an = $facts['anomaly'];
            $devScore = abs((float) ($an['deviation_score'] ?? 0.0));
            $actualVal = (float) ($an['actual_value'] ?? 0.0);
            $expectedVal = (float) ($an['expected_value'] ?? 0.0);

            if ($devScore > 0 || abs($actualVal - $expectedVal) > 0) {
                $unitPrice = (float) ($an['selling_price'] ?? 10.0);
                $valueAtStake = abs($actualVal - $expectedVal) * max(1.0, $unitPrice);

                $scoring = $this->priorityScorer->score([
                    'risk_level' => $devScore >= 5.0 ? 'HIGH' : 'MEDIUM',
                    'value_at_stake' => $valueAtStake,
                    'urgency_score' => min(100.0, $devScore * 18.0),
                ]);

                $rationale = sprintf(
                    'IF daily sales (%.0f units) deviate from baseline median (%.1f units) with Modified Z-score %.2f THEN review context (discounts, stock-outs, events). Alert is a statistical signal, not proof.',
                    $actualVal,
                    $expectedVal,
                    (float) ($an['deviation_score'] ?? 0.0)
                );

                $generated[] = [
                    'type' => 'anomaly',
                    'related_entity_type' => 'SalesAnomaly',
                    'related_entity_id' => (int) ($an['id'] ?? $an['product_id'] ?? 0),
                    'recommendation_text' => $rationale,
                    'priority' => $scoring['priority'],
                    'priority_score' => $scoring['priority_score'],
                    'status' => 'open',
                    'factors' => [
                        'rule' => 'IF_SALES_ANOMALY_DETECTED_THEN_INVESTIGATE',
                        'branch_id' => $an['branch_id'] ?? null,
                        'product_id' => $an['product_id'] ?? null,
                        'anomaly_id' => $an['id'] ?? null,
                        'inputs' => [
                            'detected_date' => $an['detected_date'] ?? now()->toDateString(),
                            'expected_value' => $expectedVal,
                            'actual_value' => $actualVal,
                            'deviation_score' => (float) ($an['deviation_score'] ?? 0.0),
                            'value_at_stake' => round($valueAtStake, 2),
                        ],
                        'priority_breakdown' => $scoring,
                        'rationale' => $rationale,
                        'action' => 'review_sales_anomaly',
                    ],
                ];
            }
        }

        // RULE 5: IF phantom inventory suspected -> THEN recommend physical cycle count
        if (!empty($facts['phantom']) && is_array($facts['phantom'])) {
            $ph = $facts['phantom'];
            $zScore = (float) ($ph['z_score'] ?? 0.0);
            $sysQty = (int) ($ph['system_quantity'] ?? $ph['recorded_stock'] ?? 0);

            if ($sysQty > 0 && $zScore <= -2.0) {
                $unitCost = (float) ($ph['cost_price'] ?? 10.0);
                $valueAtStake = $sysQty * max(1.0, $unitCost);

                $scoring = $this->priorityScorer->score([
                    'risk_level' => abs($zScore) >= 3.0 ? 'HIGH' : 'MEDIUM',
                    'value_at_stake' => $valueAtStake,
                    'urgency_score' => min(100.0, abs($zScore) * 25.0),
                ]);

                $rationale = sprintf(
                    'IF recorded stock is %d units but low-sales z-score (%.2f <= -2.0) persisted vs baseline mean %.1f THEN dispatch cycle-count task (screening signal only; never auto-adjust stock).',
                    $sysQty,
                    $zScore,
                    (float) ($ph['baseline_mean'] ?? 0.0)
                );

                $generated[] = [
                    'type' => 'phantom',
                    'related_entity_type' => 'CycleCount',
                    'related_entity_id' => (int) ($ph['id'] ?? $ph['product_id'] ?? 0),
                    'recommendation_text' => $rationale,
                    'priority' => $scoring['priority'],
                    'priority_score' => $scoring['priority_score'],
                    'status' => 'open',
                    'factors' => [
                        'rule' => 'IF_PHANTOM_INVENTORY_SUSPECTED_THEN_CYCLE_COUNT',
                        'branch_id' => $ph['branch_id'] ?? null,
                        'product_id' => $ph['product_id'] ?? null,
                        'cycle_count_id' => $ph['id'] ?? null,
                        'inputs' => [
                            'system_quantity' => $sysQty,
                            'baseline_mean' => (float) ($ph['baseline_mean'] ?? 0.0),
                            'baseline_std' => (float) ($ph['baseline_std'] ?? 0.0),
                            'z_score' => $zScore,
                            'value_at_stake' => round($valueAtStake, 2),
                        ],
                        'priority_breakdown' => $scoring,
                        'rationale' => $rationale,
                        'action' => 'perform_cycle_count',
                    ],
                ];
            }
        }

        return $this->priorityScorer->rankQueue($generated)->all();
    }

    /**
     * Run the forward-chaining recommendation engine across database findings for a branch
     * and persist priority-scored recommendations in `ai_recommendations`.
     *
     * @return Collection<int, AiRecommendation>
     */
    public function runForBranch(?int $branchId = null): Collection
    {
        $saved = collect();

        // 1. Stock-Out Risk & Replenishment + SAW Supplier Evaluation
        $predictions = StockPrediction::query()
            ->with(['product', 'branch'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->get();

        foreach ($predictions as $pred) {
            if (!$pred->product) {
                continue;
            }
            $risk = strtoupper((string) $pred->risk_level);
            $qty = (int) $pred->recommended_reorder_qty;
            if (!in_array($risk, ['HIGH', 'MEDIUM'], true) && $qty <= 0) {
                continue;
            }

            $supplierEval = $this->supplierEvaluationService->evaluate($pred->product_id, $pred->branch_id, [
                'stock' => (float) $pred->current_stock,
                'rop' => (float) ($pred->reorder_point ?? 0.0),
                'required_quantity' => max(1.0, (float) $qty),
            ]);

            $rules = $this->evaluateForwardChainingRules([
                'stock_risk' => [
                    'product_id' => $pred->product_id,
                    'product_name' => $pred->product->name,
                    'branch_id' => $pred->branch_id,
                    'risk_level' => $risk,
                    'current_stock' => (float) $pred->current_stock,
                    'reorder_point' => (float) ($pred->reorder_point ?? 0.0),
                    'safety_stock' => (float) ($pred->safety_stock ?? 0.0),
                    'recommended_reorder_qty' => $qty,
                    'lead_time_days' => (int) ($pred->lead_time_days ?? 5),
                    'unit_price' => (float) ($pred->product->cost_price ?: $pred->product->selling_price),
                ],
                'supplier_evaluation' => $supplierEval,
            ]);

            foreach ($rules as $ruleData) {
                $saved->push($this->upsertRecommendation($ruleData, $pred->branch_id));
            }
        }

        // 2. Expiring Batch Markdown Recommendations (#69)
        $markdowns = MarkdownRecommendation::query()
            ->with(['product', 'batch'])
            ->where('status', 'pending')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->get();

        foreach ($markdowns as $md) {
            $rules = $this->evaluateForwardChainingRules([
                'markdown' => [
                    'id' => $md->id,
                    'batch_id' => $md->batch_id,
                    'batch_no' => $md->batch?->batch_no ?? "Batch #{$md->batch_id}",
                    'product_id' => $md->product_id,
                    'branch_id' => $md->branch_id,
                    'days_remaining' => (int) $md->days_remaining,
                    'available_qty' => (int) $md->available_qty,
                    'projected_sales' => (float) $md->projected_sales,
                    'surplus_qty' => (float) $md->surplus_qty,
                    'suggested_markdown_pct' => (float) $md->suggested_markdown_pct,
                    'approval_authority' => $md->approval_authority,
                    'selling_price' => (float) ($md->product?->selling_price ?? 10.0),
                ],
            ]);

            foreach ($rules as $ruleData) {
                $saved->push($this->upsertRecommendation($ruleData, $md->branch_id));
            }
        }

        // 3. Statistical Sales Anomalies (#52)
        $anomalies = SalesAnomaly::query()
            ->with('product')
            ->where('status', 'pending')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->get();

        foreach ($anomalies as $an) {
            $rules = $this->evaluateForwardChainingRules([
                'anomaly' => [
                    'id' => $an->id,
                    'product_id' => $an->product_id,
                    'branch_id' => $an->branch_id,
                    'detected_date' => $an->detected_date?->format('Y-m-d'),
                    'expected_value' => (float) $an->expected_value,
                    'actual_value' => (float) $an->actual_value,
                    'deviation_score' => (float) $an->deviation_score,
                    'selling_price' => (float) ($an->product?->selling_price ?? 10.0),
                ],
            ]);

            foreach ($rules as $ruleData) {
                $saved->push($this->upsertRecommendation($ruleData, $an->branch_id));
            }
        }

        // 4. Phantom Inventory Cycle Counts (#70)
        $cycleCounts = CycleCount::query()
            ->with('product')
            ->where('status', 'open')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->get();

        foreach ($cycleCounts as $cc) {
            $rules = $this->evaluateForwardChainingRules([
                'phantom' => [
                    'id' => $cc->id,
                    'product_id' => $cc->product_id,
                    'branch_id' => $cc->branch_id,
                    'system_quantity' => (int) $cc->system_quantity,
                    'baseline_mean' => (float) ($cc->baseline_mean ?? 0.0),
                    'baseline_std' => (float) ($cc->baseline_std ?? 0.0),
                    'z_score' => (float) ($cc->z_score ?? -2.5),
                    'cost_price' => (float) ($cc->product?->cost_price ?? 10.0),
                ],
            ]);

            foreach ($rules as $ruleData) {
                $saved->push($this->upsertRecommendation($ruleData, $cc->branch_id));
            }
        }

        // 5. Re-score any existing open recommendations (such as OVERSTOCK_ALERT) so the entire queue has unified priority scores
        $existingOpen = AiRecommendation::query()
            ->where('status', 'open')
            ->get();

        foreach ($existingOpen as $rec) {
            $factors = is_array($rec->factors) ? $rec->factors : [];
            if ($branchId !== null && isset($factors['branch_id']) && (int) $factors['branch_id'] !== $branchId) {
                continue;
            }
            if ((float) $rec->priority_score <= 1.0) {
                $rescored = $this->priorityScorer->score([
                    'risk_level' => strtoupper((string) ($rec->priority ?: 'MEDIUM')),
                    'value_at_stake' => (float) ($factors['inputs']['value_at_stake'] ?? $factors['overstock_units'] ?? 150.0),
                ]);
                $rec->update([
                    'priority_score' => $rescored['priority_score'],
                    'priority' => $rescored['priority'],
                ]);
            }
            if (!$saved->contains('id', $rec->id)) {
                $saved->push($rec->fresh());
            }
        }

        return $this->priorityScorer->rankQueue($saved);
    }

    /**
     * Upsert an AiRecommendation row scoped by type, entity, and branch.
     *
     * @param  array<string, mixed>  $ruleData
     */
    protected function upsertRecommendation(array $ruleData, ?int $branchId = null): AiRecommendation
    {
        $existing = AiRecommendation::query()
            ->where('type', $ruleData['type'])
            ->where('related_entity_type', $ruleData['related_entity_type'])
            ->where('related_entity_id', $ruleData['related_entity_id'])
            ->get()
            ->first(function (AiRecommendation $r) use ($branchId) {
                if ($branchId === null) {
                    return true;
                }
                $f = is_array($r->factors) ? $r->factors : [];
                return !isset($f['branch_id']) || (int) $f['branch_id'] === $branchId;
            });

        if ($existing) {
            $existing->update([
                'recommendation_text' => $ruleData['recommendation_text'],
                'priority' => $ruleData['priority'],
                'priority_score' => $ruleData['priority_score'],
                'factors' => $ruleData['factors'],
                'status' => $existing->status === 'open' ? 'open' : $ruleData['status'],
                'generated_at' => now(),
            ]);
            return $existing->fresh();
        }

        return AiRecommendation::create([
            'type' => $ruleData['type'],
            'related_entity_type' => $ruleData['related_entity_type'],
            'related_entity_id' => $ruleData['related_entity_id'],
            'recommendation_text' => $ruleData['recommendation_text'],
            'priority' => $ruleData['priority'],
            'priority_score' => $ruleData['priority_score'],
            'factors' => $ruleData['factors'],
            'status' => 'open',
            'generated_at' => now(),
        ]);
    }
}
