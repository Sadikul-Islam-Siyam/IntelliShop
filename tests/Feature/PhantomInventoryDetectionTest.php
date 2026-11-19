<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CycleCount;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Unit;
use App\Models\User;
use App\Services\PhantomInventoryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhantomInventoryDetectionTest extends TestCase
{
    use RefreshDatabase;

    protected User $inventoryManager;
    protected Branch $branch;
    protected Product $product;
    protected PhantomInventoryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => Role::INVENTORY_MANAGER], ['description' => 'Inventory Manager']);
        $this->branch = Branch::firstOrCreate(
            ['name' => 'Phantom Screening Branch'],
            ['address' => 'Depot 1', 'phone' => '888888', 'status' => 'active']
        );

        $this->inventoryManager = User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);

        $unit = Unit::firstOrCreate(['name' => 'Bottle'], ['abbreviation' => 'btl']);
        $this->product = Product::create([
            'name' => 'Olive Oil 1L',
            'sku' => 'OIL-OLV-1L',
            'selling_price' => 12.00,
            'cost_price' => 7.00,
            'unit_id' => $unit->id,
            'status' => 'active',
        ]);

        $this->service = app(PhantomInventoryService::class);
    }

    public function test_phantom_inventory_creates_cycle_count_and_never_modifies_stock()
    {
        // 1. Recorded stock on hand is 50 units
        $inventory = Inventory::create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'quantity_available' => 50,
        ]);

        // 2. Generate 30 days of steady baseline sales (10-12 units/day)
        for ($d = 33; $d >= 4; $d--) {
            $date = Carbon::today()->subDays($d);
            $qty = ($d % 2 === 0) ? 10 : 12;

            $sale = Sale::create([
                'invoice_no' => "INV-PHT-{$d}",
                'branch_id' => $this->branch->id,
                'cashier_id' => $this->inventoryManager->id,
                'subtotal' => $qty * 12.00,
                'total_amount' => $qty * 12.00,
                'payment_method' => 'cash',
                'status' => 'completed',
                'created_at' => $date,
            ]);

            SaleItem::create([
                'sale_id' => $sale->id,
                'product_id' => $this->product->id,
                'quantity' => $qty,
                'unit_price' => 12.00,
                'subtotal' => $qty * 12.00,
                'created_at' => $date,
            ]);
        }
        // Days 1, 2, 3 before today have 0 sales (persistence)

        // Run detection
        $tasks = $this->service->runDetection($this->branch->id, $this->product->id);

        $this->assertNotEmpty($tasks);
        $task = $tasks->firstWhere('product_id', $this->product->id);
        $this->assertNotNull($task);

        // Verify task attributes
        $this->assertEquals('open', $task->status);
        $this->assertEquals('phantom_inventory', $task->trigger_source);
        $this->assertEquals(50, $task->system_quantity);
        $this->assertLessThanOrEqual(-2.0, (float) $task->z_score);

        // Verify DB record
        $this->assertDatabaseHas('cycle_counts', [
            'id' => $task->id,
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'system_quantity' => 50,
            'status' => 'open',
        ]);

        // Verify Stock is NEVER changed directly
        $this->assertEquals(50, $inventory->fresh()->quantity_available);
    }

    public function test_zero_stock_does_not_trigger_phantom_candidate()
    {
        Inventory::create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'quantity_available' => 0, // Out of stock on books
        ]);

        $tasks = $this->service->runDetection($this->branch->id, $this->product->id);
        $this->assertEmpty($tasks);
    }

    public function test_api_phantom_run_and_candidates()
    {
        $this->actingAs($this->inventoryManager);

        $res = $this->postJson('/api/v1/phantom/run', [
            'branch_id' => $this->branch->id,
        ]);
        $res->assertStatus(200);
        $res->assertJsonStructure(['message', 'count', 'data']);

        $listRes = $this->getJson('/api/v1/phantom/candidates');
        $listRes->assertStatus(200);
    }
}
