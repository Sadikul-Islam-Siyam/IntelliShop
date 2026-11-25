<?php

namespace Tests\Feature\Analytics;

use App\Models\AiRecommendation;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\Purchase;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\FulfillmentRateCalculator;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SupplierEvaluationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fulfillment_rate_calculator_updates_product_suppliers_and_uses_neutral_fallback_when_no_history(): void
    {
        $this->seed(DemoDataSeeder::class);

        $branch = Branch::query()->firstOrFail();
        $user = User::query()->where('email', 'purchase@intellishop.com')->firstOrFail();
        $product = Product::query()->firstOrFail();

        $supplierWithHistory = Supplier::query()->create([
            'name' => 'Reliable Vendor',
            'status' => 'active',
        ]);
        $supplierNoHistory = Supplier::query()->create([
            'name' => 'New Vendor',
            'status' => 'active',
        ]);

        ProductSupplier::query()->where('product_id', $product->id)->delete();

        ProductSupplier::query()->create([
            'product_id' => $product->id,
            'supplier_id' => $supplierWithHistory->id,
            'supply_price' => 92.00,
            'lead_time_days' => 5,
            'min_order_qty' => 10,
            'order_multiple' => 5,
            'fulfillment_rate' => null,
            'is_preferred' => true,
        ]);

        ProductSupplier::query()->create([
            'product_id' => $product->id,
            'supplier_id' => $supplierNoHistory->id,
            'supply_price' => 100.00,
            'lead_time_days' => 7,
            'min_order_qty' => 10,
            'order_multiple' => 5,
            'fulfillment_rate' => null,
            'is_preferred' => false,
        ]);

        // Seed 2 POs within the 90-day window for $supplierWithHistory:
        // PO 1: Ordered 100, Received 95
        // PO 2: Ordered 100, Received 85 -> Total Received 180 / Total Ordered 200 = 0.9000 -> let's use 95 + 95 = 190/200 = 0.9500
        $po1 = PurchaseOrder::query()->create([
            'supplier_id' => $supplierWithHistory->id,
            'branch_id' => $branch->id,
            'order_date' => now()->subDays(25)->toDateString(),
            'expected_date' => now()->subDays(20)->toDateString(),
            'status' => 'received',
            'total_amount' => 9200.00,
            'created_by' => $user->id,
        ]);
        PurchaseOrderItem::query()->create([
            'purchase_order_id' => $po1->id,
            'product_id' => $product->id,
            'quantity' => 100,
            'unit_price' => 92.00,
            'subtotal' => 9200.00,
        ]);
        Purchase::query()->create([
            'purchase_order_id' => $po1->id,
            'product_id' => $product->id,
            'batch_no' => 'BATCH-FUL-01',
            'quantity_received' => 95,
            'unit_cost' => 92.00,
            'received_at' => now()->subDays(20),
            'received_by' => $user->id,
        ]);

        $po2 = PurchaseOrder::query()->create([
            'supplier_id' => $supplierWithHistory->id,
            'branch_id' => $branch->id,
            'order_date' => now()->subDays(10)->toDateString(),
            'expected_date' => now()->subDays(5)->toDateString(),
            'status' => 'received',
            'total_amount' => 9200.00,
            'created_by' => $user->id,
        ]);
        PurchaseOrderItem::query()->create([
            'purchase_order_id' => $po2->id,
            'product_id' => $product->id,
            'quantity' => 100,
            'unit_price' => 92.00,
            'subtotal' => 9200.00,
        ]);
        Purchase::query()->create([
            'purchase_order_id' => $po2->id,
            'product_id' => $product->id,
            'batch_no' => 'BATCH-FUL-02',
            'quantity_received' => 95,
            'unit_cost' => 92.00,
            'received_at' => now()->subDays(5),
            'received_by' => $user->id,
        ]);

        // Run Artisan command `suppliers:fulfillment` (#67)
        $exitCode = Artisan::call('suppliers:fulfillment');
        $this->assertSame(0, $exitCode);

        $psHistory = ProductSupplier::query()
            ->where('product_id', $product->id)
            ->where('supplier_id', $supplierWithHistory->id)
            ->firstOrFail();

        $psNeutral = ProductSupplier::query()
            ->where('product_id', $product->id)
            ->where('supplier_id', $supplierNoHistory->id)
            ->firstOrFail();

        $this->assertEqualsWithDelta(0.9500, (float) $psHistory->fulfillment_rate, 0.0001);
        $this->assertEqualsWithDelta(FulfillmentRateCalculator::NEUTRAL_FULFILLMENT_RATE, (float) $psNeutral->fulfillment_rate, 0.0001);
    }

    public function test_stage_one_eligibility_filter_excludes_inactive_high_moq_and_long_lead_time_suppliers(): void
    {
        $this->seed(DemoDataSeeder::class);

        $purchaseManager = User::query()->where('email', 'purchase@intellishop.com')->firstOrFail();
        $branch = Branch::query()->firstOrFail();
        $product = Product::query()->firstOrFail();

        SystemSetting::query()->updateOrCreate(
            ['setting_key' => 'supplier_max_acceptable_lead_days'],
            ['setting_value' => '14', 'setting_group' => 'analytics']
        );

        $sEligible = Supplier::query()->create(['name' => 'Eligible Supplier', 'status' => 'active']);
        $sInactive = Supplier::query()->create(['name' => 'Inactive Supplier', 'status' => 'inactive']);
        $sHighMoq = Supplier::query()->create(['name' => 'High MOQ Supplier', 'status' => 'active']);
        $sSlowLead = Supplier::query()->create(['name' => 'Slow Lead Supplier', 'status' => 'active']);

        ProductSupplier::query()->where('product_id', $product->id)->delete();

        ProductSupplier::query()->create([
            'product_id' => $product->id,
            'supplier_id' => $sEligible->id,
            'supply_price' => 100.00,
            'lead_time_days' => 5,
            'min_order_qty' => 20,
            'order_multiple' => 5,
            'fulfillment_rate' => 0.9200,
        ]);
        ProductSupplier::query()->create([
            'product_id' => $product->id,
            'supplier_id' => $sInactive->id,
            'supply_price' => 85.00,
            'lead_time_days' => 4,
            'min_order_qty' => 10,
            'order_multiple' => 5,
            'fulfillment_rate' => 0.9800,
        ]);
        ProductSupplier::query()->create([
            'product_id' => $product->id,
            'supplier_id' => $sHighMoq->id,
            'supply_price' => 90.00,
            'lead_time_days' => 5,
            'min_order_qty' => 500, // exceeds required_quantity = 50
            'order_multiple' => 10,
            'fulfillment_rate' => 0.9500,
        ]);
        ProductSupplier::query()->create([
            'product_id' => $product->id,
            'supplier_id' => $sSlowLead->id,
            'supply_price' => 88.00,
            'lead_time_days' => 21, // exceeds max_acceptable_lead_days = 14
            'min_order_qty' => 10,
            'order_multiple' => 5,
            'fulfillment_rate' => 0.9600,
        ]);

        $response = $this->actingAs($purchaseManager)->postJson('/api/v1/suppliers/evaluate', [
            'product_id' => $product->id,
            'branch_id' => $branch->id,
            'required_quantity' => 50,
            'stock' => 120,
            'rop' => 80,
        ]);

        $response->assertOk()
            ->assertJsonPath('mode', 'NORMAL')
            ->assertJsonCount(1, 'eligible')
            ->assertJsonCount(3, 'ineligible')
            ->assertJsonPath('recommended_supplier.supplier_id', $sEligible->id);
    }

    public function test_saw_worked_example_and_context_aware_weight_modes_shift_winner_from_b_to_c(): void
    {
        $this->seed(DemoDataSeeder::class);

        $purchaseManager = User::query()->where('email', 'purchase@intellishop.com')->firstOrFail();
        $branch = Branch::query()->firstOrFail();
        $product = Product::query()->firstOrFail();

        $supA = Supplier::query()->create(['name' => 'Supplier A', 'status' => 'active']);
        $supB = Supplier::query()->create(['name' => 'Supplier B', 'status' => 'active']);
        $supC = Supplier::query()->create(['name' => 'Supplier C', 'status' => 'active']);

        ProductSupplier::query()->where('product_id', $product->id)->delete();

        // Worked Example (Page 14):
        // Supplier A: Price = 100, Lead = 5, Fulfillment = 0.90
        // Supplier B: Price = 92,  Lead = 7, Fulfillment = 0.95
        // Supplier C: Price = 105, Lead = 3, Fulfillment = 0.85
        ProductSupplier::query()->create([
            'product_id' => $product->id,
            'supplier_id' => $supA->id,
            'supply_price' => 100.00,
            'lead_time_days' => 5,
            'min_order_qty' => 10,
            'order_multiple' => 1,
            'fulfillment_rate' => 0.9000,
        ]);
        ProductSupplier::query()->create([
            'product_id' => $product->id,
            'supplier_id' => $supB->id,
            'supply_price' => 92.00,
            'lead_time_days' => 7,
            'min_order_qty' => 10,
            'order_multiple' => 1,
            'fulfillment_rate' => 0.9500,
        ]);
        ProductSupplier::query()->create([
            'product_id' => $product->id,
            'supplier_id' => $supC->id,
            'supply_price' => 105.00,
            'lead_time_days' => 3,
            'min_order_qty' => 10,
            'order_multiple' => 1,
            'fulfillment_rate' => 0.8500,
        ]);

        // 1. NORMAL Mode (stock = 150 > ROP = 100): B wins (0.943), A (0.893), C (0.892)
        $normalRes = $this->actingAs($purchaseManager)->postJson('/api/v1/suppliers/evaluate', [
            'product_id' => $product->id,
            'branch_id' => $branch->id,
            'required_quantity' => 50,
            'stock' => 150,
            'rop' => 100,
        ]);

        $normalRes->assertOk()
            ->assertJsonPath('mode', 'NORMAL')
            ->assertJsonPath('recommended_supplier.supplier_id', $supB->id);

        $normalRanking = collect($normalRes->json('ranking'))->keyBy('supplier_id');
        $this->assertEqualsWithDelta(0.893, (float) $normalRanking[$supA->id]['score'], 0.001);
        $this->assertEqualsWithDelta(0.943, (float) $normalRanking[$supB->id]['score'], 0.001);
        $this->assertEqualsWithDelta(0.892, (float) $normalRanking[$supC->id]['score'], 0.001);

        // 2. WARNING Mode (stock = 80 <= ROP = 100, > 10% ROP = 10): C wins (0.921), A (0.835), B (0.829)
        $warningRes = $this->actingAs($purchaseManager)->postJson('/api/v1/suppliers/evaluate', [
            'product_id' => $product->id,
            'branch_id' => $branch->id,
            'required_quantity' => 50,
            'stock' => 80,
            'rop' => 100,
        ]);

        $warningRes->assertOk()
            ->assertJsonPath('mode', 'WARNING')
            ->assertJsonPath('recommended_supplier.supplier_id', $supC->id);

        $warningRanking = collect($warningRes->json('ranking'))->keyBy('supplier_id');
        $this->assertEqualsWithDelta(0.835, (float) $warningRanking[$supA->id]['score'], 0.001);
        $this->assertEqualsWithDelta(0.829, (float) $warningRanking[$supB->id]['score'], 0.001);
        $this->assertEqualsWithDelta(0.921, (float) $warningRanking[$supC->id]['score'], 0.001);

        // 3. PANIC Mode (stock = 8 <= 10% of ROP = 10): C wins (0.967), A (0.701), B (0.600)
        $panicRes = $this->actingAs($purchaseManager)->postJson('/api/v1/suppliers/evaluate', [
            'product_id' => $product->id,
            'branch_id' => $branch->id,
            'required_quantity' => 50,
            'stock' => 8,
            'rop' => 100,
        ]);

        $panicRes->assertOk()
            ->assertJsonPath('mode', 'PANIC')
            ->assertJsonPath('recommended_supplier.supplier_id', $supC->id);

        $panicRanking = collect($panicRes->json('ranking'))->keyBy('supplier_id');
        $this->assertEqualsWithDelta(0.701, (float) $panicRanking[$supA->id]['score'], 0.001);
        $this->assertEqualsWithDelta(0.600, (float) $panicRanking[$supB->id]['score'], 0.001);
        $this->assertEqualsWithDelta(0.967, (float) $panicRanking[$supC->id]['score'], 0.001);
    }

    public function test_alert_persisted_when_no_suppliers_are_eligible(): void
    {
        $this->seed(DemoDataSeeder::class);

        $purchaseManager = User::query()->where('email', 'purchase@intellishop.com')->firstOrFail();
        $branch = Branch::query()->firstOrFail();
        $product = Product::query()->firstOrFail();

        ProductSupplier::query()->where('product_id', $product->id)->delete();

        $res = $this->actingAs($purchaseManager)->postJson('/api/v1/suppliers/evaluate', [
            'product_id' => $product->id,
            'branch_id' => $branch->id,
            'required_quantity' => 50,
            'stock' => 50,
            'rop' => 100,
        ]);

        $res->assertOk()
            ->assertJsonPath('alert.code', 'no_eligible_suppliers')
            ->assertJsonCount(0, 'ranking');

        $this->assertDatabaseHas('ai_recommendations', [
            'type' => 'SUPPLIER_INELIGIBLE',
            'related_entity_type' => 'Product',
            'related_entity_id' => $product->id,
            'status' => 'open',
        ]);
    }
}

