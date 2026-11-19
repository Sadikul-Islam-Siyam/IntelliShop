<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\MarketBasketRule;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Unit;
use App\Models\User;
use App\Services\MarketBasketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketBasketAnalysisTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;
    protected User $analyst;
    protected Branch $branch;
    protected Product $productA;
    protected Product $productB;
    protected Product $productC;
    protected MarketBasketService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $cashierRole = Role::firstOrCreate(['name' => Role::CASHIER], ['description' => 'Cashier']);
        $analystRole = Role::firstOrCreate(['name' => Role::SYSTEM_ANALYST], ['description' => 'System Analyst']);

        $this->branch = Branch::firstOrCreate(
            ['name' => 'Market Basket Branch'],
            ['address' => 'Central Mall', 'phone' => '112233', 'status' => 'active']
        );

        $this->cashier = User::factory()->create([
            'role_id' => $cashierRole->id,
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);

        $this->analyst = User::factory()->create([
            'role_id' => $analystRole->id,
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);

        $unit = Unit::firstOrCreate(['name' => 'Item'], ['abbreviation' => 'ea']);
        $this->productA = Product::create([
            'name' => 'Fresh Milk 1L',
            'sku' => 'MLK-01',
            'selling_price' => 2.00,
            'cost_price' => 1.20,
            'unit_id' => $unit->id,
            'status' => 'active',
        ]);

        $this->productB = Product::create([
            'name' => 'Sliced Bread 400g',
            'sku' => 'BRD-01',
            'selling_price' => 1.50,
            'cost_price' => 0.80,
            'unit_id' => $unit->id,
            'status' => 'active',
        ]);

        $this->productC = Product::create([
            'name' => 'Salted Butter 200g',
            'sku' => 'BTR-01',
            'selling_price' => 3.50,
            'cost_price' => 2.00,
            'unit_id' => $unit->id,
            'status' => 'active',
        ]);

        $this->service = app(MarketBasketService::class);
    }

    public function test_association_rules_mined_and_stored_from_sales()
    {
        // 4 transactions:
        // T1: A + B
        // T2: A + B + C
        // T3: A
        // T4: B + C
        $this->createCompletedSale([$this->productA, $this->productB]);
        $this->createCompletedSale([$this->productA, $this->productB, $this->productC]);
        $this->createCompletedSale([$this->productA]);
        $this->createCompletedSale([$this->productB, $this->productC]);

        $rules = $this->service->mineRules(minSupport: 0.20, minConfidence: 0.40, minLift: 0.50);

        $this->assertNotEmpty($rules);

        // Check rule A -> B in database
        $this->assertDatabaseHas('market_basket_rules', [
            'antecedent_product_id' => $this->productA->id,
            'consequent_product_id' => $this->productB->id,
            'support' => 0.5000, // 2 out of 4 sales
        ]);

        // Check rule C -> B in database (confidence 100%, lift > 1.0)
        $this->assertDatabaseHas('market_basket_rules', [
            'antecedent_product_id' => $this->productC->id,
            'consequent_product_id' => $this->productB->id,
            'support' => 0.5000,
            'confidence' => 1.0000,
        ]);
    }

    public function test_pos_cross_sell_endpoint_returns_recommended_products()
    {
        $this->actingAs($this->cashier);

        // Pre-store a rule: Milk (A) -> Bread (B)
        MarketBasketRule::create([
            'antecedent_product_id' => $this->productA->id,
            'consequent_product_id' => $this->productB->id,
            'support' => 0.50,
            'confidence' => 0.67,
            'lift' => 1.33,
            'generated_at' => now(),
        ]);

        $res = $this->getJson("/api/v1/crosssell/{$this->productA->id}");
        $res->assertStatus(200);
        $res->assertJsonStructure([
            'product' => ['id', 'name'],
            'cross_sells' => [
                '*' => ['recommended_product', 'support', 'confidence', 'lift'],
            ],
        ]);

        $crossSells = $res->json('cross_sells');
        $this->assertCount(1, $crossSells);
        $this->assertEquals($this->productB->id, $crossSells[0]['recommended_product']['id']);
    }

    public function test_api_basket_run_and_rules_list()
    {
        $this->actingAs($this->analyst);

        $this->createCompletedSale([$this->productA, $this->productB]);

        $runRes = $this->postJson('/api/v1/basket/run', [
            'min_support' => 0.1,
            'min_confidence' => 0.1,
            'min_lift' => 0.5,
        ]);
        $runRes->assertStatus(200);

        $listRes = $this->getJson('/api/v1/basket/rules');
        $listRes->assertStatus(200);
    }

    private function createCompletedSale(array $products): Sale
    {
        static $counter = 1;
        $sale = Sale::create([
            'invoice_no' => 'INV-BSK-' . str_pad($counter++, 3, '0', STR_PAD_LEFT),
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->cashier->id,
            'subtotal' => 10.00,
            'total_amount' => 10.00,
            'payment_method' => 'cash',
            'status' => 'completed',
        ]);

        foreach ($products as $p) {
            SaleItem::create([
                'sale_id' => $sale->id,
                'product_id' => $p->id,
                'quantity' => 1,
                'unit_price' => $p->selling_price,
                'subtotal' => $p->selling_price,
            ]);
        }

        return $sale;
    }
}
