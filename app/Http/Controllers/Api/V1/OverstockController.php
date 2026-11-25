<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\AuditLogger;
use App\Services\OverstockAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OverstockController extends Controller
{
    public function __construct(
        protected OverstockAlertService $overstockService
    ) {}

    /**
     * POST /api/v1/overstock/run
     * Trigger slow-moving (#50) and overstock (#51) evaluation for a product or an entire branch.
     * Access: Analyst+
     */
    public function run(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'nullable|integer|exists:products,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'available_stock' => 'nullable|numeric|min:0',
            'stock' => 'nullable|numeric|min:0',
            'total_sold' => 'nullable|numeric|min:0',
            'window_days' => 'nullable|integer|min:1',
            'slow_moving_threshold' => 'nullable|numeric|min:0|max:1',
            'forecast_demand_horizon' => 'nullable|numeric|min:0',
            'horizon_days' => 'nullable|integer|min:1',
        ]);

        $branchId = (int) ($validated['branch_id']
            ?? $request->user()?->branch_id
            ?? (Branch::query()->where('status', 'active')->value('id') ?? 1));

        $overrides = [];
        foreach (['available_stock', 'stock', 'total_sold', 'window_days', 'slow_moving_threshold', 'forecast_demand_horizon', 'horizon_days'] as $key) {
            if ($request->exists($key)) {
                $overrides[$key] = $validated[$key] ?? null;
            }
        }

        if (!empty($validated['product_id'])) {
            $productId = (int) $validated['product_id'];
            $result = $this->overstockService->runForProduct($productId, $branchId, $overrides);

            AuditLogger::log(
                $request->user()?->id,
                'overstock.run',
                'ai_recommendations',
                $result['recommendation']['id'] ?? null,
                [
                    'product_id' => $productId,
                    'branch_id' => $branchId,
                    'is_slow_moving' => $result['is_slow_moving'] ?? false,
                    'is_overstock' => $result['is_overstock'] ?? false,
                    'sell_through_rate' => $result['sell_through_rate'] ?? 0.0,
                    'overstock_units' => $result['overstock_units'] ?? 0.0,
                ],
                $request->ip()
            );

            return response()->json(array_merge($result, [
                'data' => $result,
            ]));
        }

        $results = $this->overstockService->runAllForBranch($branchId, $overrides);

        AuditLogger::log(
            $request->user()?->id,
            'overstock.run_all',
            'ai_recommendations',
            null,
            [
                'branch_id' => $branchId,
                'evaluated_count' => count($results),
            ],
            $request->ip()
        );

        return response()->json([
            'data' => $results,
            'meta' => [
                'branch_id' => $branchId,
                'count' => count($results),
            ],
        ]);
    }

    /**
     * GET /api/v1/overstock/alerts
     * Return open overstock and slow-moving alerts from ai_recommendations (#51).
     * Access: Manager roles
     */
    public function alerts(Request $request): JsonResponse
    {
        $branchId = $request->filled('branch_id')
            ? (int) $request->input('branch_id')
            : ($request->user()?->branch_id ? (int) $request->user()->branch_id : null);

        $autoRefresh = $request->boolean('auto_refresh', false);
        $alerts = $this->overstockService->getAlertsForBranch($branchId, $autoRefresh);

        $formatted = $alerts->map(function ($rec) {
            $factors = is_array($rec->factors) ? $rec->factors : [];

            return [
                'id' => $rec->id,
                'type' => $rec->type,
                'alert_subtype' => $factors['alert_subtype'] ?? 'OVERSTOCK',
                'related_entity_type' => $rec->related_entity_type,
                'related_entity_id' => $rec->related_entity_id,
                'product_id' => $factors['product_id'] ?? $rec->related_entity_id,
                'product_name' => $factors['product_name'] ?? null,
                'sku' => $factors['sku'] ?? null,
                'branch_id' => $factors['branch_id'] ?? null,
                'branch_name' => $factors['branch_name'] ?? null,
                'priority' => $rec->priority,
                'priority_score' => (float) $rec->priority_score,
                'recommendation_text' => $rec->recommendation_text,
                'status' => $rec->status,
                'available_stock' => (float) ($factors['available_stock'] ?? 0.0),
                'total_sold' => (float) ($factors['total_sold'] ?? 0.0),
                'sell_through_rate' => (float) ($factors['sell_through_rate'] ?? 0.0),
                'slow_moving_threshold' => (float) ($factors['slow_moving_threshold'] ?? 0.20),
                'is_slow_moving' => (bool) ($factors['is_slow_moving'] ?? false),
                'forecast_demand_horizon' => (float) ($factors['forecast_demand_horizon'] ?? 0.0),
                'horizon_days' => (int) ($factors['horizon_days'] ?? 14),
                'is_overstock' => (bool) ($factors['is_overstock'] ?? false),
                'overstock_units' => (float) ($factors['overstock_units'] ?? 0.0),
                'overstock_ratio' => (float) ($factors['overstock_ratio'] ?? 0.0),
                'days_of_cover' => $factors['days_of_cover'] ?? null,
                'factors' => $factors,
                'generated_at' => $rec->generated_at?->toIso8601String(),
            ];
        })->values()->all();

        return response()->json([
            'data' => $formatted,
            'meta' => [
                'branch_id' => $branchId,
                'count' => count($formatted),
            ],
        ]);
    }
}

