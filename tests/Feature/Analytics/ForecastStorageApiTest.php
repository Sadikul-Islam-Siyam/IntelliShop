<?php

namespace Tests\Feature\Analytics;

use App\Models\Branch;
use App\Models\DemandForecast;
use App\Models\ModelRun;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForecastStorageApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_running_forecast_stores_demand_forecasts_and_model_runs_rows(): void
    {
        $this->seed(DemoDataSeeder::class);

        $analyst = User::query()->where('email', 'analyst@intellishop.com')->firstOrFail();
        $branch = Branch::query()->firstOrFail();
        $product = Product::query()->where('sku', 'MILK-1L-001')->firstOrFail();

        $response = $this->actingAs($analyst)->postJson('/api/v1/forecasts/run', [
            'product_id' => $product->id,
            'branch_id' => $branch->id,
            'horizon' => 10,
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'product',
                    'branch',
                    'model_run',
                    'model_used',
                    'confidence_score',
                    'sigma_d',
                    'horizon',
                    'metrics' => ['mae', 'rmse', 'smape'],
                    'baseline_model',
                    'baseline_smape',
                    'beats_baseline',
                    'forecasts' => [
                        '*' => [
                            'id',
                            'date',
                            'step',
                            'predicted_demand',
                            'lower_bound',
                            'upper_bound',
                            'model_used',
                            'model_run_id',
                            'confidence_score',
                        ],
                    ],
                ],
            ]);

        $modelRunId = $response->json('data.model_run.id');
        $this->assertNotNull($modelRunId);

        // Verify model_runs row persisted with selected=true
        $selectedRun = ModelRun::query()->findOrFail($modelRunId);
        $this->assertTrue($selectedRun->selected);
        $this->assertEquals($product->id, $selectedRun->product_id);
        $this->assertEquals($branch->id, $selectedRun->branch_id);

        // Verify 10 demand_forecasts rows persisted and linked to model_run_id
        $storedForecasts = DemandForecast::query()
            ->where('product_id', $product->id)
            ->where('branch_id', $branch->id)
            ->get();

        $this->assertCount(10, $storedForecasts);
        foreach ($storedForecasts as $row) {
            $this->assertEquals($selectedRun->id, $row->model_run_id);
            $this->assertEquals($selectedRun->model_used, $row->model_used);
            $this->assertGreaterThan(0, $row->confidence_score);
        }
    }

    public function test_forecasting_web_interface_renders_for_system_analyst(): void
    {
        $this->seed(DemoDataSeeder::class);

        $analyst = User::query()->where('email', 'analyst@intellishop.com')->firstOrFail();

        $response = $this->actingAs($analyst)->get('/forecasting');
        $response->assertOk()
            ->assertSee('Upcoming Demand Forecast')
            ->assertSee('MILK-1L-001');
    }

    public function test_cashier_cannot_trigger_forecast_run(): void
    {
        $this->seed(DemoDataSeeder::class);

        $cashier = User::query()->where('email', 'cashier@intellishop.com')->firstOrFail();
        $branch = Branch::query()->firstOrFail();
        $product = Product::query()->firstOrFail();

        $response = $this->actingAs($cashier)->postJson('/api/v1/forecasts/run', [
            'product_id' => $product->id,
            'branch_id' => $branch->id,
            'horizon' => 7,
        ]);

        $response->assertForbidden();
    }
}
