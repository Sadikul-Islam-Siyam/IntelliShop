<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CycleCount;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CycleCountReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $inventoryManager;
    protected Branch $branch;
    protected Product $product;
    protected ReconciliationService $reconciliationService;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['name' => Role::ADMIN], ['description' => 'Administrator']);
        $mgrRole = Role::firstOrCreate(['name' => Role::INVENTORY_MANAGER], ['description' => 'Inventory Manager']);

        $this->branch = Branch::firstOrCreate(
            ['name' => 'Main Logistics Hub'],
            ['address' => 'Central Warehousing', 'phone' => '12345678', 'status' => 'active']
        );

        $this->adminUser = User::factory()->create([
            'role_id' => $adminRole->id,
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);

        $this->inventoryManager = User::factory()->create([
            'role_id' => $mgrRole->id,
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);

        $unit = Unit::firstOrCreate(['name' => 'Pack'], ['abbreviation' => 'pk']);
        $this->product = Product::create([
            'name' => 'Premium Coffee Blend 500g',
            'sku' => 'COFF-PREM-500G',
            'selling_price' => 14.50,
            'cost_price' => 8.20,
            'unit_id' => $unit->id,
            'status' => 'active',
        ]);

        $this->reconciliationService = app(ReconciliationService::class);
    }

    public function test_physical_count_entry_calculates_signed_variance(): void
    {
        // System says 50 on hand
        Inventory::create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'quantity_available' => 50,
        ]);

        $cycleCount = CycleCount::create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'trigger_source' => 'phantom_detection',
            'system_quantity' => 50,
            'status' => 'pending_count',
        ]);

        // Worker physical count is 42 (deficiency: variance = 42 - 50 = -8)
        $response = $this->actingAs($this->inventoryManager)->postJson(
            route('api.v1.cycle-counts.count', ['id' => $cycleCount->id]),
            [
                'physical_quantity' => 42,
                'variance_reason' => 'Damaged cans removed from shelf',
            ]
        );

        $response->assertStatus(200);
        $cycleCount->refresh();

        $this->assertEquals(42, $cycleCount->physical_quantity);
        $this->assertEquals(-8, $cycleCount->variance);
        $this->assertEquals('pending_approval', $cycleCount->status);
        $this->assertEquals('Damaged cans removed from shelf', $cycleCount->variance_reason);

        // Verify audit log
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'cycle_count.counted',
            'entity_type' => 'cycle_counts',
            'entity_id' => $cycleCount->id,
        ]);
    }

    public function test_approving_variance_posts_inventory_adjustment_and_updates_ledger(): void
    {
        $inventory = Inventory::create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'quantity_available' => 50,
        ]);

        $cycleCount = CycleCount::create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'trigger_source' => 'phantom_detection',
            'system_quantity' => 50,
            'physical_quantity' => 40,
            'variance' => -10, // 40 - 50 = -10
            'status' => 'pending_approval',
        ]);

        $response = $this->actingAs($this->adminUser)->postJson(
            route('api.v1.cycle-counts.approve', ['id' => $cycleCount->id]),
            ['notes' => 'Confirmed stock shrinkage on aisle 4']
        );

        $response->assertStatus(200);
        $cycleCount->refresh();
        $inventory->refresh();

        $this->assertEquals('approved', $cycleCount->status);
        $this->assertEquals(40, $cycleCount->adjusted_quantity);
        $this->assertNotNull($cycleCount->adjustment_transaction_id);

        // System inventory must now be 40 (50 + (-10))
        $this->assertEquals(40, $inventory->quantity_available);

        // Inventory ledger transaction must exist with immutable movement
        $this->assertDatabaseHas('inventory_transactions', [
            'id' => $cycleCount->adjustment_transaction_id,
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'transaction_type' => 'adjustment',
            'quantity' => -10,
        ]);

        // Audit log must record approval
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'cycle_count.approved',
            'entity_type' => 'cycle_counts',
            'entity_id' => $cycleCount->id,
        ]);
    }

    public function test_rejecting_variance_leaves_inventory_stock_untouched(): void
    {
        $inventory = Inventory::create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'quantity_available' => 50,
        ]);

        $cycleCount = CycleCount::create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'trigger_source' => 'phantom_detection',
            'system_quantity' => 50,
            'physical_quantity' => 45,
            'variance' => -5,
            'status' => 'pending_approval',
        ]);

        $response = $this->actingAs($this->adminUser)->postJson(
            route('api.v1.cycle-counts.reject', ['id' => $cycleCount->id]),
            ['reason' => 'Miscount suspected; recount requested']
        );

        $response->assertStatus(200);
        $cycleCount->refresh();
        $inventory->refresh();

        $this->assertEquals('rejected', $cycleCount->status);
        $this->assertEquals('Miscount suspected; recount requested', $cycleCount->variance_reason);

        // System stock remains 50 untouched
        $this->assertEquals(50, $inventory->quantity_available);
        $this->assertEquals(0, InventoryTransaction::where('transaction_type', 'adjustment')->count());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'cycle_count.rejected',
            'entity_type' => 'cycle_counts',
            'entity_id' => $cycleCount->id,
        ]);
    }

    public function test_large_variance_requires_admin_authorization(): void
    {
        Inventory::create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'quantity_available' => 100,
        ]);

        // Large variance: 100 - 40 = -60 units (>= 50 threshold)
        $cycleCount = CycleCount::create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'trigger_source' => 'phantom_detection',
            'system_quantity' => 100,
            'physical_quantity' => 40,
            'variance' => -60,
            'status' => 'pending_approval',
        ]);

        // Standard inventory manager cannot approve >= 50 unit variance
        $response = $this->actingAs($this->inventoryManager)->postJson(
            route('api.v1.cycle-counts.approve', ['id' => $cycleCount->id]),
            ['notes' => 'Attempting manager approval']
        );

        $response->assertStatus(422);
        $cycleCount->refresh();
        $this->assertEquals('pending_approval', $cycleCount->status);

        // Admin can approve
        $adminResponse = $this->actingAs($this->adminUser)->postJson(
            route('api.v1.cycle-counts.approve', ['id' => $cycleCount->id]),
            ['notes' => 'Super/Admin approval granted']
        );

        $adminResponse->assertStatus(200);
        $cycleCount->refresh();
        $this->assertEquals('approved', $cycleCount->status);
    }

    public function test_web_interface_cycle_counts_screen_renders_successfully(): void
    {
        CycleCount::create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'trigger_source' => 'phantom_detection',
            'system_quantity' => 50,
            'physical_quantity' => 45,
            'variance' => -5,
            'status' => 'pending_approval',
        ]);

        $response = $this->actingAs($this->adminUser)->get(route('cycle-counts.index'));

        $response->assertStatus(200);
        $response->assertSee('Cycle Counts');
        $response->assertSee('COFF-PREM-500G');
    }
}
