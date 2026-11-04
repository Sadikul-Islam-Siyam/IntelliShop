<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\AuditLogger;
use App\Services\ReorderCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReorderController extends Controller
{
    public function __construct(
        protected ReorderCalculator $reorderCalculator
    ) {}

    /**
     * GET /api/v1/reorder
     * Retrieve dynamic replenishment & safety stock calculations for a branch.
     */
    public function index(Request $request): JsonResponse
    {
        $branchId = (int) ($request->input('branch_id')
            ?: $request->user()?->branch_id
            ?: (Branch::query()->where('status', 'active')->value('id') ?? 1));

        $records = $this->reorderCalculator->ensureBranchCalculations($branchId);

        if ($request->filled('product_id')) {
            $productId = (int) $request->input('product_id');
            $records = array_values(array_filter(
                $records,
                fn (array $item) => (int) ($item['product']['id'] ?? 0) === $productId
            ));
        }

        if ($request->boolean('conflicts_only')) {
            $records = array_values(array_filter(
                $records,
                fn (array $item) => (bool) ($item['calculation']['conflict'] ?? false)
            ));
        }

        return response()->json([
            'data' => $records,
            'meta' => [
                'branch_id' => $branchId,
                'count' => count($records),
            ],
        ]);
    }

    /**
     * POST /api/v1/reorder/calculate
     * Calculate safety stock (SS), reorder point (ROP), target stock, and constrained reorder quantity.
     */
    public function calculate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'branch_id' => 'required|integer|exists:branches,id',
            'forecast_demand' => 'nullable|numeric|min:0',
            'demand_stddev' => 'nullable|numeric|min:0',
            'service_level_z' => 'nullable|numeric|gt:0',
            'service_level' => 'nullable|numeric|gt:0',
            'lead_time_days' => 'nullable|integer|min:0',
            'review_period_days' => 'nullable|integer|min:1',
            'stock' => 'nullable|numeric',
            'reserved' => 'nullable|numeric|min:0',
            'on_order' => 'nullable|numeric|min:0',
            'moq' => 'nullable|integer|min:0',
            'order_multiple' => 'nullable|integer|min:1',
            'capacity' => 'nullable|numeric|min:0',
            'shelf_life_days' => 'nullable|integer|min:1',
        ]);

        $overrides = [];
        $overrideKeys = [
            'forecast_demand',
            'demand_stddev',
            'service_level_z',
            'service_level',
            'lead_time_days',
            'review_period_days',
            'stock',
            'reserved',
            'on_order',
            'moq',
            'order_multiple',
            'capacity',
            'shelf_life_days',
        ];

        foreach ($overrideKeys as $key) {
            if ($request->exists($key)) {
                $overrides[$key] = $validated[$key] ?? null;
            }
        }

        $result = $this->reorderCalculator->calculate(
            (int) $validated['product_id'],
            (int) $validated['branch_id'],
            $overrides
        );

        AuditLogger::log(
            $request->user()?->id,
            'reorder.calculate',
            'stock_predictions',
            $result['prediction']['id'] ?? null,
            [
                'product_id' => $validated['product_id'],
                'branch_id' => $validated['branch_id'],
                'safety_stock' => $result['calculation']['safety_stock'] ?? null,
                'reorder_point' => $result['calculation']['reorder_point'] ?? null,
                'final_quantity' => $result['calculation']['final_quantity'] ?? 0,
                'conflict' => $result['calculation']['conflict'] ?? false,
                'conflict_flag' => $result['calculation']['conflict_flag'] ?? null,
            ],
            $request->ip()
        );

        return response()->json([
            'data' => $result,
        ], 201);
    }
}

