<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\SalesAnomaly;
use App\Services\AuditLogger;
use App\Services\SalesAnomalyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AnomalyController extends Controller
{
    public function __construct(
        protected SalesAnomalyService $anomalyService
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $branches = Branch::query()->where('status', 'active')->orderBy('name')->get();
        $selectedBranchId = $request->filled('branch_id') ? (int) $request->input('branch_id') : $user?->branch_id;
        $selectedStatus = $request->input('status', '');

        $query = SalesAnomaly::query()
            ->with(['product:id,name,sku,category_id', 'branch:id,name'])
            ->when($selectedBranchId, fn ($q) => $q->where('branch_id', $selectedBranchId))
            ->when($selectedStatus !== '', fn ($q) => $q->where('status', $selectedStatus))
            ->latest('detected_date');

        $anomalies = $query->get()->map(function (SalesAnomaly $anomaly) {
            $anomaly->setAttribute('context', $this->anomalyService->buildContextForAnomaly($anomaly));
            return $anomaly;
        });

        return view('anomalies.index', [
            'user' => $user,
            'branches' => $branches,
            'selectedBranchId' => $selectedBranchId,
            'selectedStatus' => $selectedStatus,
            'anomalies' => $anomalies,
        ]);
    }

    public function review(Request $request, SalesAnomaly $anomaly): RedirectResponse
    {
        $validated = $request->validate([
            'action' => 'required|string|in:acknowledge,acknowledged,explain,explained,dismiss,dismissed',
            'notes' => 'nullable|string|max:500',
        ]);

        $normalized = match (strtolower($validated['action'])) {
            'acknowledge', 'acknowledged' => 'acknowledged',
            'explain', 'explained' => 'explained',
            'dismiss', 'dismissed' => 'dismissed',
        };

        $anomaly->update([
            'status' => $normalized,
            'notes' => $validated['notes'] ?? $anomaly->notes,
        ]);

        AuditLogger::log(
            $request->user()?->id,
            'anomaly.review',
            'sales_anomalies',
            $anomaly->id,
            [
                'action' => $normalized,
                'notes' => $validated['notes'] ?? null,
            ],
            $request->ip()
        );

        return redirect()->back()->with('success', "Anomaly #{$anomaly->id} marked as {$normalized}.");
    }
}
