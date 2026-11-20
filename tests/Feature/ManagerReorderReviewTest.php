<?php

namespace Tests\Feature;

use App\Models\AiRecommendation;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\StockPrediction;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagerReorderReviewTest extends TestCase
{
    use RefreshDatabase;

    protected User $purchaseManager;
    protected Branch $branch;
    protected Product $product;
    protected Supplier $supplier;
    protected StockPrediction $prediction;
    protected AiRecommendation $recommendation;

    protected function setUp(): void
    {
        parent::setUp();

        $pmRole = Role::firstOrCreate(['name' => Role::PURCHASE_MANAGER], ['description' => 'Purchase Manager']);

        $this->branch = Branch::create([
            'name' => 'Central Review Branch',
            'address' => '45 Market Rd',
            'phone' => '555-3000',
            'status' => 'active',
        ]);

        $this->purchaseManager = User::factory()->create([
            'role_id' => $pmRole->id,
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);

        $unit = Unit::firstOrCreate(['name' => 'Carton'], ['abbreviation' => 'ctn']);
        $this->product = Product::create([
            'name' => 'Basmati Rice 5kg',
            'sku' => 'RCE-5KG',
            'unit_id' => $unit->id,
            'cost_price' => 12.00,
            'selling_price' => 16.50,
            'reorder_point' => 40,
            'reorder_quantity' => 120,
            'status' => 'active',
        ]);

        $this->supplier = Supplier::create([
            'name' => 'Golden Grain Distributors',
            'contact_person' => 'Mr. Karim',
            'phone' => '555-8899',
            'status' => 'active',
        ]);

        ProductSupplier::create([
            'product_id' => $this->product->id,
            'supplier_id' => $this->supplier->id,
            'supply_price' => 11.50,
            'lead_time_days' => 4,
            'min_order_qty' => 50,
            'order_multiple' => 10,
            'is_preferred' => true,
            'fulfillment_rate' => 0.96,
        ]);

        $this->prediction = StockPrediction::create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'predicted_stockout_date' => now()->addDays(3)->toDateString(),
            'current_stock' => 25,
            'predicted_demand' => 220,
            'recommended_reorder_qty' => 180,
            'risk_level' => 'HIGH',
            'safety_stock' => 16.45,
            'reorder_point' => 96.45,
            'lead_time_days' => 4,
            'generated_at' => now(),
        ]);

        $this->recommendation = AiRecommendation::create([
            'type' => 'reorder',
            'related_entity_type' => 'Product',
            'related_entity_id' => $this->product->id,
            'recommendation_text' => 'Recommended reorder of 180 units for Basmati Rice 5kg.',
            'priority' => 'high',
            'priority_score' => 88.50,
            'status' => 'open',
            'factors' => [
                'product_id' => $this->product->id,
                'branch_id' => $this->branch->id,
                'supplier_id' => $this->supplier->id,
                'safety_stock' => 16.45,
                'reorder_point' => 96.45,
                'target_stock' => 236.45,
                'recommended_reorder_qty' => 180,
            ],
            'generated_at' => now(),
        ]);
    }

    public function test_manager_approve_drafts_auto_generated_purchase_order_and_logs_decision(): void
    {
        $this->actingAs($this->purchaseManager);

        $res = $this->postJson("/api/v1/reorder/{$this->prediction->id}/review", [
            'action' => 'approve',
            'notes' => 'Approved standard weekly replenishment.',
        ]);

        $res->assertStatus(200);
        $res->assertJsonPath('data.decision', 'approved');
        $res->assertJsonPath('data.calculation_inputs.safety_stock', 16.45);
        $res->assertJsonPath('data.calculation_inputs.reorder_point', 96.45);
        $res->assertJsonPath('data.purchase_order.is_auto_generated', true);

        $this->assertDatabaseHas('purchase_orders', [
            'branch_id' => $this->branch->id,
            'supplier_id' => $this->supplier->id,
            'is_auto_generated' => true,
            'total_amount' => 180 * 11.50,
        ]);

        $this->assertDatabaseHas('purchase_order_items', [
            'product_id' => $this->product->id,
            'quantity' => 180,
            'unit_price' => 11.50,
        ]);

        $this->assertEquals('approved', $this->recommendation->fresh()->status);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->purchaseManager->id,
            'action' => 'reorder.review',
        ]);
    }

    public function test_manager_modify_updates_quantity_drafts_po_and_logs_audit(): void
    {
        $this->actingAs($this->purchaseManager);

        $res = $this->postJson("/api/v1/reorder/{$this->prediction->id}/review", [
            'action' => 'modify',
            'quantity' => 200,
            'notes' => 'Increased to 200 units ahead of festival demand.',
        ]);

        $res->assertStatus(200);
        $res->assertJsonPath('data.decision', 'modified');
        $res->assertJsonPath('data.calculation_inputs.reviewed_quantity', 200);

        $this->assertDatabaseHas('purchase_order_items', [
            'product_id' => $this->product->id,
            'quantity' => 200,
        ]);

        $this->assertEquals(200, $this->prediction->fresh()->recommended_reorder_qty);
        $this->assertEquals('modified', $this->recommendation->fresh()->status);
    }

    public function test_manager_reject_does_not_draft_po_and_logs_rejection(): void
    {
        $this->actingAs($this->purchaseManager);

        $poCountBefore = PurchaseOrder::count();

        $res = $this->postJson("/api/v1/reorder/{$this->prediction->id}/review", [
            'action' => 'reject',
            'notes' => 'Stock transfer incoming from Warehouse B instead.',
        ]);

        $res->assertStatus(200);
        $res->assertJsonPath('data.decision', 'rejected');
        $this->assertNull($res->json('data.purchase_order'));
        $this->assertEquals($poCountBefore, PurchaseOrder::count());
        $this->assertEquals('rejected', $this->recommendation->fresh()->status);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->purchaseManager->id,
            'action' => 'reorder.review',
        ]);
    }
}
