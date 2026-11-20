<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Discount;
use App\Models\InventoryBatch;
use App\Models\MarkdownRecommendation;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Unit;
use App\Models\User;
use App\Services\MarkdownService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZeroWasteMarkdownTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $branchManager;
    protected Branch $branch;
    protected Product $product;
    protected MarkdownService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['name' => Role::ADMIN], ['description' => 'Admin']);
        $bmRole = Role::firstOrCreate(['name' => Role::BRANCH_MANAGER], ['description' => 'Branch Manager']);

        $this->branch = Branch::firstOrCreate(
            ['name' => 'ZeroWaste Branch'],
            ['address' => 'Market Square', 'phone' => '555555', 'status' => 'active']
        );

        $this->admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);

        $this->branchManager = User::factory()->create([
            'role_id' => $bmRole->id,
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);

        $unit = Unit::firstOrCreate(['name' => 'Pack'], ['abbreviation' => 'pk']);
        $this->product = Product::create([
            'name' => 'Fresh Yogurt 500g',
            'sku' => 'YOG-500',
            'selling_price' => 3.00,
            'cost_price' => 1.80,
            'unit_id' => $unit->id,
            'shelf_life_days' => 14,
            'status' => 'active',
        ]);

        $this->service = app(MarkdownService::class);
    }

    public function test_surplus_formula_and_bounded_markdown()
    {
        // Batch with 120 qty, 10 days left, baseline sales 120 units over 30 days (avg = 4/day)
        // Projected = 4 * 10 = 40. Surplus = 120 - 40 = 80.
        // Worked example yields 35% markdown.
        $batch = InventoryBatch::create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'batch_no' => 'BATCH-YOG-001',
            'expiry_date' => Carbon::today()->addDays(10),
            'quantity_received' => 120,
            'quantity_remaining' => 120,
            'unit_cost' => 1.80,
            'status' => 'active',
        ]);

        // Create historical sales of 120 units over last 30 days
        $sale = Sale::create([
            'invoice_no' => 'INV-HIST-001',
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->admin->id,
            'subtotal' => 360.00,
            'total_amount' => 360.00,
            'payment_method' => 'cash',
            'status' => 'completed',
            'created_at' => Carbon::now()->subDays(5),
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'quantity' => 120,
            'unit_price' => 3.00,
            'subtotal' => 360.00,
            'created_at' => Carbon::now()->subDays(5),
        ]);

        $recs = $this->service->runEvaluation($this->branch->id);

        $this->assertNotEmpty($recs);
        $rec = $recs->firstWhere('batch_id', $batch->id);
        $this->assertNotNull($rec);
        $this->assertEquals(4.0, (float) $rec->avg_daily_sales);
        $this->assertEquals(40.0, (float) $rec->projected_sales);
        $this->assertEquals(80.0, (float) $rec->surplus_qty);
        $this->assertEquals(35.0, (float) $rec->suggested_markdown_pct);
        $this->assertEquals('Super Admin', $rec->approval_authority); // 35% > 30%
    }

    public function test_expired_batch_is_excluded()
    {
        $expiredBatch = InventoryBatch::create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'batch_no' => 'BATCH-EXP-001',
            'expiry_date' => Carbon::today()->subDays(1), // already expired
            'quantity_received' => 50,
            'quantity_remaining' => 50,
            'unit_cost' => 1.80,
            'status' => 'active',
        ]);

        $recs = $this->service->runEvaluation($this->branch->id);

        $this->assertNull($recs->firstWhere('batch_id', $expiredBatch->id));
    }

    public function test_approval_authority_and_batch_scoped_discount_creation()
    {
        $batch = InventoryBatch::create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'batch_no' => 'BATCH-APP-001',
            'expiry_date' => Carbon::today()->addDays(7),
            'quantity_received' => 100,
            'quantity_remaining' => 100,
            'unit_cost' => 1.80,
            'status' => 'active',
        ]);

        // High markdown recommendation (35% > 30% threshold)
        $rec = MarkdownRecommendation::create([
            'batch_id' => $batch->id,
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'available_qty' => 100,
            'suggested_markdown_pct' => 35.00,
            'max_markdown_pct' => 40.00,
            'approval_authority' => 'Super Admin',
            'status' => 'pending',
        ]);

        // Branch manager should fail to approve high markdown (>30%)
        $this->expectException(AuthorizationException::class);
        $this->service->approveRecommendation($rec, $this->branchManager);
    }

    public function test_admin_approves_markdown_creates_batch_scoped_discount()
    {
        $batch = InventoryBatch::create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'batch_no' => 'BATCH-OK-001',
            'expiry_date' => Carbon::today()->addDays(7),
            'quantity_received' => 100,
            'quantity_remaining' => 100,
            'unit_cost' => 1.80,
            'status' => 'active',
        ]);

        $rec = MarkdownRecommendation::create([
            'batch_id' => $batch->id,
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'available_qty' => 100,
            'suggested_markdown_pct' => 20.00, // below 30%
            'max_markdown_pct' => 40.00,
            'approval_authority' => 'Branch Manager',
            'status' => 'pending',
        ]);

        // Branch manager can approve 20%
        $discount = $this->service->approveRecommendation($rec, $this->branchManager);

        $this->assertNotNull($discount);
        $this->assertEquals('percentage', $discount->type);
        $this->assertEquals(20.00, (float) $discount->value);
        $this->assertEquals($batch->id, $discount->batch_id);
        $this->assertEquals($this->product->id, $discount->product_id);
        $this->assertEquals('active', $discount->status);

        // Check recommendation is updated
        $this->assertEquals('approved', $rec->fresh()->status);
        $this->assertEquals($discount->id, $rec->fresh()->discount_id);
    }

    public function test_api_markdown_run_endpoint()
    {
        $this->actingAs($this->admin);

        InventoryBatch::create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'batch_no' => 'BATCH-API-001',
            'expiry_date' => Carbon::today()->addDays(5),
            'quantity_received' => 60,
            'quantity_remaining' => 60,
            'unit_cost' => 1.80,
            'status' => 'active',
        ]);

        $res = $this->postJson('/api/v1/markdown/run', [
            'branch_id' => $this->branch->id,
        ]);
        $res->assertStatus(200);
        $res->assertJsonStructure(['message', 'count', 'data']);
    }
}
