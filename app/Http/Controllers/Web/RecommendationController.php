<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AiRecommendation;
use App\Models\Branch;
use App\Services\PriorityScorer;
use App\Services\RecommendationEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RecommendationController extends Controller
{
    public function __construct(
        protected RecommendationEngine $recommendationEngine,
        protected PriorityScorer $priorityScorer
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $branches = Branch::query()->where('status', 'active')->orderBy('name')->get();
        $selectedBranchId = $request->filled('branch_id') ? (int) $request->input('branch_id') : $user?->branch_id;

        $query = AiRecommendation::query()
            ->with(['product:id,name,sku,selling_price,cost_price', 'reviewer:id,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')), fn ($q) => $q->where('status', 'open'))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->when($request->filled('priority'), fn ($q) => $q->where('priority', strtolower((string) $request->input('priority'))));

        $recommendations = $this->priorityScorer->rankQueue($query->get());

        if ($selectedBranchId !== null) {
            $recommendations = $recommendations->filter(function (AiRecommendation $r) use ($selectedBranchId) {
                $f = is_array($r->factors) ? $r->factors : [];
                return !isset($f['branch_id']) || (int) $f['branch_id'] === $selectedBranchId;
            })->values();
        }

        return view('recommendations.index', [
            'user' => $user,
            'branches' => $branches,
            'selectedBranchId' => $selectedBranchId,
            'recommendations' => $recommendations,
            'weights' => $this->priorityScorer->resolveWeights(),
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        $branchId = $request->filled('branch_id') ? (int) $request->input('branch_id') : $request->user()?->branch_id;
        $recs = $this->recommendationEngine->runForBranch($branchId ? (int) $branchId : null);

        return redirect()->route('recommendations.index', ['branch_id' => $branchId])
            ->with('success', "Forward-chaining recommendation engine executed. {$recs->count()} recommendations ranked.");
    }
}
