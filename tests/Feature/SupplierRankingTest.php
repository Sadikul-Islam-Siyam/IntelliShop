<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierRankingTest extends TestCase
{
    use RefreshDatabase;

    protected User $purchaseManager;
    protected Branch $branch;
    protected Product $product;
    protected Supplier $supplierA;
    protected Supplier $supplierB;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => Role::PURCHASE_MANAGER], ['description' => 'Purchase Manager']);
        $this->branch = Branch::firstOrCreate(
            ['name' => 'Procurement Central'],
            ['address' => 'HQ', 'phone' => '999999', 'status' => 'active']
        );

        $this->purchaseManager = User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);

        $unit = Unit::firstOrCreate(['name' => 'Case'], ['abbreviation' => 'cs']);
        $this->product = Product::create([
            'name' => 'Almond Milk 1L',
            'sku' => 'MLK-ALM-1L',
            'selling_price' => 4.50,
            'cost_price' => 3.00,
            'unit_id' => $unit->id,
            'status' => 'active',
        ]);

        // Supplier A: Lower price, good lead time
        $this->supplierA = Supplier::create([
            'name' => 'Alpha Distributors',
            'status' => 'active',
        ]);
        ProductSupplier::create([
            'product_id' => $this->product->id,
            'supplier_id' => $this->supplierA->id,
            'supply_price' => 2.50,
            'lead_time_days' => 5,
            'min_order_qty' => 5,
            'is_preferred' => true,
        ]);

        // Supplier B: Slightly higher price, faster lead time
        $this->supplierB = Supplier::create([
            'name' => 'Beta Logistics',
            'status' => 'active',
        ]);
        ProductSupplier::create([
            'product_id' => $this->product->id,
            'supplier_id' => $this->supplierB->id,
            'supply_price' => 3.20,
            'lead_time_days' => 2,
            'min_order_qty' => 5,
            'is_preferred' => false,
        ]);
    }

    public function test_ranking_api_returns_top_alternatives_and_score_breakdown()
    {
        $this->actingAs($this->purchaseManager);

        $res = $this->getJson("/api/v1/suppliers/ranking?product_id={$this->product->id}&branch_id={$this->branch->id}");
        $res->assertStatus(200);

        $data = $res->json('data');
        $this->assertNotEmpty($data['top_supplier']);
        $this->assertNotEmpty($data['alternatives']);
        $this->assertNotEmpty($data['active_mode']);
        $this->assertNotEmpty($data['score_breakdown']);

        // Check top supplier fields
        $top = $data['top_supplier'];
        $this->assertArrayHasKey('supplier_id', $top);
        $this->assertArrayHasKey('score', $top);

        // Check breakdown contains raw & normalized metrics
        $breakdown = $data['score_breakdown'];
        $this->assertCount(2, $breakdown);
        $this->assertArrayHasKey('raw', $breakdown[0]);
        $this->assertArrayHasKey('normalized', $breakdown[0]);
        $this->assertArrayHasKey('total_saw_score', $breakdown[0]);
    }

    public function test_weight_mode_selection_and_weights()
    {
        $this->actingAs($this->purchaseManager);

        // Normal mode
        $resNormal = $this->getJson("/api/v1/suppliers/ranking?product_id={$this->product->id}&branch_id={$this->branch->id}&mode=NORMAL");
        $resNormal->assertStatus(200);
        $this->assertEquals('NORMAL', $resNormal->json('data.active_mode'));
        $this->assertEquals(0.70, $resNormal->json('data.weights.price'));

        // Panic mode
        $resPanic = $this->getJson("/api/v1/suppliers/ranking?product_id={$this->product->id}&branch_id={$this->branch->id}&mode=PANIC");
        $resPanic->assertStatus(200);
        $this->assertEquals('PANIC', $resPanic->json('data.active_mode'));
        $this->assertEquals(0.10, $resPanic->json('data.weights.price'));
        $this->assertEquals(0.70, $resPanic->json('data.weights.speed'));
    }

    public function test_purchase_manager_can_override_recommended_supplier()
    {
        $this->actingAs($this->purchaseManager);

        // Query with override_supplier_id = supplierB
        $res = $this->getJson("/api/v1/suppliers/ranking?product_id={$this->product->id}&branch_id={$this->branch->id}&override_supplier_id={$this->supplierB->id}");
        $res->assertStatus(200);

        $data = $res->json('data');
        $this->assertEquals($this->supplierB->id, $data['selected_supplier']['supplier_id']);
        $this->assertTrue($data['is_overridden']);

        // Test explicit override submission and audit logging
        $postRes = $this->postJson('/api/v1/suppliers/ranking/override', [
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'chosen_supplier_id' => $this->supplierB->id,
            'recommended_supplier_id' => $this->supplierA->id,
            'reason' => 'Urgent expedited shipment needed due to local holiday rush.',
        ]);
        $postRes->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->purchaseManager->id,
            'action' => 'supplier.ranking_override',
        ]);
    }
}
