<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\SubstitutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSubstitutionTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;
    protected Branch $branch;
    protected Category $beverages;
    protected Category $snacks;
    protected Unit $unit;
    protected SubstitutionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => Role::CASHIER], ['description' => 'Cashier']);
        $this->branch = Branch::firstOrCreate(
            ['name' => 'Main POS Branch'],
            ['address' => 'Station Road', 'phone' => '12345678', 'status' => 'active']
        );

        $this->cashier = User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);

        $this->unit = Unit::firstOrCreate(['name' => 'Can'], ['abbreviation' => 'can']);
        $this->beverages = Category::firstOrCreate(['name' => 'Cold Drinks']);
        $this->snacks = Category::firstOrCreate(['name' => 'Snacks & Chips']);

        $this->service = app(SubstitutionService::class);
    }

    public function test_identical_vectors_have_cosine_similarity_one()
    {
        $vecA = [2.0, 0.5, 0.8];
        $vecB = [2.0, 0.5, 0.8];

        $similarity = $this->service->computeCosineSimilarity($vecA, $vecB);
        $this->assertEquals(1.0, $similarity);
    }

    public function test_self_is_excluded_from_substitutes()
    {
        $product = Product::create([
            'name' => 'Cola 330ml',
            'sku' => 'COLA-330',
            'category_id' => $this->beverages->id,
            'unit_id' => $this->unit->id,
            'unit_size' => 330,
            'selling_price' => 1.50,
            'cost_price' => 0.80,
            'status' => 'active',
        ]);

        $substitutes = $this->service->findSubstitutes($product);
        $this->assertIsArray($substitutes);

        foreach ($substitutes as $sub) {
            $this->assertNotEquals($product->id, $sub['product']->id);
        }
    }

    public function test_same_category_and_in_stock_are_preferred()
    {
        $target = Product::create([
            'name' => 'Target Cola 330ml',
            'sku' => 'TGT-COLA',
            'category_id' => $this->beverages->id,
            'unit_id' => $this->unit->id,
            'unit_size' => 330,
            'selling_price' => 2.00,
            'cost_price' => 1.00,
            'status' => 'active',
        ]);

        // Candidate 1: Same category, in-stock
        $sameCatInStock = Product::create([
            'name' => 'Pepsi 330ml',
            'sku' => 'PEP-330',
            'category_id' => $this->beverages->id,
            'unit_id' => $this->unit->id,
            'unit_size' => 330,
            'selling_price' => 2.10,
            'cost_price' => 1.05,
            'status' => 'active',
        ]);
        Inventory::create([
            'product_id' => $sameCatInStock->id,
            'branch_id' => $this->branch->id,
            'quantity_available' => 15,
        ]);

        // Candidate 2: Different category (snacks), in-stock
        $diffCatInStock = Product::create([
            'name' => 'Potato Crisps 50g',
            'sku' => 'POT-CRISP',
            'category_id' => $this->snacks->id,
            'unit_id' => $this->unit->id,
            'unit_size' => 330,
            'selling_price' => 2.00,
            'cost_price' => 1.00,
            'status' => 'active',
        ]);
        Inventory::create([
            'product_id' => $diffCatInStock->id,
            'branch_id' => $this->branch->id,
            'quantity_available' => 20,
        ]);

        $substitutes = $this->service->findSubstitutes($target, $this->branch->id);

        $this->assertNotEmpty($substitutes);
        // The first recommended substitute must be the same category in-stock product
        $this->assertEquals($sameCatInStock->id, $substitutes[0]['product']->id);
        $this->assertTrue($substitutes[0]['same_category']);
        $this->assertTrue($substitutes[0]['in_stock']);
    }

    public function test_price_and_unit_size_band_filtering()
    {
        $target = Product::create([
            'name' => 'Premium Olive Oil 500ml',
            'sku' => 'OIL-500',
            'category_id' => $this->beverages->id,
            'unit_id' => $this->unit->id,
            'unit_size' => 500,
            'selling_price' => 10.00,
            'cost_price' => 6.00,
            'status' => 'active',
        ]);

        // Way too expensive (outside +30% band, > 13.00)
        $expensive = Product::create([
            'name' => 'Ultra Rare Oil 500ml',
            'sku' => 'OIL-EXP',
            'category_id' => $this->beverages->id,
            'unit_id' => $this->unit->id,
            'unit_size' => 500,
            'selling_price' => 20.00,
            'cost_price' => 12.00,
            'status' => 'active',
        ]);

        // Inside price band (11.00 is +10%) and unit size band (500ml is 0%)
        $eligible = Product::create([
            'name' => 'Standard Olive Oil 500ml',
            'sku' => 'OIL-STD',
            'category_id' => $this->beverages->id,
            'unit_id' => $this->unit->id,
            'unit_size' => 500,
            'selling_price' => 11.00,
            'cost_price' => 7.00,
            'status' => 'active',
        ]);

        $substitutes = $this->service->findSubstitutes($target);

        $candidateIds = array_column(array_column($substitutes, 'product'), 'id');
        $this->assertContains($eligible->id, $candidateIds);
        $this->assertNotContains($expensive->id, $candidateIds);
    }

    public function test_api_endpoint_returns_top_k_substitutes()
    {
        $this->actingAs($this->cashier);

        $target = Product::create([
            'name' => 'Mineral Water 500ml',
            'sku' => 'WAT-500',
            'category_id' => $this->beverages->id,
            'unit_id' => $this->unit->id,
            'unit_size' => 500,
            'selling_price' => 1.00,
            'cost_price' => 0.50,
            'status' => 'active',
        ]);

        $sub1 = Product::create([
            'name' => 'Spring Water 500ml',
            'sku' => 'WAT-SPR',
            'category_id' => $this->beverages->id,
            'unit_id' => $this->unit->id,
            'unit_size' => 500,
            'selling_price' => 1.10,
            'cost_price' => 0.55,
            'status' => 'active',
        ]);

        $res = $this->getJson("/api/v1/pos/substitutes/{$target->id}?branch_id={$this->branch->id}");
        $res->assertStatus(200);
        $res->assertJsonStructure([
            'target_product' => ['id', 'name', 'selling_price'],
            'substitutes' => [
                '*' => ['product_id', 'name', 'similarity_score', 'in_stock'],
            ],
        ]);
    }
}
