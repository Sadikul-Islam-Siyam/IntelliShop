<?php

namespace Tests\Feature;

use App\Models\AiRecommendation;
use App\Models\Branch;
use App\Models\CycleCount;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\MarkdownRecommendation;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Role;
use App\Models\SalesAnomaly;
use App\Models\StockPrediction;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecommendationReviewManagerTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;
    protected Branch $branch;
    protected Product $product;
    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => Role::BRANCH_MANAGER], ['description' => 'Branch Manager']);
        $this->branch = Branch::firstOrCreate(
            ['name' => 'Metro Super Store'],
            ['address' => 'Downtown Ave 10', 'phone' => '11223344', 'status' => 'active']
        );

        $this->manager = User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);

        $unit = Unit::firstOrCreate(['name' => 'Pack'], ['abbreviation' => 'pk']);
        $this->product = Product::create([
            'name' => 'Whole Wheat Bread 400g',
            'sku' => 'BRD-WHT-400G',
            'selling_price' => 3.20,
            'cost_price' => 1.80,
            'unit_id' => $unit->id,
            'reorder_point' => 25,
            'status' => 'active',
        ]);

        $this->supplier = Supplier::create([
            'name' => 'Fresh Bakery Supplies',
            'status' => 'active',
        ]);
    }

    public function test_approving_reorder_recommendation_drafts_auto_generated_purchase_order(): void
    {
        $rec = AiRecommendation::create([
            'type' => 'reorder',
            'related_entity_type' => 'Product',
            'related_entity_id' => $this->product->id,
            'recommendation_text' => 'Stock is below ROP. Reorder 60 units.',
            'priority' => 'high',
            'priority_score' => 88.5,
            'status' => 'open',
            'factors' => [
                'rule' => 'IF_STOCK_RISK_THEN_REORDER_WITH_SAW_SUPPLIER',
                'branch_id' => $this->branch->id,
                'product_id' => $this->product->id,
                'inputs' => [
                    'risk_level' => 'HIGH',
                    'current_stock' => 5,
                    'reorder_point' => 25,
                    'recommended_reorder_qty' => 60,
                ],
            ],
            'generated_at' => now(),
        ]);

        $response = $this->actingAs($this->manager)->postJson(
            route('api.v1.recommendations.review', ['id' => $rec->id]),
            [
                'action' => 'approve',
                'supplier_id' => $this->supplier->id,
                'unit_price' => 1.80,
                'notes' => 'Approved morning bakery reorder',
            ]
        );

        $response->assertStatus(200);
        $rec->refresh();

        $this->assertEquals('approved', $rec->status);
        $this->assertEquals($this->manager->id, $rec->reviewed_by);

        // Verify draft purchase order created with is_auto_generated = true
        $po = PurchaseOrder::where('branch_id', $this->branch->id)
            ->where('supplier_id', $this->supplier->id)
            ->where('is_auto_generated', true)
            ->first();

        $this->assertNotNull($po);
        $this->assertEquals('pending', $po->status);

        $poItem = PurchaseOrderItem::where('purchase_order_id', $po->id)->first();
        $this->assertNotNull($poItem);
        $this->assertEquals(60, $poItem->quantity);
        $this->assertEquals(1.80, (float) $poItem->unit_price);

        // Verify audit log entry
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'reorder.review',
            'entity_type' => 'ai_recommendations',
            'entity_id' => $rec->id,
        ]);
    }

    public function test_modifying_reorder_quantity_drafts_po_with_modified_amount(): void
    {
        $rec = AiRecommendation::create([
            'type' => 'reorder',
            'related_entity_type' => 'Product',
            'related_entity_id' => $this->product->id,
            'recommendation_text' => 'Reorder 60 units.',
            'priority' => 'high',
            'priority_score' => 88.5,
            'status' => 'open',
            'factors' => [
                'branch_id' => $this->branch->id,
                'product_id' => $this->product->id,
                'inputs' => ['recommended_reorder_qty' => 60],
            ],
            'generated_at' => now(),
        ]);

        $response = $this->actingAs($this->manager)->postJson(
            route('api.v1.recommendations.review', ['id' => $rec->id]),
            [
                'action' => 'modify',
                'modified_quantity' => 45,
                'supplier_id' => $this->supplier->id,
                'unit_price' => 1.80,
                'notes' => 'Reduced from 60 to 45 due to shelf constraints',
            ]
        );

        $response->assertStatus(200);
        $rec->refresh();

        $this->assertEquals('modified', $rec->status);

        $po = PurchaseOrder::where('branch_id', $this->branch->id)
            ->where('is_auto_generated', true)
            ->latest('id')
            ->first();

        $this->assertNotNull($po);
        $poItem = PurchaseOrderItem::where('purchase_order_id', $po->id)->first();
        $this->assertEquals(45, $poItem->quantity);
    }

    public function test_rejecting_reorder_recommendation_leaves_no_po_and_logs_decision(): void
    {
        $rec = AiRecommendation::create([
            'type' => 'reorder',
            'related_entity_type' => 'Product',
            'related_entity_id' => $this->product->id,
            'recommendation_text' => 'Reorder 50 units.',
            'priority' => 'medium',
            'priority_score' => 60.0,
            'status' => 'open',
            'factors' => ['branch_id' => $this->branch->id],
            'generated_at' => now(),
        ]);

        $initialPoCount = PurchaseOrder::count();

        $response = $this->actingAs($this->manager)->postJson(
            route('api.v1.recommendations.review', ['id' => $rec->id]),
            [
                'action' => 'reject',
                'reason' => 'Sufficient stock at regional depot',
            ]
        );

        $response->assertStatus(200);
        $rec->refresh();

        $this->assertEquals('rejected', $rec->status);
        $this->assertEquals($initialPoCount, PurchaseOrder::count());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'reorder.review',
            'entity_type' => 'ai_recommendations',
            'entity_id' => $rec->id,
        ]);
    }

    public function test_approving_phantom_recommendation_opens_cycle_count_task(): void
    {
        Inventory::create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'quantity_available' => 30,
        ]);

        $rec = AiRecommendation::create([
            'type' => 'phantom',
            'related_entity_type' => 'Product',
            'related_entity_id' => $this->product->id,
            'recommendation_text' => 'Zero sales detected despite 30 units on hand.',
            'priority' => 'critical',
            'priority_score' => 95.0,
            'status' => 'open',
            'factors' => [
                'rule' => 'IF_PHANTOM_DETECTED_THEN_SCHEDULE_CYCLE_COUNT',
                'branch_id' => $this->branch->id,
                'inputs' => ['current_stock' => 30],
            ],
            'generated_at' => now(),
        ]);

        $response = $this->actingAs($this->manager)->postJson(
            route('api.v1.recommendations.review', ['id' => $rec->id]),
            [
                'action' => 'approve',
                'notes' => 'Schedule cycle count for aisle 3',
            ]
        );

        $response->assertStatus(200);
        $rec->refresh();

        $this->assertEquals('approved', $rec->status);

        // Verify cycle count task opened
        $cycleCount = CycleCount::where('product_id', $this->product->id)
            ->where('branch_id', $this->branch->id)
            ->first();

        $this->assertNotNull($cycleCount);
        $this->assertEquals('pending_count', $cycleCount->status);
        $this->assertEquals(30, $cycleCount->system_quantity);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'recommendation.review',
            'entity_type' => 'ai_recommendations',
            'entity_id' => $rec->id,
        ]);
    }

    public function test_approving_markdown_recommendation_approves_discount(): void
    {
        $batch = InventoryBatch::create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'batch_no' => 'BAT-BREAD-01',
            'quantity_received' => 50,
            'quantity_remaining' => 20,
            'unit_cost' => 1.80,
            'expiry_date' => now()->addDays(2),
            'status' => 'active',
        ]);

        $markdownRec = MarkdownRecommendation::create([
            'batch_id' => $batch->id,
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'baseline_days' => 30,
            'avg_daily_sales' => 3.0,
            'days_remaining' => 2,
            'available_qty' => 20,
            'projected_sales' => 6.0,
            'surplus_qty' => 14.0,
            'suggested_markdown_pct' => 25.0,
            'max_markdown_pct' => 50.0,
            'status' => 'pending',
            'approval_authority' => 'branch_manager',
            'generated_at' => now(),
        ]);

        $aiRec = AiRecommendation::create([
            'type' => 'markdown',
            'related_entity_type' => 'Product',
            'related_entity_id' => $this->product->id,
            'recommendation_text' => 'Batch BAT-BREAD-01 expires in 2 days. 25% discount recommended.',
            'priority' => 'high',
            'priority_score' => 84.0,
            'status' => 'open',
            'factors' => [
                'rule' => 'IF_EXPIRING_SURPLUS_THEN_MARKDOWN_DISCOUNT',
                'markdown_id' => $markdownRec->id,
                'branch_id' => $this->branch->id,
                'inputs' => ['batch_id' => $batch->id],
            ],
            'generated_at' => now(),
        ]);

        $response = $this->actingAs($this->manager)->postJson(
            route('api.v1.recommendations.review', ['id' => $aiRec->id]),
            [
                'action' => 'approve',
                'notes' => 'Approved 25% zero-waste discount',
            ]
        );

        $response->assertStatus(200);
        $aiRec->refresh();
        $markdownRec->refresh();

        $this->assertEquals('approved', $aiRec->status);
        $this->assertEquals('approved', $markdownRec->status);
        $this->assertNotNull($markdownRec->discount_id);
    }

    public function test_web_interface_review_action_redirects_with_success(): void
    {
        $rec = AiRecommendation::create([
            'type' => 'phantom',
            'related_entity_type' => 'Product',
            'related_entity_id' => $this->product->id,
            'recommendation_text' => 'Check phantom stock',
            'priority' => 'high',
            'priority_score' => 75.0,
            'status' => 'open',
            'factors' => [
                'branch_id' => $this->branch->id,
                'inputs' => ['current_stock' => 15],
            ],
            'generated_at' => now(),
        ]);

        $response = $this->actingAs($this->manager)->post(
            route('recommendations.review', ['id' => $rec->id]),
            ['action' => 'approve']
        );

        $response->assertStatus(302);
        $response->assertSessionHas('success');
        $rec->refresh();
        $this->assertEquals('approved', $rec->status);
    }
}
