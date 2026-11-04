<?php

namespace App\Http\Controllers\Web;

use App\Contracts\SettingsService;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Product;
use App\Models\StockPrediction;
use App\Services\Analytics\DemandForecastService;
use App\Services\Analytics\StockRiskService;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StockRiskController extends Controller
{
    public function __construct(
        protected StockRiskService $stockRiskService,
        protected DemandForecastService $forecastService,
        protected SettingsService $settings
    ) {}

    public function index(Request $request): View
    {
        if (Product::query()->count() === 0 || Branch::query()->count() === 0) {
            app(DemoDataSeeder::class)->run();
        }

        $user = $request->user();
        $branches = Branch::query()->where('status', 'active')->get();

        $selectedBranchId = $request->query('branch_id')
            ? (int) $request->query('branch_id')
            : ($user?->branch_id ?? $branches->first()?->id ?? 1);

        $horizonDays = (int) $this->settings->get('forecast_horizon_days', 14);

        // Update using the exact same database records & service as demand forecasting
        $this->forecastService->ensureBranchForecasts($selectedBranchId, $horizonDays);
        $this->stockRiskService->ensureBranchPredictions($selectedBranchId, false, $horizonDays);

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
        $branch = Branch::query()->find($selectedBranchId);

        return view('stock_risk.index', [
            'user' => $user,
            'branch' => $branch,
            'branches' => $branches,
            'selectedBranchId' => $selectedBranchId,
            'selectedRisk' => $selectedRisk,
            'predictions' => $predictions,
            'products' => $products,
            'horizonDays' => $horizonDays,
        ]);
    }
}
