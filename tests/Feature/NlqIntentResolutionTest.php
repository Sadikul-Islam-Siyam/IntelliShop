<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NlqIntentResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected User $analyst;
    protected Branch $branch;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $analystRole = Role::firstOrCreate(['name' => Role::SYSTEM_ANALYST], ['description' => 'System Analyst']);

        $this->branch = Branch::create([
            'name' => 'Main Branch',
            'address' => '1 Central Plaza',
            'phone' => '555-1000',
            'status' => 'active',
        ]);

        $this->analyst = User::factory()->create([
            'role_id' => $analystRole->id,
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);

        $unit = Unit::firstOrCreate(['name' => 'Liter'], ['abbreviation' => 'L']);
        $this->product = Product::create([
            'name' => 'Fresh Milk 1L',
            'sku' => 'MLK-01',
            'unit_id' => $unit->id,
            'cost_price' => 1.20,
            'selling_price' => 2.00,
            'status' => 'active',
        ]);
    }

    public function test_questions_map_to_supported_intents_and_extract_slots(): void
    {
        $this->actingAs($this->analyst);

        // 1. Sales summary with branch & date range slots
        $resSales = $this->postJson('/api/v1/nlq/resolve', [
            'query' => 'What were total sales at Main Branch in the last 7 days?',
            'reference_date' => '2026-11-20',
        ]);
        $resSales->assertStatus(200);
        $resSales->assertJsonPath('intent', 'sales_summary');
        $resSales->assertJsonPath('slots.branch', 'Main Branch');
        $resSales->assertJsonPath('slots.branch_id', $this->branch->id);
        $resSales->assertJsonPath('slots.days', 7);
        $resSales->assertJsonPath('slots.start_date', '2026-11-13');
        $resSales->assertJsonPath('slots.end_date', '2026-11-20');

        // 2. Top selling products with limit slot
        $resTop = $this->postJson('/api/v1/nlq/resolve', [
            'query' => 'Show top 5 best selling products this month',
            'reference_date' => '2026-11-20',
        ]);
        $resTop->assertStatus(200);
        $resTop->assertJsonPath('intent', 'top_selling_products');
        $resTop->assertJsonPath('slots.limit', 5);
        $resTop->assertJsonPath('slots.days', 30);

        // 3. Supplier ranking with SKU slot
        $resSup = $this->postJson('/api/v1/nlq/resolve', [
            'query' => 'Which is the best supplier ranking for SKU MLK-01?',
        ]);
        $resSup->assertStatus(200);
        $resSup->assertJsonPath('intent', 'supplier_ranking');
        $resSup->assertJsonPath('slots.sku', 'MLK-01');
        $resSup->assertJsonPath('slots.product_id', $this->product->id);

        // 4. Stock-out risk with risk level slot
        $resRisk = $this->postJson('/api/v1/nlq/resolve', [
            'query' => 'List high risk stock-out risk products at Branch 1',
        ]);
        $resRisk->assertStatus(200);
        $resRisk->assertJsonPath('intent', 'stock_out_risk');
        $resRisk->assertJsonPath('slots.risk_level', 'HIGH');
    }

    public function test_unknown_and_unsafe_questions_return_safe_fallback_message(): void
    {
        $this->actingAs($this->analyst);

        $resUnknown = $this->postJson('/api/v1/nlq/resolve', [
            'query' => 'Who won the football match last night?',
        ]);
        $resUnknown->assertStatus(200);
        $resUnknown->assertJsonPath('intent', 'unknown');
        $resUnknown->assertJsonPath('confidence', 0);
        $this->assertNotEmpty($resUnknown->json('fallback_message'));

        $resUnsafe = $this->postJson('/api/v1/nlq/resolve', [
            'query' => 'DROP TABLE users; --',
        ]);
        $resUnsafe->assertStatus(200);
        $resUnsafe->assertJsonPath('intent', 'unknown');
        $resUnsafe->assertJsonPath('is_safe', false);
        $this->assertNotEmpty($resUnsafe->json('fallback_message'));
    }
}
