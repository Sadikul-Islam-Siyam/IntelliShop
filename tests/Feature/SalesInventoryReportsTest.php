<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockPrediction;
use App\Models\Unit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesInventoryReportsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $branchManager1;
    protected Branch $branch1;
    protected Branch $branch2;
    protected Product $productA;
    protected Product $productB;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['name' => Role::ADMIN], ['description' => 'Admin']);
        $bmRole = Role::firstOrCreate(['name' => Role::BRANCH_MANAGER], ['description' => 'Branch Manager']);

        $this->branch1 = Branch::create([
            'name' => 'Downtown Branch',
            'address' => '100 Main St',
            'phone' => '555-0101',
            'status' => 'active',
        ]);

        $this->branch2 = Branch::create([
            'name' => 'Uptown Branch',
            'address' => '200 North Ave',
            'phone' => '555-0202',
            'status' => 'active',
        ]);

        $this->admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'branch_id' => null,
            'status' => 'active',
        ]);

        $this->branchManager1 = User::factory()->create([
            'role_id' => $bmRole->id,
            'branch_id' => $this->branch1->id,
            'status' => 'active',
        ]);

        $unit = Unit::firstOrCreate(['name' => 'Piece'], ['abbreviation' => 'pc']);
        $cat = Category::firstOrCreate(['name' => 'Groceries']);

        $this->productA = Product::create([
            'name' => 'Organic Honey 500g',
            'sku' => 'HNY-500',
            'category_id' => $cat->id,
            'unit_id' => $unit->id,
            'cost_price' => 6.00,
            'selling_price' => 10.00,
            'reorder_point' => 25,
            'status' => 'active',
        ]);

        $this->productB = Product::create([
            'name' => 'Green Tea 100 Bags',
            'sku' => 'TEA-100',
            'category_id' => $cat->id,
            'unit_id' => $unit->id,
            'cost_price' => 3.00,
            'selling_price' => 5.00,
            'reorder_point' => 15,
            'status' => 'active',
        ]);
    }

    public function test_sales_report_filters_by_date_range_and_branch_and_reconciles_totals(): void
    {
        $this->actingAs($this->admin);

        // Sale 1 at Branch 1 (3 days ago): 2 x Honey ($20) + 1 x Tea ($5) = $25
        $sale1Date = Carbon::today()->subDays(3);
        $sale1 = Sale::create([
            'invoice_no' => 'INV-REP-001',
            'branch_id' => $this->branch1->id,
            'cashier_id' => $this->admin->id,
            'sale_date' => $sale1Date,
            'subtotal' => 25.00,
            'discount_amount' => 2.00,
            'tax_amount' => 1.00,
            'total_amount' => 24.00,
            'payment_method' => 'cash',
            'status' => 'completed',
        ]);
        SaleItem::create([
            'sale_id' => $sale1->id,
            'product_id' => $this->productA->id,
            'quantity' => 2,
            'unit_price' => 10.00,
            'subtotal' => 20.00,
        ]);
        SaleItem::create([
            'sale_id' => $sale1->id,
            'product_id' => $this->productB->id,
            'quantity' => 1,
            'unit_price' => 5.00,
            'subtotal' => 5.00,
        ]);

        // Sale 2 at Branch 2 (yesterday): 4 x Tea ($20)
        $sale2Date = Carbon::today()->subDay();
        $sale2 = Sale::create([
            'invoice_no' => 'INV-REP-002',
            'branch_id' => $this->branch2->id,
            'cashier_id' => $this->admin->id,
            'sale_date' => $sale2Date,
            'subtotal' => 20.00,
            'discount_amount' => 0.00,
            'tax_amount' => 0.00,
            'total_amount' => 20.00,
            'payment_method' => 'card',
            'status' => 'completed',
        ]);
        SaleItem::create([
            'sale_id' => $sale2->id,
            'product_id' => $this->productB->id,
            'quantity' => 4,
            'unit_price' => 5.00,
            'subtotal' => 20.00,
        ]);

        // Sale 3 outside date window (20 days ago)
        $oldSale = Sale::create([
            'invoice_no' => 'INV-REP-OLD',
            'branch_id' => $this->branch1->id,
            'cashier_id' => $this->admin->id,
            'sale_date' => Carbon::today()->subDays(20),
            'subtotal' => 100.00,
            'discount_amount' => 0.00,
            'tax_amount' => 0.00,
            'total_amount' => 100.00,
            'payment_method' => 'cash',
            'status' => 'completed',
        ]);
        SaleItem::create([
            'sale_id' => $oldSale->id,
            'product_id' => $this->productA->id,
            'quantity' => 10,
            'unit_price' => 10.00,
            'subtotal' => 100.00,
        ]);

        // 1. Filter by last 7 days across all branches
        $resAll = $this->getJson('/api/v1/reports/sales?' . http_build_query([
            'start_date' => Carbon::today()->subDays(7)->toDateString(),
            'end_date' => Carbon::today()->toDateString(),
        ]));

        $resAll->assertStatus(200);
        $this->assertEquals(2, $resAll->json('summary.total_sales_count'));
        $this->assertEquals(7, $resAll->json('summary.total_units_sold'));
        $this->assertEquals(44.00, (float) $resAll->json('summary.net_revenue'));
        $this->assertTrue((bool) $resAll->json('reconciliation.reconciled'));

        // 2. Filter by Branch 1 only
        $resBranch1 = $this->getJson('/api/v1/reports/sales?' . http_build_query([
            'branch_id' => $this->branch1->id,
            'start_date' => Carbon::today()->subDays(7)->toDateString(),
            'end_date' => Carbon::today()->toDateString(),
        ]));

        $resBranch1->assertStatus(200);
        $this->assertEquals(1, $resBranch1->json('summary.total_sales_count'));
        $this->assertEquals(3, $resBranch1->json('summary.total_units_sold'));
        $this->assertEquals(24.00, (float) $resBranch1->json('summary.net_revenue'));
        $this->assertTrue((bool) $resBranch1->json('reconciliation.reconciled'));

        // 3. Branch Manager is strictly scoped to their own branch even if requesting Branch 2
        $this->actingAs($this->branchManager1);
        $resScoped = $this->getJson('/api/v1/reports/sales?' . http_build_query([
            'branch_id' => $this->branch2->id,
            'start_date' => Carbon::today()->subDays(7)->toDateString(),
            'end_date' => Carbon::today()->toDateString(),
        ]));

        $resScoped->assertStatus(200);
        $this->assertEquals($this->branch1->id, $resScoped->json('filters.branch_id'));
        $this->assertEquals(24.00, (float) $resScoped->json('summary.net_revenue'));
    }

    public function test_inventory_report_reconciles_stock_and_valuation_by_branch(): void
    {
        $this->actingAs($this->admin);

        // Product A at Branch 1: 20 units (below ROP 25 -> low stock), cost $6 = $120, retail $10 = $200
        Inventory::updateOrCreate(
            ['product_id' => $this->productA->id, 'branch_id' => $this->branch1->id],
            ['quantity_available' => 20, 'quantity_reserved' => 2]
        );
        InventoryBatch::create([
            'product_id' => $this->productA->id,
            'branch_id' => $this->branch1->id,
            'batch_no' => 'B-HNY-01',
            'quantity_received' => 20,
            'quantity_remaining' => 20,
            'unit_cost' => 6.00,
            'status' => 'active',
        ]);

        // Product B at Branch 1: 50 units (above ROP 15), cost $3 = $150, retail $5 = $250
        Inventory::updateOrCreate(
            ['product_id' => $this->productB->id, 'branch_id' => $this->branch1->id],
            ['quantity_available' => 50, 'quantity_reserved' => 0]
        );

        $res = $this->getJson('/api/v1/reports/inventory?branch_id=' . $this->branch1->id);
        $res->assertStatus(200);
        $this->assertEquals(70, $res->json('summary.total_units_on_hand'));
        $this->assertEquals(270.00, (float) $res->json('summary.total_cost_valuation'));
        $this->assertEquals(450.00, (float) $res->json('summary.total_retail_valuation'));
        $this->assertEquals(1, $res->json('summary.low_stock_count'));
        $this->assertTrue((bool) $res->json('reconciliation.reconciled'));
    }

    public function test_chartjs_endpoints_return_valid_chart_json_structures(): void
    {
        $this->actingAs($this->admin);

        StockPrediction::create([
            'product_id' => $this->productA->id,
            'branch_id' => $this->branch1->id,
            'current_stock' => 10,
            'predicted_demand' => 45,
            'recommended_reorder_qty' => 40,
            'risk_level' => 'HIGH',
            'safety_stock' => 8.5,
            'reorder_point' => 25.0,
            'lead_time_days' => 4,
            'generated_at' => now(),
        ]);

        $resAll = $this->getJson('/api/v1/reports/charts');
        $resAll->assertStatus(200);
        $resAll->assertJsonStructure([
            'labels',
            'datasets',
            'charts' => [
                'sales_trend' => ['chart_type', 'labels', 'datasets'],
                'inventory_valuation' => ['chart_type', 'labels', 'datasets'],
                'stock_risk_distribution' => ['chart_type', 'labels', 'datasets'],
                'forecast_projection' => ['chart_type', 'labels', 'datasets'],
                'markdown_surplus' => ['chart_type', 'labels', 'datasets'],
            ],
        ]);

        $resRisk = $this->getJson('/api/v1/reports/charts/risk?branch_id=' . $this->branch1->id);
        $resRisk->assertStatus(200);
        $this->assertEquals('doughnut', $resRisk->json('chart_type'));
        $this->assertEquals(['HIGH Risk', 'MEDIUM Risk', 'LOW Risk'], $resRisk->json('labels'));
    }
}
