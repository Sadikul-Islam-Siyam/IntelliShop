<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Product;
use App\Models\StockPrediction;
use App\Services\Analytics\StockRiskService;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StockRiskController extends Controller
{
    public function __construct(
        protected StockRiskService $stockRiskService
    ) {}

    /**
     * List current stock predictions and risk classifications.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'risk_level' => ['nullable', 'string', 'in:HIGH,MEDIUM,LOW,high,medium,low'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
        ]);

        $query = StockPrediction::query()->with(['product', 'branch']);

        if (!empty($validated['branch_id'])) {
            $query->where('branch_id', (int) $validated['branch_id']);
        } elseif ($request->user() && $request->user()->branch_id && !$request->user()->isSuperAdmin()) {
            $query->where('branch_id', (int) $request->user()->branch_id);
        }

        if (!empty($validated['risk_level'])) {
            $query->where('risk_level', strtoupper($validated['risk_level']));
        }

        if (!empty($validated['product_id'])) {
            $query->where('product_id', (int) $validated['product_id']);
        }

        $predictions = $query->orderByRaw("
            CASE risk_level
                WHEN 'HIGH' THEN 1
                WHEN 'MEDIUM' THEN 2
                ELSE 3
            END ASC
        ")->orderBy('predicted_stockout_date', 'asc')->get();

        return response()->json([
            'data' => $predictions->map(function ($row) {
                return [
                    'id' => $row->id,
                    'product_id' => $row->product_id,
                    'product_name' => $row->product?->name,
                    'sku' => $row->product?->sku,
                    'branch_id' => $row->branch_id,
                    'branch_name' => $row->branch?->name,
                    'current_stock' => (float) $row->current_stock,
                    'predicted_demand' => (float) $row->predicted_demand,
                    'predicted_stockout_date' => $row->predicted_stockout_date?->format('Y-m-d'),
                    'risk_level' => $row->risk_level,
                    'lead_time_days' => $row->lead_time_days,
                    'safety_stock' => $row->safety_stock ? (float) $row->safety_stock : null,
                    'reorder_point' => $row->reorder_point ? (float) $row->reorder_point : null,
                    'recommended_reorder_qty' => (int) $row->recommended_reorder_qty,
                    'generated_at' => $row->generated_at?->toIso8601String(),
                ];
            }),
            'meta' => [
                'total' => $predictions->count(),
                'high_risk_count' => $predictions->where('risk_level', 'HIGH')->count(),
                'medium_risk_count' => $predictions->where('risk_level', 'MEDIUM')->count(),
                'low_risk_count' => $predictions->where('risk_level', 'LOW')->count(),
            ],
        ]);
    }

    /**
     * Run stock-out risk projection for a product and branch (POST /api/v1/stock-risk/run).
     */
    public function run(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'horizon' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);

        $productId = (int) $validated['product_id'];
        $branchId = (int) $validated['branch_id'];
        $horizon = isset($validated['horizon']) ? (int) $validated['horizon'] : null;

        $result = $this->stockRiskService->runStockRisk($productId, $branchId, $horizon);

        if ($request->user()) {
            AuditLogger::log(
                $request->user()->id,
                'stock_risk.run',
                'stock_predictions',
                $result['prediction']['id'] ?? null,
                [
                    'product_id' => $productId,
                    'branch_id' => $branchId,
                    'risk_level' => $result['prediction']['risk_level'] ?? null,
                    'predicted_stockout_date' => $result['prediction']['predicted_stockout_date'] ?? null,
                ]
            );
        }

        return response()->json([
            'data' => $result,
        ], Response::HTTP_CREATED);
    }
}
