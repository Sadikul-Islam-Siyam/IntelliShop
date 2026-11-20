<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CycleCount;
use App\Services\ReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CycleCountController (Sub-Feature #71)
 *
 * Exposes API endpoints for phantom-inventory cycle count reconciliation:
 *  - Record physical count entry
 *  - Approve variance (posts explicit inventory ledger adjustment)
 *  - Reject variance
 */
class CycleCountController extends Controller
{
    public function __construct(
        protected ReconciliationService $reconciliationService
    ) {}

    /**
     * GET /api/v1/cycle-counts
     */
    public function index(Request $request): JsonResponse
    {
        $query = CycleCount::with(['product', 'branch', 'batch', 'countedBy', 'reviewedBy'])
            ->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', (int) $request->input('branch_id'));
        }

        $counts = $query->paginate((int) $request->input('per_page', 20));

        return response()->json([
            'data' => $counts->items(),
            'meta' => [
                'current_page' => $counts->currentPage(),
                'per_page' => $counts->perPage(),
                'total' => $counts->total(),
                'last_page' => $counts->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/v1/cycle-counts/{id}
     */
    public function show(int $id): JsonResponse
    {
        $cycleCount = CycleCount::with(['product', 'branch', 'batch', 'countedBy', 'reviewedBy'])
            ->findOrFail($id);

        return response()->json([
            'data' => $cycleCount,
        ]);
    }

    /**
     * POST /api/v1/cycle-counts/{id}/count
     */
    public function recordCount(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'physical_quantity' => 'required|integer|min:0',
            'reason' => 'nullable|string|max:255',
            'variance_reason' => 'nullable|string|max:255',
        ]);

        $cycleCount = CycleCount::findOrFail($id);
        $reason = $validated['variance_reason'] ?? $validated['reason'] ?? null;

        $updated = $this->reconciliationService->recordPhysicalCount(
            $cycleCount,
            (int) $validated['physical_quantity'],
            (int) ($request->user()?->id ?: 1),
            $reason
        );

        return response()->json([
            'message' => 'Physical count recorded successfully. Variance calculated.',
            'data' => $updated,
        ]);
    }

    /**
     * POST /api/v1/cycle-counts/{id}/approve
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        $cycleCount = CycleCount::findOrFail($id);

        $approved = $this->reconciliationService->approveVariance(
            $cycleCount,
            (int) ($request->user()?->id ?: 1),
            $validated['notes'] ?? null
        );

        return response()->json([
            'message' => 'Cycle count variance approved and inventory ledger adjustment posted.',
            'data' => $approved,
        ]);
    }

    /**
     * POST /api/v1/cycle-counts/{id}/reject
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        $cycleCount = CycleCount::findOrFail($id);

        $rejected = $this->reconciliationService->rejectVariance(
            $cycleCount,
            (int) ($request->user()?->id ?: 1),
            $validated['reason'] ?? null
        );

        return response()->json([
            'message' => 'Cycle count variance rejected. Inventory stock unchanged.',
            'data' => $rejected,
        ]);
    }
}
