<?php

namespace App\Http\Controllers\Web;

use App\Contracts\SettingsService;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Product;
use App\Services\Analytics\DemandForecastService;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Web controller for the demand forecasting workspace.
 */
class ForecastController extends Controller
{
    public function __construct(
        private readonly DemandForecastService $forecastService,
        private readonly SettingsService $settings,
    ) {}

    public function index(Request $request): View
    {
        if (Product::query()->count() === 0 || Branch::query()->count() === 0) {
            app(DemoDataSeeder::class)->run();
        }

        $user = $request->user();
        $branch = ($user && $user->branch_id)
            ? Branch::query()->find($user->branch_id)
            : Branch::query()->where('status', 'active')->orderBy('id')->first();

        $branchId = (int) ($branch?->id ?? 1);
        $horizonDays = (int) $this->settings->get('forecast_horizon_days', 14);

        $productForecasts = $this->forecastService->ensureBranchForecasts($branchId, $horizonDays);

        return view('forecasts.index', [
            'user' => $user,
            'branch' => $branch,
            'horizonDays' => $horizonDays,
            'productForecasts' => $productForecasts,
        ]);
    }
}
