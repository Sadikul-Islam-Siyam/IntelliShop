<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\OverstockAlertService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class OverstockController extends Controller
{
    public function __construct(
        protected OverstockAlertService $overstockService
    ) {}

    /**
     * Render the Slow-Moving & Overstock Alerts workspace (#50 & #51).
     */
    public function index(Request $request): View
    {
        $user = $request->user();
        $branches = Branch::query()->where('status', 'active')->orderBy('name')->get();

        $selectedBranchId = (int) ($request->input('branch_id')
            ?: $user?->branch_id
            ?: ($branches->first()?->id ?? 1));

        $filterMode = (string) $request->input('filter', 'all');

        try {
            $evaluations = $this->overstockService->runAllForBranch($selectedBranchId);
        } catch (\Throwable $e) {
            Log::warning('Failed to auto-evaluate overstock & slow-moving items on page load: ' . $e->getMessage());
            $evaluations = [];
        }

        $totalProducts = count($evaluations);
        $overstockCount = count(array_filter($evaluations, fn (array $r) => (bool) ($r['is_overstock'] ?? false)));
        $slowMovingCount = count(array_filter($evaluations, fn (array $r) => (bool) ($r['is_slow_moving'] ?? false)));
        $totalSurplusUnits = array_sum(array_map(fn (array $r) => (float) ($r['overstock_units'] ?? 0.0), $evaluations));

        if ($filterMode === 'overstock') {
            $evaluations = array_values(array_filter($evaluations, fn (array $r) => (bool) ($r['is_overstock'] ?? false)));
        } elseif ($filterMode === 'slow_moving') {
            $evaluations = array_values(array_filter($evaluations, fn (array $r) => (bool) ($r['is_slow_moving'] ?? false)));
        } elseif ($filterMode === 'alerts_only') {
            $evaluations = array_values(array_filter($evaluations, fn (array $r) => (bool) ($r['requires_alert'] ?? false)));
        }

        // Sort: items requiring alert first (high severity -> medium -> low), then by overstock_units descending
        usort($evaluations, function (array $a, array $b): int {
            $alertA = (bool) ($a['requires_alert'] ?? false) ? 0 : 1;
            $alertB = (bool) ($b['requires_alert'] ?? false) ? 0 : 1;
            if ($alertA !== $alertB) {
                return $alertA <=> $alertB;
            }

            $surplusCmp = ((float) ($b['overstock_units'] ?? 0.0)) <=> ((float) ($a['overstock_units'] ?? 0.0));
            if ($surplusCmp !== 0) {
                return $surplusCmp;
            }

            return ((float) ($a['sell_through_rate'] ?? 0.0)) <=> ((float) ($b['sell_through_rate'] ?? 0.0));
        });

        $alerts = $this->overstockService->getAlertsForBranch($selectedBranchId, false);

        return view('overstock.index', [
            'user' => $user,
            'branches' => $branches,
            'selectedBranchId' => $selectedBranchId,
            'filterMode' => $filterMode,
            'evaluations' => $evaluations,
            'alerts' => $alerts,
            'totalProducts' => $totalProducts,
            'overstockCount' => $overstockCount,
            'slowMovingCount' => $slowMovingCount,
            'totalSurplusUnits' => $totalSurplusUnits,
            'windowDays' => $this->overstockService->resolveWindowDays(),
            'horizonDays' => $this->overstockService->resolveHorizonDays(),
            'slowMovingThreshold' => $this->overstockService->resolveSlowMovingThreshold(),
        ]);
    }
}

