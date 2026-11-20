<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CycleCount;
use App\Services\ReconciliationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CycleCountController extends Controller
{
    public function __construct(
        protected ReconciliationService $reconciliationService
    ) {}

    public function index(Request $request): View
    {
        $query = CycleCount::with(['product', 'branch', 'batch', 'countedBy', 'reviewedBy'])
            ->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', (int) $request->input('branch_id'));
        }

        $cycleCounts = $query->paginate(20)->withQueryString();
        $branches = Branch::all();

        return view('cycle_counts.index', [
            'cycleCounts' => $cycleCounts,
            'branches' => $branches,
            'selectedStatus' => $request->input('status'),
            'selectedBranch' => $request->input('branch_id'),
        ]);
    }

    public function recordCount(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'physical_quantity' => 'required|integer|min:0',
            'variance_reason' => 'nullable|string|max:255',
        ]);

        $cycleCount = CycleCount::findOrFail($id);

        $this->reconciliationService->recordPhysicalCount(
            $cycleCount,
            (int) $validated['physical_quantity'],
            (int) ($request->user()?->id ?: 1),
            $validated['variance_reason'] ?? null
        );

        return redirect()->back()->with('success', 'Physical count submitted and variance computed.');
    }

    public function approve(Request $request, int $id): RedirectResponse
    {
        $cycleCount = CycleCount::findOrFail($id);

        $this->reconciliationService->approveVariance(
            $cycleCount,
            (int) ($request->user()?->id ?: 1),
            $request->input('notes')
        );

        return redirect()->back()->with('success', 'Cycle count approved and stock adjustment posted.');
    }

    public function reject(Request $request, int $id): RedirectResponse
    {
        $cycleCount = CycleCount::findOrFail($id);

        $this->reconciliationService->rejectVariance(
            $cycleCount,
            (int) ($request->user()?->id ?: 1),
            $request->input('reason')
        );

        return redirect()->back()->with('success', 'Cycle count variance rejected.');
    }
}
