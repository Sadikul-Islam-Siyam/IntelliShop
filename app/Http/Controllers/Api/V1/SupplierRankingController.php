<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\AuditLogger;
use App\Services\SupplierEvaluationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierRankingController extends Controller
{
    public function __construct(
        protected SupplierEvaluationService $evaluationService
    ) {}

    /**
     * GET /api/v1/suppliers/ranking
     * Retrieve ranked suppliers, active weight mode, criterion score breakdown, and alternatives.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'override_supplier_id' => 'nullable|integer|exists:suppliers,id',
            'mode' => 'nullable|string|in:NORMAL,WARNING,PANIC,normal,warning,panic',
            'required_quantity' => 'nullable|numeric|min:0',
        ]);

        $productId = (int) $request->input('product_id');
        $branchId = (int) ($request->input('branch_id')
            ?: $request->user()?->branch_id
            ?: (Branch::query()->where('status', 'active')->value('id') ?? 1));

        $overrides = array_filter([
            'mode' => $request->input('mode'),
            'required_quantity' => $request->input('required_quantity'),
        ], fn($v) => $v !== null);

        $evaluation = $this->evaluationService->evaluate($productId, $branchId, $overrides);

        $ranked = $evaluation['ranking'] ?? [];
        $topSupplier = $evaluation['recommended_supplier'] ?? (!empty($ranked) ? $ranked[0] : null);
        $alternatives = count($ranked) > 1 ? array_slice($ranked, 1) : [];

        $overrideSupplierId = $request->input('override_supplier_id');
        $selectedSupplier = $topSupplier;
        $isOverridden = false;

        if ($overrideSupplierId !== null) {
            $isOverridden = true;
            $matched = collect($ranked)->firstWhere('supplier_id', (int) $overrideSupplierId);
            if ($matched) {
                $selectedSupplier = $matched;
            } else {
                $sup = Supplier::find($overrideSupplierId);
                if ($sup) {
                    $selectedSupplier = [
                        'supplier_id' => $sup->id,
                        'supplier_name' => $sup->name,
                        'total_score' => 0.0,
                        'overridden_manually' => true,
                    ];
                }
            }
        }

        $response = [
            'product_id' => $productId,
            'branch_id' => $branchId,
            'active_mode' => $evaluation['mode'] ?? 'NORMAL',
            'weights' => $evaluation['weights'] ?? SupplierEvaluationService::DEFAULT_WEIGHT_MODES['NORMAL'],
            'top_supplier' => $topSupplier,
            'alternatives' => $alternatives,
            'selected_supplier' => $selectedSupplier,
            'is_overridden' => $isOverridden,
            'score_breakdown' => array_map(function ($cand) {
                return [
                    'supplier_id' => $cand['supplier_id'] ?? null,
                    'supplier_name' => $cand['supplier_name'] ?? $cand['name'] ?? ('Supplier #' . ($cand['supplier_id'] ?? '')),
                    'rank' => $cand['rank'] ?? null,
                    'raw' => [
                        'price' => $cand['price'] ?? $cand['supply_price'] ?? null,
                        'lead_time_days' => $cand['lead_time_days'] ?? null,
                        'fulfillment_rate' => $cand['fulfillment_rate'] ?? null,
                    ],
                    'normalized' => [
                        'price' => $cand['normalized']['r_price'] ?? $cand['r_price'] ?? null,
                        'speed' => $cand['normalized']['r_leadtime'] ?? $cand['r_speed'] ?? null,
                        'reliability' => $cand['normalized']['r_fulfillment'] ?? $cand['r_reliability'] ?? null,
                    ],
                    'total_saw_score' => $cand['score'] ?? $cand['total_score'] ?? 0.0,
                ];
            }, $ranked),
            'ineligible' => $evaluation['ineligible'] ?? [],
        ];

        return response()->json([
            'data' => $response,
        ]);
    }

    /**
     * POST /api/v1/suppliers/ranking/override
     * Record a manual purchase manager override of the algorithm recommendation.
     */
    public function override(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'branch_id' => 'required|integer|exists:branches,id',
            'chosen_supplier_id' => 'required|integer|exists:suppliers,id',
            'recommended_supplier_id' => 'required|integer|exists:suppliers,id',
            'reason' => 'required|string|max:500',
        ]);

        AuditLogger::log(
            $request->user()?->id,
            'supplier.ranking_override',
            'purchase_orders',
            null,
            [
                'product_id' => $validated['product_id'],
                'branch_id' => $validated['branch_id'],
                'chosen_supplier_id' => $validated['chosen_supplier_id'],
                'recommended_supplier_id' => $validated['recommended_supplier_id'],
                'reason' => $validated['reason'],
            ],
            $request->ip()
        );

        return response()->json([
            'message' => 'Supplier recommendation successfully overridden by Purchase Manager.',
            'data' => [
                'chosen_supplier_id' => (int) $validated['chosen_supplier_id'],
                'recommended_supplier_id' => (int) $validated['recommended_supplier_id'],
                'reason' => $validated['reason'],
            ],
        ]);
    }
}
