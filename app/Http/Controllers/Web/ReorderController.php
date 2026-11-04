<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\Analytics\StockRiskService;
use App\Services\ReorderCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class ReorderController extends Controller
{
    public function __construct(
        protected ReorderCalculator $reorderCalculator,
        protected StockRiskService $stockRiskService
    ) {}

    /**
     * Render the Manager Review Screen for Dynamic Replenishment & Safety Stock (ROP + Constraints).
     */
    public function index(Request $request): View
    {
        $user = $request->user();
        $branches = Branch::query()->where('status', 'active')->orderBy('name')->get();

        $selectedBranchId = (int) ($request->input('branch_id')
            ?: $user?->branch_id
            ?: ($branches->first()?->id ?? 1));

        $selectedRisk = $request->input('risk_level', '');
        $filterMode = $request->input('filter', '');

        try {
            $this->stockRiskService->ensureBranchPredictions($selectedBranchId);
            $recommendations = $this->reorderCalculator->ensureBranchCalculations($selectedBranchId);
        } catch (\Throwable $e) {
            Log::warning('Failed to auto-compute reorder recommendations on page load: ' . $e->getMessage());
            $recommendations = [];
        }

        if (in_array(strtoupper($selectedRisk), ['HIGH', 'MEDIUM', 'LOW'], true)) {
            $riskUpper = strtoupper($selectedRisk);
            $recommendations = array_values(array_filter(
                $recommendations,
                fn (array $row) => ($row['prediction']['risk_level'] ?? 'LOW') === $riskUpper
            ));
        }

        if ($filterMode === 'reorder_needed') {
            $recommendations = array_values(array_filter(
                $recommendations,
                fn (array $row) => (int) ($row['calculation']['final_quantity'] ?? 0) > 0
                    || (bool) ($row['calculation']['conflict'] ?? false)
            ));
        } elseif ($filterMode === 'conflicts') {
            $recommendations = array_values(array_filter(
                $recommendations,
                fn (array $row) => (bool) ($row['calculation']['conflict'] ?? false)
            ));
        }

        // Sort: conflicts & HIGH risk first, then MEDIUM, then LOW, then by product name
        usort($recommendations, function (array $a, array $b): int {
            $conflictA = (bool) ($a['calculation']['conflict'] ?? false) ? 0 : 1;
            $conflictB = (bool) ($b['calculation']['conflict'] ?? false) ? 0 : 1;
            if ($conflictA !== $conflictB) {
                return $conflictA <=> $conflictB;
            }

            $riskOrder = ['HIGH' => 1, 'MEDIUM' => 2, 'LOW' => 3];
            $rA = $riskOrder[$a['prediction']['risk_level'] ?? 'LOW'] ?? 4;
            $rB = $riskOrder[$b['prediction']['risk_level'] ?? 'LOW'] ?? 4;
            if ($rA !== $rB) {
                return $rA <=> $rB;
            }

            return strcmp((string) ($a['product']['name'] ?? ''), (string) ($b['product']['name'] ?? ''));
        });

        $reviewPeriod = $this->reorderCalculator->resolveReviewPeriodDays();
        $serviceLevelZ = $this->reorderCalculator->resolveServiceLevelZ();

        return view('reorder.index', [
            'user' => $user,
            'branches' => $branches,
            'selectedBranchId' => $selectedBranchId,
            'selectedRisk' => strtoupper($selectedRisk),
            'filterMode' => $filterMode,
            'recommendations' => $recommendations,
            'reviewPeriod' => $reviewPeriod,
            'serviceLevelZ' => $serviceLevelZ,
        ]);
    }
}

