<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiRecommendation;
use App\Services\AuditLogger;
use App\Services\PriorityScorer;
use App\Services\RecommendationEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RecommendationController (Sub-Features #57 & #58)
 *
 * Exposes the Rule-Based Expert System (IF-THEN) runner and Priority Queue inbox.
 */
class RecommendationController extends Controller
{
    public function __construct(
        protected RecommendationEngine $recommendationEngine,
        protected PriorityScorer $priorityScorer
    ) {}

    /**
     * POST /api/v1/recommendations/run
     * Execute forward-chaining IF-THEN rules across all analytical outputs and update the priority queue.
     */
    public function run(Request $request): JsonResponse
    {
        $branchId = $request->filled('branch_id')
            ? (int) $request->input('branch_id')
            : ($request->user()?->branch_id ? (int) $request->user()->branch_id : null);

        // If custom in-memory facts are supplied, evaluate directly and optionally persist
        if ($request->has('facts') && is_array($request->input('facts'))) {
            $evaluated = $this->recommendationEngine->evaluateForwardChainingRules($request->input('facts'));

            return response()->json([
                'message' => sprintf('Forward-chaining rule evaluation completed. %d recommendations generated.', count($evaluated)),
                'count' => count($evaluated),
                'data' => $evaluated,
            ]);
        }

        $recommendations = $this->recommendationEngine->runForBranch($branchId);

        AuditLogger::log(
            $request->user()?->id,
            'recommendations.run',
            'ai_recommendations',
            null,
            [
                'branch_id' => $branchId,
                'count' => $recommendations->count(),
            ],
            $request->ip()
        );

        return response()->json([
            'message' => sprintf('Recommendation engine run completed. %d prioritized recommendations active.', $recommendations->count()),
            'count' => $recommendations->count(),
            'data' => $recommendations->values(),
        ]);
    }

    /**
     * GET /api/v1/recommendations
     * Return the ordered recommendation inbox sorted by weighted priority_score descending (#58).
     */
    public function index(Request $request): JsonResponse
    {
        $query = AiRecommendation::query()
            ->with(['product:id,name,sku,selling_price,cost_price', 'reviewer:id,name']);

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        } else {
            $query->where('status', 'open');
        }

        if ($request->filled('type')) {
            $query->where('type', (string) $request->input('type'));
        }

        if ($request->filled('priority')) {
            $query->where('priority', strtolower((string) $request->input('priority')));
        }

        $items = $query->orderByDesc('priority_score')->orderByDesc('generated_at')->get();

        $branchId = $request->filled('branch_id')
            ? (int) $request->input('branch_id')
            : null;

        if ($branchId !== null) {
            $items = $items->filter(function (AiRecommendation $rec) use ($branchId) {
                $factors = is_array($rec->factors) ? $rec->factors : [];
                return !isset($factors['branch_id']) || (int) $factors['branch_id'] === $branchId;
            })->values();
        }

        $ranked = $this->priorityScorer->rankQueue($items);

        return response()->json([
            'data' => $ranked,
            'meta' => [
                'count' => $ranked->count(),
                'weights' => $this->priorityScorer->resolveWeights(),
            ],
        ]);
    }
}
