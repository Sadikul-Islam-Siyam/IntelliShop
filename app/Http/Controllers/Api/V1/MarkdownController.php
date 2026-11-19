<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MarkdownRecommendation;
use App\Services\AuditLogger;
use App\Services\MarkdownService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarkdownController extends Controller
{
    public function __construct(
        protected MarkdownService $markdownService
    ) {}

    /**
     * Run batch markdown evaluation.
     * Endpoint: POST /api/v1/markdown/run
     */
    public function run(Request $request): JsonResponse
    {
        $branchId = $request->input('branch_id', $request->user()?->branch_id);

        $recommendations = $this->markdownService->runEvaluation($branchId ? (int) $branchId : null);

        $loaded = MarkdownRecommendation::whereIn('id', $recommendations->pluck('id'))
            ->with(['product:id,name,sku', 'batch:id,batch_no,expiry_date'])
            ->get();

        return response()->json([
            'message' => "Zero-waste markdown evaluation completed. {$loaded->count()} recommendations generated.",
            'count' => $loaded->count(),
            'data' => $loaded,
        ]);
    }

    /**
     * List existing markdown recommendations.
     * Endpoint: GET /api/v1/markdown/recommendations
     */
    public function index(Request $request): JsonResponse
    {
        $query = MarkdownRecommendation::with(['product:id,name,sku', 'batch:id,batch_no,expiry_date', 'branch:id,name']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->input('branch_id'));
        }

        return response()->json($query->latest('generated_at')->paginate(25));
    }

    /**
     * Approve a markdown recommendation and create batch discount.
     * Endpoint: POST /api/v1/markdown/{recommendation}/approve
     */
    public function approve(Request $request, MarkdownRecommendation $recommendation): JsonResponse
    {
        $user = $request->user();

        $discount = $this->markdownService->approveRecommendation($recommendation, $user);

        AuditLogger::log(
            $user->id,
            'markdown.approve',
            'markdown_recommendations',
            $recommendation->id,
            [
                'batch_id' => $recommendation->batch_id,
                'discount_id' => $discount->id,
                'discount_pct' => $discount->value,
            ],
            $request->ip()
        );

        return response()->json([
            'message' => 'Markdown recommendation approved and batch discount applied.',
            'recommendation' => $recommendation->fresh()->load('discount'),
            'discount' => $discount,
        ]);
    }

    /**
     * Reject a markdown recommendation.
     * Endpoint: POST /api/v1/markdown/{recommendation}/reject
     */
    public function reject(Request $request, MarkdownRecommendation $recommendation): JsonResponse
    {
        $user = $request->user();

        $this->markdownService->rejectRecommendation($recommendation, $user);

        AuditLogger::log(
            $user->id,
            'markdown.reject',
            'markdown_recommendations',
            $recommendation->id,
            ['batch_id' => $recommendation->batch_id],
            $request->ip()
        );

        return response()->json([
            'message' => 'Markdown recommendation rejected.',
            'recommendation' => $recommendation->fresh(),
        ]);
    }
}
