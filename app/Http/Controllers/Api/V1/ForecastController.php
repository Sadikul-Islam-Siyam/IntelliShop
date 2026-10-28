<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\DemandForecast;
use App\Models\ModelRun;
use App\Models\Product;
use App\Services\Analytics\DemandForecastService;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * REST API controller for demand forecasts and model evaluation runs.
 */
class ForecastController extends Controller
{
    public function __construct(
        private readonly DemandForecastService $forecastService,
    ) {}

    /**
     * List stored demand forecasts and latest model run metadata for a product-branch.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $user = $request->user();
        $branchId = isset($validated['branch_id']) ? (int) $validated['branch_id'] : null;

        if ($user && !$user->isSuperAdmin() && !$user->hasRole(['system-analyst', 'purchase-manager'])) {
            $branchId = $user->branch_id ? (int) $user->branch_id : $branchId;
        }

        if ($branchId === null) {
            $branchId = (int) (Branch::query()->where('status', 'active')->value('id') ?? 1);
        }

        $productId = isset($validated['product_id'])
            ? (int) $validated['product_id']
            : (int) (Product::query()->where('status', 'active')->orderBy('id')->value('id') ?? 1);

        $forecasts = DemandForecast::query()
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->orderBy('forecast_date')
            ->get();

        // Auto-generate initial baseline forecast if none stored yet and product/branch exist
        if ($forecasts->isEmpty() && Product::whereKey($productId)->exists() && Branch::whereKey($branchId)->exists()) {
            $runPayload = $this->forecastService->runForecast($productId, $branchId);
            return response()->json(['data' => $runPayload]);
        }

        $selectedRun = ModelRun::query()
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->where('selected', true)
            ->latest('id')
            ->first();

        $sigmaD = (float) ($selectedRun?->params['sigma_d'] ?? ($selectedRun?->rmse ?? 0.0));
        $zValue = (float) ($selectedRun?->params['service_level_z'] ?? 1.645);

        $formattedForecasts = $forecasts->values()->map(function (DemandForecast $row, int $idx) use ($sigmaD, $zValue) {
            $step = $idx + 1;
            $halfWidth = $zValue * $sigmaD * sqrt($step);
            $pred = (float) $row->predicted_demand;
            $dateStr = $row->forecast_date instanceof \DateTimeInterface
                ? $row->forecast_date->format('Y-m-d')
                : (string) $row->forecast_date;

            return [
                'id' => $row->id,
                'date' => $dateStr,
                'weekday' => CarbonImmutable::parse($dateStr)->format('D'),
                'step' => $step,
                'predicted_demand' => round($pred, 2),
                'lower_bound' => max(0.0, round($pred - $halfWidth, 2)),
                'upper_bound' => round($pred + $halfWidth, 2),
                'model_used' => $row->model_used,
                'model_run_id' => $row->model_run_id,
                'confidence_score' => round((float) $row->confidence_score, 2),
            ];
        });

        $recentRuns = ModelRun::query()
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $history = array_slice($this->forecastService->extractDailyHistory($productId, $branchId, 42), -42);

        return response()->json([
            'data' => [
                'product' => Product::query()->find($productId, ['id', 'name', 'sku', 'barcode', 'selling_price']),
                'branch' => Branch::query()->find($branchId, ['id', 'name']),
                'model_run' => $selectedRun,
                'model_used' => $selectedRun?->model_used ?? ($forecasts->first()?->model_used ?? 'seasonal_naive'),
                'confidence_score' => (float) ($forecasts->first()?->confidence_score ?? 0.0),
                'sigma_d' => round($sigmaD, 4),
                'service_level_z' => $zValue,
                'horizon' => $forecasts->count(),
                'metrics' => [
                    'mae' => (float) ($selectedRun?->mae ?? 0.0),
                    'rmse' => (float) ($selectedRun?->rmse ?? 0.0),
                    'smape' => (float) ($selectedRun?->smape ?? 0.0),
                    'mape' => (float) ($selectedRun?->params['mape'] ?? 0.0),
                ],
                'baseline_model' => $selectedRun?->baseline_model ?? 'seasonal_naive',
                'baseline_smape' => (float) ($selectedRun?->baseline_smape ?? 0.0),
                'beats_baseline' => (bool) ($selectedRun?->beats_baseline ?? false),
                'candidates' => $recentRuns->map(fn (ModelRun $r) => [
                    'id' => $r->id,
                    'model' => $r->model_used,
                    'mae' => $r->mae,
                    'rmse' => $r->rmse,
                    'smape' => $r->smape,
                    'mape' => $r->params['mape'] ?? 0.0,
                    'baseline_model' => $r->baseline_model,
                    'baseline_smape' => $r->baseline_smape,
                    'beats_baseline' => $r->beats_baseline,
                    'selected' => $r->selected,
                    'trained_at' => $r->trained_at,
                ]),
                'history' => $history,
                'forecasts' => $formattedForecasts,
            ],
        ]);
    }

    /**
     * Trigger a new demand forecast run for a product and branch (POST /api/v1/forecasts/run).
     */
    public function run(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'horizon' => ['nullable', 'integer', 'min:1', 'max:60'],
            'preferred_model' => ['nullable', 'string', 'in:auto,naive,seasonal_naive,sma,wma,weekday_index,holt_winters,croston_sba,croston,linear_trend'],
        ]);

        $preferred = ($validated['preferred_model'] ?? 'auto') === 'auto'
            ? null
            : (string) $validated['preferred_model'];

        $result = $this->forecastService->runForecast(
            productId: (int) $validated['product_id'],
            branchId: (int) $validated['branch_id'],
            horizonDays: isset($validated['horizon']) ? (int) $validated['horizon'] : null,
            preferredModel: $preferred,
        );

        if ($request->user()) {
            AuditLogger::log(
                $request->user()->id,
                'forecasts.run',
                'model_runs',
                $result['model_run']?->id,
                [
                    'product_id' => (int) $validated['product_id'],
                    'branch_id' => (int) $validated['branch_id'],
                    'model_used' => $result['model_used'],
                    'smape' => $result['metrics']['smape'] ?? null,
                ]
            );
        }

        return response()->json([
            'data' => $result,
        ], Response::HTTP_CREATED);
    }

    /**
     * Run demand forecasts across all active products for a branch.
     */
    public function runAll(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'horizon' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);

        $branchId = (int) $validated['branch_id'];
        $horizon = isset($validated['horizon']) ? (int) $validated['horizon'] : null;

        $products = Product::query()->where('status', 'active')->orderBy('id')->get(['id', 'name', 'sku']);
        $summaries = [];

        foreach ($products as $product) {
            $res = $this->forecastService->runForecast($product->id, $branchId, $horizon);
            $summaries[] = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'sku' => $product->sku,
                'model_used' => $res['model_used'],
                'smape' => $res['metrics']['smape'] ?? 0.0,
                'rmse' => $res['metrics']['rmse'] ?? 0.0,
                'mae' => $res['metrics']['mae'] ?? 0.0,
                'sigma_d' => $res['sigma_d'],
                'confidence_score' => $res['confidence_score'],
                'beats_baseline' => $res['beats_baseline'],
                'total_horizon_demand' => round(collect($res['forecasts'])->sum('predicted_demand'), 2),
            ];
        }

        return response()->json([
            'data' => $summaries,
            'meta' => [
                'branch_id' => $branchId,
                'products_processed' => count($summaries),
            ],
        ]);
    }
}
