<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Product;
use App\Services\AuditLogger;
use App\Services\FulfillmentRateCalculator;
use App\Services\SupplierEvaluationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierEvaluationController extends Controller
{
    public function __construct(
        protected SupplierEvaluationService $evaluationService,
        protected FulfillmentRateCalculator $fulfillmentCalculator
    ) {}

    /**
     * GET /api/v1/suppliers/evaluate
     * Evaluate and rank suppliers for a product (or all active products) at a branch.
     */
    public function index(Request $request): JsonResponse
    {
        $branchId = (int) ($request->input('branch_id')
            ?: $request->user()?->branch_id
            ?: (Branch::query()->where('status', 'active')->value('id') ?? 1));

        if ($request->filled('product_id')) {
            $productId = (int) $request->input('product_id');
            $overrides = array_filter([
                'required_quantity' => $request->input('required_quantity'),
                'stock' => $request->input('stock'),
                'rop' => $request->input('rop'),
                'mode' => $request->input('mode'),
                'max_acceptable_lead_days' => $request->input('max_acceptable_lead_days'),
            ], fn ($v) => $v !== null);

            $result = $this->evaluationService->evaluate($productId, $branchId, $overrides);

            return response()->json(array_merge($result, [
                'data' => $result,
            ]));
        }

        $products = Product::query()->where('status', 'active')->orderBy('name')->get();
        $evaluations = [];
        foreach ($products as $product) {
            $evaluations[] = $this->evaluationService->evaluate($product->id, $branchId);
        }

        return response()->json([
            'data' => $evaluations,
            'meta' => [
                'branch_id' => $branchId,
                'count' => count($evaluations),
            ],
        ]);
    }

    /**
     * POST /api/v1/suppliers/evaluate
     * Run Stage 1 Eligibility Filter (#47), Context-Aware Weight Selection (#68),
     * and Stage 2 SAW Scoring & Ranking (#48).
     */
    public function evaluate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer',
            'branch_id' => 'required|integer',
            'required_quantity' => 'nullable|numeric|min:0',
            'stock' => 'nullable|numeric|min:0',
            'rop' => 'nullable|numeric|min:0',
            'reorder_point' => 'nullable|numeric|min:0',
            'mode' => 'nullable|string|in:NORMAL,WARNING,PANIC,normal,warning,panic',
            'max_acceptable_lead_days' => 'nullable|integer|min:1',
            'panic_ratio_of_rop' => 'nullable|numeric|min:0|max:1',
            'candidates' => 'nullable|array',
        ]);

        $overrides = [];
        foreach (['required_quantity', 'stock', 'rop', 'reorder_point', 'mode', 'max_acceptable_lead_days', 'panic_ratio_of_rop', 'candidates'] as $key) {
            if ($request->exists($key)) {
                $overrides[$key] = $validated[$key] ?? null;
            }
        }

        $result = $this->evaluationService->evaluate(
            (int) $validated['product_id'],
            (int) $validated['branch_id'],
            $overrides
        );

        AuditLogger::log(
            $request->user()?->id,
            'suppliers.evaluate',
            'product_suppliers',
            (int) $validated['product_id'],
            [
                'product_id' => (int) $validated['product_id'],
                'branch_id' => (int) $validated['branch_id'],
                'mode' => $result['mode'] ?? null,
                'eligible_count' => $result['eligible_count'] ?? 0,
                'recommended_supplier_id' => $result['recommended_supplier']['supplier_id'] ?? null,
            ],
            $request->ip()
        );

        return response()->json(array_merge($result, [
            'data' => $result,
        ]));
    }

    /**
     * POST /api/v1/suppliers/fulfillment
     * Trigger supplier fulfillment-rate recalculation (#67).
     */
    public function recalculateFulfillment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'window_days' => 'nullable|integer|min:1',
            'product_id' => 'nullable|integer|exists:products,id',
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
        ]);

        $windowDays = isset($validated['window_days']) ? (int) $validated['window_days'] : null;

        if (!empty($validated['product_id']) && !empty($validated['supplier_id'])) {
            $rate = $this->fulfillmentCalculator->calculateForProductSupplier(
                (int) $validated['product_id'],
                (int) $validated['supplier_id'],
                $windowDays
            );

            return response()->json([
                'data' => [
                    'product_id' => (int) $validated['product_id'],
                    'supplier_id' => (int) $validated['supplier_id'],
                    'fulfillment_rate' => $rate,
                ],
            ]);
        }

        $summary = $this->fulfillmentCalculator->calculateAll($windowDays);

        return response()->json([
            'data' => $summary,
        ]);
    }
}

