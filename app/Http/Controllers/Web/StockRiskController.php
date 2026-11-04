<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Product;
use App\Models\StockPrediction;
use App\Services\Analytics\StockRiskService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StockRiskController extends Controller
{
    public function __construct(
        protected StockRiskService $stockRiskService
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $branches = Branch::query()->where('status', 'active')->get();

        $selectedBranchId = $request->query('branch_id')
            ? (int) $request->query('branch_id')
            : ($user?->branch_id ?? $branches->first()?->id ?? 1);

        // Automatically ensure projections for all active products are generated and fresh
        $this->stockRiskService->ensureBranchPredictions($selectedBranchId);

        $selectedRisk = $request->query('risk_level')
            ? strtoupper($request->query('risk_level'))
            : null;

        $query = StockPrediction::query()
            ->with(['product', 'branch'])
            ->where('branch_id', $selectedBranchId);

        if ($selectedRisk) {
            $query->where('risk_level', $selectedRisk);
        }

        $predictions = $query->orderByRaw("
            CASE risk_level
                WHEN 'HIGH' THEN 1
                WHEN 'MEDIUM' THEN 2
                ELSE 3
            END ASC
        ")->orderBy('predicted_stockout_date', 'asc')->get();

        $products = Product::query()->where('status', 'active')->orderBy('name')->get();

        return view('stock_risk.index', [
            'user' => $user,
            'branches' => $branches,
            'selectedBranchId' => $selectedBranchId,
            'selectedRisk' => $selectedRisk,
            'predictions' => $predictions,
            'products' => $products,
            'highCount' => $predictions->where('risk_level', 'HIGH')->count(),
            'mediumCount' => $predictions->where('risk_level', 'MEDIUM')->count(),
            'lowCount' => $predictions->where('risk_level', 'LOW')->count(),
        ]);
    }
}
