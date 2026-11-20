<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CycleCount;
use App\Services\AuditLogger;
use App\Services\PhantomInventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PhantomInventoryController extends Controller
{
    public function __construct(
        protected PhantomInventoryService $phantomService
    ) {}

    /**
     * Run phantom inventory detection.
     * Endpoint: POST /api/v1/phantom/run
     */
    public function run(Request $request): JsonResponse
    {
        $branchId = $request->input('branch_id', $request->user()?->branch_id);
        $productId = $request->input('product_id');

        $tasks = $this->phantomService->runDetection(
            $branchId ? (int) $branchId : null,
            $productId ? (int) $productId : null
        );

        $loaded = CycleCount::whereIn('id', $tasks->pluck('id'))
            ->with(['product:id,name,sku', 'branch:id,name'])
            ->get();

        return response()->json([
            'message' => "Phantom inventory screening completed. {$loaded->count()} cycle-count tasks active.",
            'count' => $loaded->count(),
            'data' => $loaded,
        ]);
    }

    /**
     * List open phantom inventory cycle count tasks.
     * Endpoint: GET /api/v1/phantom/candidates
     */
    public function candidates(Request $request): JsonResponse
    {
        $query = CycleCount::where('trigger_source', 'phantom_inventory')
            ->with(['product:id,name,sku', 'branch:id,name']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->input('branch_id'));
        }

        return response()->json($query->latest()->paginate(25));
    }
}
