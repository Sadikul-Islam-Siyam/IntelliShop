<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Discount;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesAnomaly;
use App\Models\Unit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnomalyReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $branchManager;
    protected Branch $branch;
    protected Product $product;
    protected SalesAnomaly $anomaly;

    protected function setUp(): void
    {
        parent::setUp();

        $bmRole = Role::firstOrCreate(['name' => Role::BRANCH_MANAGER], ['description' => 'Branch Manager']);

        $this->branch = Branch::create([
            'name' => 'Anomaly Workflow Branch',
            'address' => '12 Station Rd',
            'phone' => '555-4455',
            'status' => 'active',
        ]);

        $this->branchManager = User::factory()->create([
            'role_id' => $bmRole->id,
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);

        $unit = Unit::firstOrCreate(['name' => 'Bottle'], ['abbreviation' => 'btl']);
        $this->product = Product::create([
            'name' => 'Sparkling Water 1L',
            'sku' => 'SPK-1L',
            'unit_id' => $unit->id,
            'cost_price' => 0.80,
            'selling_price' => 1.50,
            'status' => 'active',
        ]);

        // Set up context: active discount and zero inventory (stock-out context)
        Inventory::updateOrCreate(
            ['product_id' => $this->product->id, 'branch_id' => $this->branch->id],
            ['quantity_available' => 0, 'quantity_reserved' => 0]
        );

        Discount::create([
            'name' => 'Weekend Hydration Promo',
            'type' => 'percentage',
            'value' => 20.00,
            'product_id' => $this->product->id,
            'starts_at' => Carbon::today()->subDays(5),
            'ends_at' => Carbon::today()->addDays(5),
            'status' => 'active',
        ]);

        $this->anomaly = SalesAnomaly::create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'detected_date' => Carbon::today()->subDay()->toDateString(),
            'expected_value' => 12.00,
            'actual_value' => 95.00,
            'deviation_score' => 5.420,
            'status' => 'pending',
            'notes' => 'Modified Z-Score: 5.42 (> 3.5); IQR Fence [8.0, 18.0]',
        ]);
    }

    public function test_anomaly_details_include_context_and_signal_disclaimer(): void
    {
        $this->actingAs($this->branchManager);

        $res = $this->getJson("/api/v1/anomalies/{$this->anomaly->id}");
        $res->assertStatus(200);
        $res->assertJsonPath('context.stock_out_detected', true);
        $res->assertJsonPath('context.has_active_discount', true);
        $this->assertStringContainsString(
            'statistical screening signal',
            (string) $res->json('context.signal_notice')
        );
    }

    public function test_acknowledge_explain_and_dismiss_actions_update_status_and_write_audit_logs(): void
    {
        $this->actingAs($this->branchManager);

        // 1. Acknowledge
        $ackRes = $this->postJson("/api/v1/anomalies/{$this->anomaly->id}/review", [
            'action' => 'acknowledge',
            'notes' => 'Investigating POS receipts for bulk order.',
        ]);
        $ackRes->assertStatus(200);
        $this->assertEquals('acknowledged', $this->anomaly->fresh()->status);

        // 2. Explain
        $expRes = $this->postJson("/api/v1/anomalies/{$this->anomaly->id}/review", [
            'action' => 'explain',
            'notes' => 'Explained by 20% Weekend Hydration Promo and corporate event purchase.',
        ]);
        $expRes->assertStatus(200);
        $this->assertEquals('explained', $this->anomaly->fresh()->status);
        $this->assertStringContainsString('Hydration Promo', (string) $this->anomaly->fresh()->notes);

        // 3. Dismiss
        $disRes = $this->postJson("/api/v1/anomalies/{$this->anomaly->id}/review", [
            'action' => 'dismiss',
            'notes' => 'False alarm dismissed after verification.',
        ]);
        $disRes->assertStatus(200);
        $this->assertEquals('dismissed', $this->anomaly->fresh()->status);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->branchManager->id,
            'action' => 'anomaly.review',
            'entity_id' => $this->anomaly->id,
        ]);
    }
}
