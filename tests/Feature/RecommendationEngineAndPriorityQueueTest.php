<?php

namespace Tests\Feature;

use App\Models\AiRecommendation;
use App\Models\Branch;
use App\Models\CycleCount;
use App\Models\InventoryBatch;
use App\Models\MarkdownRecommendation;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\Role;
use App\Models\SalesAnomaly;
use App\Models\StockPrediction;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\PriorityScorer;
use App\Services\RecommendationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecommendationEngineAndPriorityQueueTest extends TestCase
{
    use RefreshDatabase;

    protected User $analyst;
    protected Branch $branch;
    protected Product $product;
    protected RecommendationEngine $engine;
    protected PriorityScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();

        $analystRole = Role::firstOrCreate(['name' => Role::SYSTEM_ANALYST], ['description' => 'System Analyst']);

        $this->branch = Branch::create([
            'name' => 'Decision Engine Branch',
            'address' => '77 Analytics Way',
            'phone' => '555-7700',
            'status' => 'active',
        ]);

        $this->analyst = User::factory()->create([
            'role_id' => $analystRole->id,
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);

        $unit = Unit::firstOrCreate(['name' => 'Pack'], ['abbreviation' => 'pk']);
        $this->product = Product::create([
            'name' => 'Greek Yogurt 500g',
            'sku' => 'YGT-500',
            'unit_id' => $unit->id,
            'cost_price' => 3.50,
            'selling_price' => 5.50,
            'reorder_point' => 30,
            'status' => 'active',
        ]);

        $this->engine = app(RecommendationEngine::class);
        $this->scorer = app(PriorityScorer::class);
    }

    public function test_forward_chaining_if_then_rules_consume_all_analytical_inputs(): void
    {
        $facts = [
            'stock_risk' => [
                'product_id' => $this->product->id,
                'product_name' => $this->product->name,
                'branch_id' => $this->branch->id,
                'risk_level' => 'HIGH',
                'current_stock' => 2,
                'reorder_point' => 30,
                'safety_stock' => 10,
                'recommended_reorder_qty' => 80,
                'lead_time_days' => 3,
                'unit_price' => 3.50,
            ],
            'supplier_evaluation' => [
                'mode' => 'PANIC',
                'recommended_supplier' => [
                    'supplier_id' => 1,
                    'supplier_name' => 'Express Dairy Co',
                    'score' => 0.967,
                    'price' => 3.50,
                    'lead_time_days' => 2,
                ],
            ],
            'overstock' => [
                'product_id' => $this->product->id,
                'product_name' => 'Slow Cereal Box',
                'branch_id' => $this->branch->id,
                'available_stock' => 250,
                'forecast_demand_horizon' => 40,
                'overstock_units' => 210,
                'sell_through_rate' => 0.08,
                'is_overstock' => true,
                'is_slow_moving' => true,
                'severity' => 'high',
                'cost_price' => 4.00,
            ],
            'markdown' => [
                'id' => 10,
                'batch_id' => 5,
                'batch_no' => 'BATCH-YGT-09',
                'product_id' => $this->product->id,
                'branch_id' => $this->branch->id,
                'days_remaining' => 4,
                'available_qty' => 90,
                'projected_sales' => 20,
                'surplus_qty' => 70,
                'suggested_markdown_pct' => 35.0,
                'approval_authority' => 'Super Admin',
                'selling_price' => 5.50,
            ],
            'anomaly' => [
                'id' => 12,
                'product_id' => $this->product->id,
                'branch_id' => $this->branch->id,
                'detected_date' => now()->toDateString(),
                'expected_value' => 15.0,
                'actual_value' => 110.0,
                'deviation_score' => 6.12,
                'selling_price' => 5.50,
            ],
            'phantom' => [
                'id' => 15,
                'product_id' => $this->product->id,
                'branch_id' => $this->branch->id,
                'system_quantity' => 85,
                'baseline_mean' => 12.0,
                'baseline_std' => 3.0,
                'z_score' => -3.67,
                'cost_price' => 3.50,
            ],
        ];

        $recommendations = $this->engine->evaluateForwardChainingRules($facts);

        $this->assertCount(5, $recommendations);
        $types = array_column($recommendations, 'type');
        $this->assertContains('reorder', $types);
        $this->assertContains('overstock', $types);
        $this->assertContains('markdown', $types);
        $this->assertContains('anomaly', $types);
        $this->assertContains('phantom', $types);

        // Verify sorted descending by priority_score
        for ($i = 0; $i < count($recommendations) - 1; $i++) {
            $this->assertGreaterThanOrEqual(
                $recommendations[$i + 1]['priority_score'],
                $recommendations[$i]['priority_score']
            );
        }

        // Verify factors contract schema ({ rule, inputs, rationale, action })
        foreach ($recommendations as $rec) {
            $this->assertArrayHasKey('rule', $rec['factors']);
            $this->assertArrayHasKey('inputs', $rec['factors']);
            $this->assertArrayHasKey('rationale', $rec['factors']);
            $this->assertArrayHasKey('action', $rec['factors']);
        }
    }

    public function test_weighted_priority_queue_ordering_and_api_endpoints(): void
    {
        $this->actingAs($this->analyst);

        // Score comparison: CRITICAL high-value urgent item vs LOW risk low-value item
        $highScore = $this->scorer->score([
            'risk_level' => 'CRITICAL',
            'value_at_stake' => 950.0,
            'days_remaining' => 1,
            'horizon_days' => 14,
        ]);

        $lowScore = $this->scorer->score([
            'risk_level' => 'LOW',
            'value_at_stake' => 40.0,
            'days_remaining' => 12,
            'horizon_days' => 14,
        ]);

        $this->assertGreaterThan($lowScore['priority_score'], $highScore['priority_score']);
        $this->assertEquals('high', $highScore['priority']);
        $this->assertEquals('low', $lowScore['priority']);

        // Seed DB records for live engine run
        $supplier = Supplier::create(['name' => 'Prime Dairy', 'status' => 'active']);
        ProductSupplier::create([
            'product_id' => $this->product->id,
            'supplier_id' => $supplier->id,
            'supply_price' => 3.40,
            'lead_time_days' => 3,
            'min_order_qty' => 20,
            'order_multiple' => 10,
            'is_preferred' => true,
            'fulfillment_rate' => 0.95,
        ]);

        StockPrediction::create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'current_stock' => 5,
            'predicted_demand' => 90,
            'recommended_reorder_qty' => 70,
            'risk_level' => 'HIGH',
            'safety_stock' => 10,
            'reorder_point' => 35,
            'lead_time_days' => 3,
            'generated_at' => now(),
        ]);

        $batch = InventoryBatch::create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'batch_no' => 'B-YGT-101',
            'expiry_date' => now()->addDays(5)->toDateString(),
            'quantity_received' => 100,
            'quantity_remaining' => 80,
            'unit_cost' => 3.50,
            'status' => 'active',
        ]);

        MarkdownRecommendation::create([
            'batch_id' => $batch->id,
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'baseline_days' => 30,
            'avg_daily_sales' => 4.0,
            'days_remaining' => 5,
            'available_qty' => 80,
            'projected_sales' => 20.0,
            'surplus_qty' => 60.0,
            'suggested_markdown_pct' => 30.0,
            'max_markdown_pct' => 40.0,
            'status' => 'pending',
            'approval_authority' => 'Branch Manager',
            'generated_at' => now(),
        ]);

        CycleCount::create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'trigger_source' => 'phantom_alert',
            'baseline_mean' => 12.0,
            'baseline_std' => 3.0,
            'z_score' => -3.40,
            'system_quantity' => 60,
            'status' => 'open',
        ]);

        SalesAnomaly::create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'detected_date' => now()->toDateString(),
            'expected_value' => 10.0,
            'actual_value' => 75.0,
            'deviation_score' => 4.80,
            'status' => 'pending',
        ]);

        $runRes = $this->postJson('/api/v1/recommendations/run', [
            'branch_id' => $this->branch->id,
        ]);
        $runRes->assertStatus(200);
        $this->assertGreaterThanOrEqual(4, $runRes->json('count'));

        $queueRes = $this->getJson('/api/v1/recommendations?branch_id=' . $this->branch->id);
        $queueRes->assertStatus(200);
        $queueData = $queueRes->json('data');
        $this->assertNotEmpty($queueData);

        // Verify highest priority_score is first
        $scores = array_column($queueData, 'priority_score');
        $sortedScores = $scores;
        rsort($sortedScores);
        $this->assertEquals($sortedScores, $scores);
    }
}
