<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiRecommendation;
use App\Models\SalesAnomaly;
use App\Services\AuditLogger;
use App\Services\SalesAnomalyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AnomalyController (Sub-Features #52 & #54)
 *
 * Exposes statistical anomaly detection and the Anomaly Review Workflow
 * (acknowledge / explain / dismiss) with full contextual metadata
 * (stock-outs, discounts, promotions, holidays).
 */
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
            ->with(['product:id,name,sku,category_id', 'branch:id,name'])
            ->get()
            ->map(function (SalesAnomaly $anomaly) {
                $arr = $anomaly->toArray();
                $arr['context'] = $this->anomalyService->buildContextForAnomaly($anomaly);
                return $arr;
            });

        return response()->json([
            'message' => "Statistical anomaly detection completed. {$loaded->count()} anomalies flagged.",
            'count' => $loaded->count(),
            'signal_notice' => 'Alert is a statistical screening signal, not proof of error or fraud.',
            'data' => $loaded,
        ]);
    }

    /**
     * List flagged sales anomalies with operational context.
     * Endpoint: GET /api/v1/anomalies
     */
    public function index(Request $request): JsonResponse
    {
        $query = SalesAnomaly::with(['product:id,name,sku,category_id', 'branch:id,name']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->input('branch_id'));
        }
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }

        $paginated = $query->latest('detected_date')->paginate(25);
        $paginated->getCollection()->transform(function (SalesAnomaly $anomaly) {
            $arr = $anomaly->toArray();
            $arr['context'] = $this->anomalyService->buildContextForAnomaly($anomaly);
            return $arr;
        });

        return response()->json($paginated);
    }

    /**
     * Show a single sales anomaly with full context (stock-outs, discounts, holidays).
     * Endpoint: GET /api/v1/anomalies/{anomaly}
     */
    public function show(SalesAnomaly $anomaly): JsonResponse
    {
        $anomaly->load(['product:id,name,sku,category_id', 'branch:id,name']);
        $context = $this->anomalyService->buildContextForAnomaly($anomaly);

        return response()->json([
            'data' => array_merge($anomaly->toArray(), [
                'context' => $context,
            ]),
            'context' => $context,
        ]);
    }

    /**
     * Review an anomaly: acknowledge, explain, dismiss (#54).
     * Endpoint: POST /api/v1/anomalies/{anomaly}/review
     */
    public function review(Request $request, SalesAnomaly $anomaly): JsonResponse
    {
        $validated = $request->validate([
            'action' => 'required|string|in:acknowledge,acknowledged,explain,explained,dismiss,dismissed',
            'notes' => 'nullable|string|max:500',
            'explanation' => 'nullable|string|max:500',
        ]);

        $normalizedAction = match (strtolower($validated['action'])) {
            'acknowledge', 'acknowledged' => 'acknowledged',
            'explain', 'explained' => 'explained',
            'dismiss', 'dismissed' => 'dismissed',
        };

        $notes = $validated['notes'] ?? $validated['explanation'] ?? $anomaly->notes;

        $anomaly->update([
            'status' => $normalizedAction,
            'notes' => $notes,
        ]);

        // Also update any open AI recommendation linked to this anomaly
        AiRecommendation::query()
            ->where('type', 'anomaly')
            ->where('related_entity_id', $anomaly->id)
            ->update([
                'status' => $normalizedAction,
                'reviewed_by' => $request->user()?->id,
            ]);

        $context = $this->anomalyService->buildContextForAnomaly($anomaly);

        AuditLogger::log(
            $request->user()?->id,
            'anomaly.review',
            'sales_anomalies',
            $anomaly->id,
            [
                'action' => $normalizedAction,
                'notes' => $notes,
                'detected_date' => $anomaly->detected_date?->format('Y-m-d'),
            ],
            $request->ip()
        );

        $fresh = $anomaly->fresh(['product:id,name,sku,category_id', 'branch:id,name']);

        return response()->json([
            'message' => "Anomaly status updated to {$normalizedAction}.",
            'data' => array_merge($fresh->toArray(), [
                'context' => $context,
            ]),
            'context' => $context,
        ]);
    }
}
