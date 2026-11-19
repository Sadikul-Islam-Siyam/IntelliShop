<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SalesAnomaly;
use App\Services\AuditLogger;
use App\Services\SalesAnomalyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnomalyController extends Controller
{
    public function __construct(
        protected SalesAnomalyService $anomalyService
    ) {}

    /**
     * Run statistical sales anomaly detection.
     * Endpoint: POST /api/v1/anomalies/run
     */
    public function run(Request $request): JsonResponse
    {
        $productId = $request->input('product_id') ? (int) $request->input('product_id') : null;
        $branchId = $request->input('branch_id') ? (int) $request->input('branch_id') : $request->user()?->branch_id;
        $lookbackDays = (int) ($request->input('lookback_days', 60) ?? 60);

        $anomalies = $this->anomalyService->runDetection($productId, $branchId ? (int) $branchId : null, $lookbackDays);

        $loaded = SalesAnomaly::whereIn('id', $anomalies->pluck('id'))
            ->with(['product:id,name,sku', 'branch:id,name'])
            ->get();

        return response()->json([
            'message' => "Statistical anomaly detection completed. {$loaded->count()} anomalies flagged.",
            'count' => $loaded->count(),
            'data' => $loaded,
        ]);
    }

    /**
     * List flagged sales anomalies.
     * Endpoint: GET /api/v1/anomalies
     */
    public function index(Request $request): JsonResponse
    {
        $query = SalesAnomaly::with(['product:id,name,sku', 'branch:id,name']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->input('branch_id'));
        }
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }

        return response()->json($query->latest('detected_date')->paginate(25));
    }

    /**
     * Review an anomaly: acknowledge, explain, dismiss.
     * Endpoint: POST /api/v1/anomalies/{anomaly}/review
     */
    public function review(Request $request, SalesAnomaly $anomaly): JsonResponse
    {
        $validated = $request->validate([
            'action' => 'required|string|in:acknowledged,explained,dismissed',
            'notes' => 'nullable|string|max:500',
        ]);

        $anomaly->update([
            'status' => $validated['action'],
            'notes' => $validated['notes'] ?? $anomaly->notes,
        ]);

        AuditLogger::log(
            $request->user()?->id,
            'anomaly.review',
            'sales_anomalies',
            $anomaly->id,
            [
                'action' => $validated['action'],
                'notes' => $validated['notes'] ?? null,
            ],
            $request->ip()
        );

        return response()->json([
            'message' => "Anomaly status updated to {$validated['action']}.",
            'data' => $anomaly->fresh(),
        ]);
    }
}
