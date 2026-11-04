<?php

namespace Tests\Feature\Analytics;

use App\Models\AiRecommendation;
use App\Models\Branch;
use App\Models\DemandForecast;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\StockPrediction;
use App\Models\User;
use App\Services\Analytics\StockRiskService;
use Carbon\Carbon;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockRiskTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_risk_classification_mathematical_rules(): void
    {
        $this->seed(DemoDataSeeder::class);

        /** @var StockRiskService $service */
        $service = app(StockRiskService::class);

        $leadTime = 4;
        $reviewPeriod = 7;

        // Rule 1: No ESD -> LOW
        $this->assertEquals('LOW', $service->classifyRisk(null, $leadTime, $reviewPeriod));

        // Rule 2: ESD <= today + L -> HIGH
        // Today + 2 days <= 4 -> HIGH
        $highDate = now()->addDays(2)->format('Y-m-d');
        $this->assertEquals('HIGH', $service->classifyRisk($highDate, $leadTime, $reviewPeriod));

        // Exactly on today + L (4 days) -> HIGH
        $exactLeadDate = now()->addDays(4)->format('Y-m-d');
        $this->assertEquals('HIGH', $service->classifyRisk($exactLeadDate, $leadTime, $reviewPeriod));

        // Rule 3: ESD <= today + L + R -> MEDIUM
        // Today + 8 days (4 < 8 <= 11) -> MEDIUM
        $mediumDate = now()->addDays(8)->format('Y-m-d');
        $this->assertEquals('MEDIUM', $service->classifyRisk($mediumDate, $leadTime, $reviewPeriod));

        // Exactly on today + L + R (11 days) -> MEDIUM
        $exactReviewDate = now()->addDays(11)->format('Y-m-d');
        $this->assertEquals('MEDIUM', $service->classifyRisk($exactReviewDate, $leadTime, $reviewPeriod));

        // Rule 4: ESD > today + L + R -> LOW
        // Today + 15 days (> 11) -> LOW
        $lowDate = now()->addDays(15)->format('Y-m-d');
        $this->assertEquals('LOW', $service->classifyRisk($lowDate, $leadTime, $reviewPeriod));
    }

    public function test_running_stock_risk_stores_prediction_and_generates_recommendations(): void
    {
        $this->seed(DemoDataSeeder::class);

        $analyst = User::query()->where('email', 'analyst@intellishop.com')->firstOrFail();
        $branch = Branch::query()->firstOrFail();
        $product = Product::query()->where('sku', 'MILK-1L-001')->firstOrFail();

        // Adjust inventory to simulate low stock triggering high risk
        Inventory::query()->updateOrCreate(
            ['product_id' => $product->id, 'branch_id' => $branch->id],
            ['quantity_available' => 15, 'quantity_reserved' => 0]
        );

        $response = $this->actingAs($analyst)->postJson('/api/v1/stock-risk/run', [
            'product_id' => $product->id,
            'branch_id' => $branch->id,
            'horizon' => 14,
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'product' => ['id', 'name', 'sku'],
                    'branch' => ['id', 'name', 'code'],
                    'prediction' => [
                        'id',
                        'product_id',
                        'branch_id',
                        'current_stock',
                        'predicted_demand',
                        'predicted_stockout_date',
                        'risk_level',
                        'lead_time_days',
                    ],
                    'projection',
                ],
            ]);

        // Verify stock_predictions row persisted
        $stored = StockPrediction::query()
            ->where('product_id', $product->id)
            ->where('branch_id', $branch->id)
            ->first();

        $this->assertNotNull($stored);
        $this->assertEquals($product->id, $stored->product_id);
        $this->assertEquals($branch->id, $stored->branch_id);
        $this->assertContains($stored->risk_level, ['HIGH', 'MEDIUM', 'LOW']);
        $this->assertNotNull($stored->lead_time_days);

        // If risk is HIGH or MEDIUM, verify ai_recommendations row
        if (in_array($stored->risk_level, ['HIGH', 'MEDIUM'], true)) {
            $recommendation = AiRecommendation::query()
                ->where('type', 'STOCK_OUT_RISK')
                ->where('related_entity_id', $product->id)
                ->where('status', 'open')
                ->first();

            $this->assertNotNull($recommendation);
            $this->assertEquals(strtolower($stored->risk_level), $recommendation->priority);
        }
    }

    public function test_cashier_cannot_trigger_stock_risk_run(): void
    {
        $this->seed(DemoDataSeeder::class);

        $cashier = User::query()->where('email', 'cashier@intellishop.com')->firstOrFail();
        $branch = Branch::query()->firstOrFail();
        $product = Product::query()->firstOrFail();

        $response = $this->actingAs($cashier)->postJson('/api/v1/stock-risk/run', [
            'product_id' => $product->id,
            'branch_id' => $branch->id,
        ]);

        $response->assertForbidden();
    }

    public function test_stock_risk_web_interface_renders_for_system_analyst(): void
    {
        $this->seed(DemoDataSeeder::class);

        $analyst = User::query()->where('email', 'analyst@intellishop.com')->firstOrFail();

        $response = $this->actingAs($analyst)->get('/stock-risk');
        $response->assertOk()
            ->assertSee('Stock-Out Risk Classification')
            ->assertSee('Automatic Sync');
    }

    public function test_inventory_change_automatically_triggers_stock_risk_update(): void
    {
        $this->seed(DemoDataSeeder::class);

        $branch = Branch::query()->firstOrFail();
        $product = Product::query()->where('sku', 'MILK-1L-001')->firstOrFail();

        // Simulate an inventory reduction (e.g. sale or deduction)
        $inventory = Inventory::query()->where('product_id', $product->id)->where('branch_id', $branch->id)->firstOrFail();
        $inventory->quantity_available = 5;
        $inventory->save();

        $prediction = StockPrediction::query()
            ->where('product_id', $product->id)
            ->where('branch_id', $branch->id)
            ->first();

        $this->assertNotNull($prediction);
        $this->assertEquals(5.0, (float) $prediction->current_stock);
        $this->assertEquals('HIGH', $prediction->risk_level);
    }
}
