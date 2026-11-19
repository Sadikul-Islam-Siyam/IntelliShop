<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesAnomaly;
use App\Models\Unit;
use App\Models\User;
use App\Services\SalesAnomalyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesAnomalyDetectionTest extends TestCase
{
    use RefreshDatabase;

    protected User $analyst;
    protected Branch $branch;
    protected Product $product;
    protected SalesAnomalyService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => Role::SYSTEM_ANALYST], ['description' => 'System Analyst']);
        $this->branch = Branch::firstOrCreate(
            ['name' => 'Anomaly Detection Branch'],
            ['address' => 'Lab Rd', 'phone' => '123123', 'status' => 'active']
        );

        $this->analyst = User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);

        $unit = Unit::firstOrCreate(['name' => 'Box'], ['abbreviation' => 'bx']);
        $this->product = Product::create([
            'name' => 'Snack Crackers 200g',
            'sku' => 'CRK-200',
            'selling_price' => 2.50,
            'cost_price' => 1.20,
            'unit_id' => $unit->id,
            'status' => 'active',
        ]);

        $this->service = app(SalesAnomalyService::class);
    }

    public function test_injected_spike_flagged_by_modified_zscore_and_iqr_fence()
    {
        // 10 days of normal baseline sales (around 10 units each)
        for ($i = 15; $i >= 2; $i--) {
            $date = Carbon::today()->subDays($i);
            $sale = Sale::create([
                'invoice_no' => "INV-NORM-{$i}",
                'branch_id' => $this->branch->id,
                'cashier_id' => $this->analyst->id,
                'subtotal' => 25.00,
                'total_amount' => 25.00,
                'payment_method' => 'cash',
                'status' => 'completed',
                'created_at' => $date,
            ]);

            SaleItem::create([
                'sale_id' => $sale->id,
                'product_id' => $this->product->id,
                'quantity' => 10,
                'unit_price' => 2.50,
                'subtotal' => 25.00,
                'created_at' => $date,
            ]);
        }

        // Inject severe spike (100 units) on yesterday
        $spikeDate = Carbon::today()->subDay();
        $spikeSale = Sale::create([
            'invoice_no' => 'INV-SPIKE-001',
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->analyst->id,
            'subtotal' => 250.00,
            'total_amount' => 250.00,
            'payment_method' => 'cash',
            'status' => 'completed',
            'created_at' => $spikeDate,
        ]);

        SaleItem::create([
            'sale_id' => $spikeSale->id,
            'product_id' => $this->product->id,
            'quantity' => 100,
            'unit_price' => 2.50,
            'subtotal' => 250.00,
            'created_at' => $spikeDate,
        ]);

        $anomalies = $this->service->runDetection($this->product->id, $this->branch->id);

        $this->assertNotEmpty($anomalies);
        $flagged = $anomalies->first(fn($a) => Carbon::parse($a->detected_date)->isSameDay($spikeDate));
        $this->assertNotNull($flagged);
        $this->assertEquals(100.0, (float) $flagged->actual_value);
        $this->assertEquals(10.0, (float) $flagged->expected_value);
        $this->assertGreaterThan(3.5, (float) $flagged->deviation_score);

        $this->assertDatabaseHas('sales_anomalies', [
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'detected_date' => $spikeDate->toDateString(),
            'actual_value' => 100.00,
        ]);
    }

    public function test_manager_review_workflow()
    {
        $this->actingAs($this->analyst);

        $anomaly = SalesAnomaly::create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'detected_date' => Carbon::today()->subDays(2)->toDateString(),
            'expected_value' => 10.00,
            'actual_value' => 85.00,
            'deviation_score' => 4.250,
            'status' => 'pending',
            'notes' => 'Unusual bulk purchase detected',
        ]);

        // Review action: explain
        $res = $this->postJson("/api/v1/anomalies/{$anomaly->id}/review", [
            'action' => 'explained',
            'notes' => 'Local sports team purchased in bulk for weekend tournament.',
        ]);

        $res->assertStatus(200);
        $this->assertEquals('explained', $anomaly->fresh()->status);
        $this->assertStringContainsString('sports team', $anomaly->fresh()->notes);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->analyst->id,
            'action' => 'anomaly.review',
        ]);
    }

    public function test_api_anomalies_run_and_index()
    {
        $this->actingAs($this->analyst);

        $res = $this->postJson('/api/v1/anomalies/run', [
            'branch_id' => $this->branch->id,
        ]);
        $res->assertStatus(200);
        $res->assertJsonStructure(['message', 'count', 'data']);

        $listRes = $this->getJson('/api/v1/anomalies');
        $listRes->assertStatus(200);
    }
}
