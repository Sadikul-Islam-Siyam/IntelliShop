<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiRecommendation;
use App\Models\Branch;
use App\Models\CycleCount;
use App\Models\MarkdownRecommendation;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\SalesAnomaly;
use App\Models\StockPrediction;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MarkdownService;
use App\Services\ReorderCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * RecommendationReviewController (Sub-Feature #46)
 *
 * Handles manager review (approve / modify / reject) of dynamic replenishment
 * and reorder recommendations:
 *  - Approval or Modification drafts a PurchaseOrder with `is_auto_generated = true`
 *    (never autonomously places an external order without manager review).
 *  - Rejection records the manager's decision and rationale without drafting a PO.
 *  - Exposes key calculation inputs (SS, ROP, Target Stock, Lead Time, Available Stock)
 *    and logs every review action to `audit_logs`.
 */
class RecommendationReviewController extends Controller
{
    public function __construct(
        protected ReorderCalculator $reorderCalculator
    ) {}

    /**
     * POST /api/v1/recommendations/{id}/review
     * Generic review action (approve, modify, reject) across all recommendation types (#59).
     */
    public function review(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'action' => 'required|string|in:approve,approved,modify,modified,reject,rejected',
            'notes' => 'nullable|string|max:500',
            'reason' => 'nullable|string|max:500',
            'quantity' => 'nullable|integer|min:0',
            'modified_quantity' => 'nullable|integer|min:0',
            'discount_pct' => 'nullable|numeric|min:1|max:90',
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
            'unit_price' => 'nullable|numeric|min:0',
        ]);

        $rawAction = strtolower($validated['action']);
        $normalizedAction = match ($rawAction) {
            'approve', 'approved' => 'approved',
            'modify', 'modified' => 'modified',
            'reject', 'rejected' => 'rejected',
        };

        $recommendation = AiRecommendation::findOrFail($id);
        $user = $request->user() ?: User::query()->first();
        $notes = $validated['notes'] ?? $validated['reason'] ?? null;
        $resultingEntity = null;

        $type = strtolower((string) $recommendation->type);
        $factors = is_array($recommendation->factors) ? $recommendation->factors : [];

        if (in_array($type, ['reorder', 'reorder_recommendation', 'stock_out_risk'], true)) {
            return $this->reviewReorder($request, $id);
        }

        DB::transaction(function () use (
            $recommendation,
            $normalizedAction,
            $type,
            $factors,
            $user,
            $notes,
            $validated,
            &$resultingEntity
        ) {
            if ($type === 'markdown') {
                $markdownRec = null;
                if (!empty($factors['markdown_id'])) {
                    $markdownRec = MarkdownRecommendation::find($factors['markdown_id']);
                }
                if (!$markdownRec && !empty($factors['inputs']['batch_id'])) {
                    $markdownRec = MarkdownRecommendation::where('batch_id', $factors['inputs']['batch_id'])->latest('id')->first();
                }
                if (!$markdownRec && $recommendation->related_entity_type === 'Product') {
                    $markdownRec = MarkdownRecommendation::where('product_id', $recommendation->related_entity_id)->latest('id')->first();
                }

                if ($normalizedAction === 'rejected') {
                    if ($markdownRec) {
                        $markdownRec->update(['status' => 'rejected', 'reviewed_by' => $user?->id, 'reviewed_at' => now()]);
                    }
                } else {
                    if ($markdownRec) {
                        if (isset($validated['discount_pct'])) {
                            $markdownRec->update(['suggested_markdown_pct' => (float) $validated['discount_pct']]);
                        }
                        if ($user) {
                            $discount = app(MarkdownService::class)->approveRecommendation($markdownRec, $user);
                            $resultingEntity = ['type' => 'discount', 'id' => $discount->id, 'discount_pct' => $discount->value];
                        }
                    } else {
                        $batchId = $factors['inputs']['batch_id'] ?? null;
                        $productId = $recommendation->related_entity_id ?: ($factors['inputs']['product_id'] ?? null);
                        $branchId = $factors['branch_id'] ?? 1;
                        $discountPct = (float) ($validated['discount_pct'] ?? $factors['inputs']['suggested_discount_pct'] ?? 20.0);

                        $newRec = MarkdownRecommendation::create([
                            'batch_id' => $batchId,
                            'product_id' => $productId,
                            'branch_id' => $branchId,
                            'baseline_days' => 30,
                            'avg_daily_sales' => 5.0,
                            'days_remaining' => 3,
                            'available_qty' => 20,
                            'projected_sales' => 15.0,
                            'surplus_qty' => 5.0,
                            'suggested_markdown_pct' => $discountPct,
                            'max_markdown_pct' => 50.0,
                            'status' => 'approved',
                            'approval_authority' => 'branch_manager',
                            'reviewed_by' => $user?->id,
                            'reviewed_at' => now(),
                            'generated_at' => now(),
                        ]);
                        $resultingEntity = ['type' => 'markdown_recommendation', 'id' => $newRec->id, 'discount_pct' => $discountPct];
                    }
                }
            } elseif (in_array($type, ['phantom', 'phantom_inventory'], true)) {
                $productId = $recommendation->related_entity_id ?: ($factors['product_id'] ?? $factors['inputs']['product_id'] ?? null);
                $branchId = $factors['branch_id'] ?? ($user?->branch_id ?: 1);

                if ($normalizedAction === 'rejected') {
                    $resultingEntity = ['type' => 'cycle_count', 'status' => 'rejected'];
                } else {
                    $systemQty = (int) ($factors['inputs']['current_stock'] ?? $factors['inputs']['quantity_on_hand'] ?? 20);
                    $cycleCount = CycleCount::create([
                        'product_id' => $productId,
                        'branch_id' => $branchId,
                        'trigger_source' => 'recommendation_review',
                        'system_quantity' => $systemQty,
                        'status' => 'pending_count',
                        'assigned_to' => $user?->id,
                    ]);
                    $resultingEntity = ['type' => 'cycle_count', 'id' => $cycleCount->id, 'status' => 'pending_count'];
                }
            } elseif (in_array($type, ['anomaly', 'sales_anomaly'], true)) {
                if (!empty($factors['anomaly_id'])) {
                    $anomaly = SalesAnomaly::find($factors['anomaly_id']);
                    if ($anomaly) {
                        $anomaly->update([
                            'status' => $normalizedAction === 'rejected' ? 'false_positive' : 'reviewed',
                        ]);
                        $resultingEntity = ['type' => 'sales_anomaly', 'id' => $anomaly->id, 'status' => $anomaly->status];
                    }
                }
            }

            $updatedFactors = array_merge($factors, [
                'review_decision' => [
                    'action' => $normalizedAction,
                    'notes' => $notes,
                    'resulting_entity' => $resultingEntity,
                    'reviewed_by' => $user?->id,
                    'reviewed_at' => now()->toIso8601String(),
                ],
            ]);

            $recommendation->update([
                'status' => $normalizedAction,
                'reviewed_by' => $user?->id,
                'factors' => $updatedFactors,
            ]);

            AuditLogger::log(
                $user?->id,
                'recommendation.review',
                'ai_recommendations',
                $recommendation->id,
                [
                    'action' => $normalizedAction,
                    'type' => $type,
                    'notes' => $notes,
                    'resulting_entity' => $resultingEntity,
                ],
                request()->ip()
            );
        });

        return response()->json([
            'message' => "Recommendation #{$recommendation->id} ({$type}) {$normalizedAction} successfully.",
            'data' => [
                'decision' => $normalizedAction,
                'notes' => $notes,
                'recommendation' => $recommendation->fresh(),
                'resulting_entity' => $resultingEntity,
            ],
        ]);
    }

    /**
     * POST /api/v1/reorder/{id}/review
     * Manager approves, modifies, or rejects a reorder recommendation / stock prediction.
     */
    public function reviewReorder(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'action' => 'required|string|in:approve,approved,modify,modified,reject,rejected',
            'quantity' => 'nullable|integer|min:0',
            'modified_quantity' => 'nullable|integer|min:0',
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'unit_price' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
            'reason' => 'nullable|string|max:500',
        ]);

        $rawAction = strtolower($validated['action']);
        $normalizedAction = match ($rawAction) {
            'approve', 'approved' => 'approved',
            'modify', 'modified' => 'modified',
            'reject', 'rejected' => 'rejected',
        };

        // Resolve target prediction, recommendation, and product
        [$prediction, $recommendation, $product, $branchId] = $this->resolveReorderTarget($id, $request);

        $factors = is_array($recommendation?->factors) ? $recommendation->factors : [];
        $inputs = is_array($factors['inputs'] ?? null) ? $factors['inputs'] : $factors;

        $originalQty = (int) ($prediction?->recommended_reorder_qty
            ?: ($inputs['recommended_reorder_qty'] ?? $inputs['final_quantity'] ?? $product?->reorder_quantity ?? 20));

        $approvedQty = (int) ($validated['modified_quantity']
            ?? $validated['quantity']
            ?? ($originalQty > 0 ? $originalQty : 20));

        $notes = $validated['notes'] ?? $validated['reason'] ?? null;

        // Calculation inputs for transparency
        $calculationInputs = [
            'product_id' => $product?->id,
            'product_name' => $product?->name,
            'branch_id' => $branchId,
            'current_stock' => (float) ($prediction?->current_stock ?? $inputs['current_stock'] ?? $inputs['available_stock'] ?? 0.0),
            'predicted_demand' => (float) ($prediction?->predicted_demand ?? $inputs['predicted_demand'] ?? 0.0),
            'safety_stock' => (float) ($prediction?->safety_stock ?? $inputs['safety_stock'] ?? 0.0),
            'reorder_point' => (float) ($prediction?->reorder_point ?? $inputs['reorder_point'] ?? 0.0),
            'target_stock' => (float) ($inputs['target_stock'] ?? (($prediction?->predicted_demand ?? 0) + ($prediction?->safety_stock ?? 0))),
            'lead_time_days' => (int) ($prediction?->lead_time_days ?? $inputs['lead_time_days'] ?? 5),
            'risk_level' => (string) ($prediction?->risk_level ?? $inputs['risk_level'] ?? 'HIGH'),
            'original_recommended_qty' => $originalQty,
            'reviewed_quantity' => $normalizedAction === 'rejected' ? 0 : $approvedQty,
        ];

        $purchaseOrder = null;

        DB::transaction(function () use (
            $request,
            $normalizedAction,
            $approvedQty,
            $originalQty,
            $notes,
            $validated,
            $product,
            $branchId,
            &$prediction,
            &$recommendation,
            &$purchaseOrder,
            $calculationInputs
        ) {
            if (in_array($normalizedAction, ['approved', 'modified'], true) && $approvedQty > 0) {
                $supplierId = $this->resolveSupplierIdForPo($product?->id, $validated['supplier_id'] ?? ($recommendation->factors['supplier_id'] ?? null));
                $unitPrice = isset($validated['unit_price'])
                    ? (float) $validated['unit_price']
                    : $this->resolveUnitPriceForPo($product, $supplierId);

                $leadTime = (int) ($calculationInputs['lead_time_days'] ?: 7);
                $subtotal = round($approvedQty * $unitPrice, 2);

                $purchaseOrder = PurchaseOrder::create([
                    'supplier_id' => $supplierId,
                    'branch_id' => $branchId,
                    'order_date' => now()->toDateString(),
                    'expected_date' => now()->addDays(max(1, $leadTime))->toDateString(),
                    'status' => 'pending',
                    'total_amount' => $subtotal,
                    'created_by' => $request->user()?->id,
                    'is_auto_generated' => true,
                ]);

                if ($product) {
                    PurchaseOrderItem::create([
                        'purchase_order_id' => $purchaseOrder->id,
                        'product_id' => $product->id,
                        'quantity' => $approvedQty,
                        'unit_price' => $unitPrice,
                        'subtotal' => $subtotal,
                    ]);
                }

                if ($prediction && $normalizedAction === 'modified') {
                    $prediction->update([
                        'recommended_reorder_qty' => $approvedQty,
                    ]);
                }
            }

            // Persist review decision in ai_recommendations
            $updatedFactors = array_merge(is_array($recommendation?->factors) ? $recommendation->factors : [], [
                'branch_id' => $branchId,
                'product_id' => $product?->id,
                'calculation_inputs' => $calculationInputs,
                'review_decision' => [
                    'action' => $normalizedAction,
                    'original_quantity' => $originalQty,
                    'final_quantity' => $normalizedAction === 'rejected' ? 0 : $approvedQty,
                    'purchase_order_id' => $purchaseOrder?->id,
                    'notes' => $notes,
                    'reviewed_by' => $request->user()?->id,
                    'reviewed_at' => now()->toIso8601String(),
                ],
            ]);

            if ($recommendation) {
                $recommendation->update([
                    'status' => $normalizedAction,
                    'reviewed_by' => $request->user()?->id,
                    'factors' => $updatedFactors,
                ]);
            } else {
                $recommendation = AiRecommendation::create([
                    'type' => 'reorder',
                    'related_entity_type' => 'Product',
                    'related_entity_id' => $product?->id,
                    'recommendation_text' => sprintf(
                        'Reorder review (%s) for %s at Branch #%d: %d units.',
                        $normalizedAction,
                        $product?->name ?? 'Product',
                        $branchId,
                        $normalizedAction === 'rejected' ? 0 : $approvedQty
                    ),
                    'priority' => strtolower((string) ($calculationInputs['risk_level'] ?: 'high')),
                    'priority_score' => 85.00,
                    'status' => $normalizedAction,
                    'reviewed_by' => $request->user()?->id,
                    'factors' => $updatedFactors,
                    'generated_at' => now(),
                ]);
            }

            AuditLogger::log(
                $request->user()?->id,
                'reorder.review',
                'ai_recommendations',
                $recommendation->id,
                [
                    'action' => $normalizedAction,
                    'product_id' => $product?->id,
                    'branch_id' => $branchId,
                    'original_quantity' => $originalQty,
                    'reviewed_quantity' => $normalizedAction === 'rejected' ? 0 : $approvedQty,
                    'purchase_order_id' => $purchaseOrder?->id,
                    'notes' => $notes,
                ],
                $request->ip()
            );
        });

        $poData = $purchaseOrder ? $purchaseOrder->load('items')->toArray() : null;

        return response()->json([
            'message' => match ($normalizedAction) {
                'approved' => "Reorder recommendation approved. Draft Purchase Order #{$purchaseOrder?->id} created.",
                'modified' => "Reorder recommendation modified to {$approvedQty} units and approved. Draft Purchase Order #{$purchaseOrder?->id} created.",
                'rejected' => 'Reorder recommendation rejected and logged.',
            },
            'data' => [
                'decision' => $normalizedAction,
                'notes' => $notes,
                'calculation_inputs' => $calculationInputs,
                'purchase_order' => $poData,
                'recommendation' => $recommendation?->fresh(),
                'prediction' => $prediction?->fresh(),
            ],
        ]);
    }

    /**
     * Resolve target entities from {id} (supports StockPrediction ID, AiRecommendation ID, or Product ID).
     *
     * @return array{0: StockPrediction|null, 1: AiRecommendation|null, 2: Product|null, 3: int}
     */
    protected function resolveReorderTarget(int $id, Request $request): array
    {
        $branchId = (int) ($request->input('branch_id')
            ?: $request->user()?->branch_id
            ?: (Branch::query()->value('id') ?? 1));

        // 1. Check AiRecommendation by ID
        $rec = AiRecommendation::find($id);
        if ($rec && in_array($rec->type, ['reorder', 'REORDER_RECOMMENDATION', 'STOCK_OUT_RISK'], true)) {
            $productId = (int) ($rec->related_entity_id ?: ($rec->factors['product_id'] ?? 0));
            $bId = (int) ($request->input('branch_id') ?: ($rec->factors['branch_id'] ?? $branchId));
            $product = Product::find($productId);
            $prediction = StockPrediction::query()
                ->where('product_id', $productId)
                ->where('branch_id', $bId)
                ->first();

            return [$prediction, $rec, $product, $bId];
        }

        // 2. Check StockPrediction by ID
        $prediction = StockPrediction::find($id);
        if ($prediction) {
            $product = Product::find($prediction->product_id);
            $bId = (int) ($request->input('branch_id') ?: $prediction->branch_id ?: $branchId);
            $rec = AiRecommendation::query()
                ->whereIn('type', ['reorder', 'REORDER_RECOMMENDATION', 'STOCK_OUT_RISK'])
                ->where('related_entity_id', $prediction->product_id)
                ->latest('id')
                ->first();

            return [$prediction, $rec, $product, $bId];
        }

        // 3. Fallback: Product by ID
        $product = Product::findOrFail($id);
        $prediction = StockPrediction::query()
            ->where('product_id', $product->id)
            ->where('branch_id', $branchId)
            ->first();

        $rec = AiRecommendation::query()
            ->whereIn('type', ['reorder', 'REORDER_RECOMMENDATION', 'STOCK_OUT_RISK'])
            ->where('related_entity_id', $product->id)
            ->latest('id')
            ->first();

        return [$prediction, $rec, $product, $branchId];
    }

    /**
     * Resolve a valid supplier_id for drafting a purchase order.
     */
    protected function resolveSupplierIdForPo(?int $productId, mixed $requestedSupplierId = null): int
    {
        if ($requestedSupplierId && Supplier::where('id', (int) $requestedSupplierId)->exists()) {
            return (int) $requestedSupplierId;
        }

        if ($productId) {
            $ps = ProductSupplier::query()
                ->where('product_id', $productId)
                ->orderByDesc('is_preferred')
                ->orderBy('supply_price')
                ->first();
            if ($ps) {
                return (int) $ps->supplier_id;
            }
        }

        $existingSupplier = Supplier::query()->where('status', 'active')->first() ?? Supplier::query()->first();
        if ($existingSupplier) {
            return (int) $existingSupplier->id;
        }

        $created = Supplier::create([
            'name' => 'Default Approved Supplier',
            'status' => 'active',
        ]);

        return (int) $created->id;
    }

    /**
     * Resolve unit supply price for a product and supplier.
     */
    protected function resolveUnitPriceForPo(?Product $product, int $supplierId): float
    {
        if ($product) {
            $ps = ProductSupplier::query()
                ->where('product_id', $product->id)
                ->where('supplier_id', $supplierId)
                ->first();
            if ($ps && (float) $ps->supply_price > 0) {
                return (float) $ps->supply_price;
            }

            if ((float) $product->cost_price > 0) {
                return (float) $product->cost_price;
            }

            if ((float) $product->selling_price > 0) {
                return (float) $product->selling_price;
            }
        }

        return 10.00;
    }
}
