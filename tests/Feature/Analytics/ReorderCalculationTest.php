<?php

namespace Tests\Feature\Analytics;

use App\Models\AiRecommendation;
use App\Models\Branch;
use App\Models\DemandForecast;
use App\Models\Inventory;
use App\Models\ModelRun;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockPrediction;
use App\Models\Supplier;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\ReorderCalculator;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReorderCalculationTest extends TestCase
{
    use RefreshDatabase;

    public function test_worked_example_exact_values_and_stock_prediction_persistence(): void
    {
        $this->seed(DemoDataSeeder::class);

        $purchaseManager = User::query()->where('email', 'purchase@intellishop.com')->firstOrFail();
        $branch = Branch::query()->firstOrFail();
        $product = Product::query()->where('sku', 'COFF-250G-003')->firstOrFail();
        $supplier = Supplier::query()->firstOrFail();

        // System settings: 95% service level (z = 1.645), R = 7 days
        SystemSetting::query()->updateOrCreate(
            ['setting_key' => 'replenishment_service_level'],
            ['setting_value' => '0.95', 'data_type' => 'float']
        );
        SystemSetting::query()->updateOrCreate(
            ['setting_key' => 'replenishment_review_period_days'],
            ['setting_value' => '7', 'data_type' => 'integer']
        );

        // Supplier terms: L = 4, MOQ = 100, Multiple = 12
        ProductSupplier::query()->where('product_id', $product->id)->delete();
        ProductSupplier::query()->create([
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'supply_price' => 4.50,
            'lead_time_days' => 4,
            'min_order_qty' => 100,
            'order_multiple' => 12,
            'is_preferred' => true,
        ]);

        // Non-perishable, no capacity cap for the base worked example
        $product->update([
            'is_expiry_tracked' => false,
            'shelf_life_days' => null,
        ]);

        // Available = 60 (on-hand = 60, reserved = 0, open PO = 0)
        PurchaseOrderItem::query()->where('product_id', $product->id)->delete();
        Inventory::query()->updateOrCreate(
            ['product_id' => $product->id, 'branch_id' => $branch->id],
            ['quantity_available' => 60, 'quantity_reserved' => 0, 'max_capacity' => null]
        );

        // Forecast: d_bar = 20 units/day over horizon
        DemandForecast::query()
            ->where('product_id', $product->id)
            ->where('branch_id', $branch->id)
            ->delete();

        for ($d = 0; $d < 14; $d++) {
            DemandForecast::query()->create([
                'product_id' => $product->id,
                'branch_id' => $branch->id,
                'forecast_date' => now()->addDays($d)->toDateString(),
                'predicted_demand' => 20.0,
                'lower_bound' => 10.0,
                'upper_bound' => 30.0,
                'model_used' => 'mean',
                'confidence_score' => 0.95,
                'generated_at' => now(),
            ]);
        }

        // Model run with sigma_d = 5.0
        ModelRun::query()->where('product_id', $product->id)->where('branch_id', $branch->id)->delete();
        ModelRun::query()->create([
            'product_id' => $product->id,
            'branch_id' => $branch->id,
            'model_used' => 'mean',
            'sse' => 250.0,
            'rse' => 5.0,
            'selected' => true,
            'params' => ['sigma_d' => 5.0],
            'trained_at' => now(),
        ]);

        $response = $this->actingAs($purchaseManager)->postJson('/api/v1/reorder/calculate', [
            'product_id' => $product->id,
            'branch_id' => $branch->id,
        ]);

        $response->assertCreated();

        $calc = $response->json('data.calculation');
        $this->assertEqualsWithDelta(16.45, $calc['safety_stock'], 0.01);
        $this->assertEqualsWithDelta(96.45, $calc['reorder_point'], 0.01);
        $this->assertEqualsWithDelta(236.45, $calc['target_stock'], 0.01);
        $this->assertEqualsWithDelta(176.45, $calc['raw_quantity'], 0.01);
        $this->assertEqualsWithDelta(176.45, $calc['after_moq'], 0.01);
        $this->assertSame(180, $calc['after_multiple']);
        $this->assertSame(180, $calc['final_quantity']);
        $this->assertFalse($calc['conflict']);
        $this->assertNull($calc['conflict_flag']);

        // Verify persistence in stock_predictions table
        $prediction = StockPrediction::query()
            ->where('product_id', $product->id)
            ->where('branch_id', $branch->id)
            ->firstOrFail();

        $this->assertEqualsWithDelta(16.45, (float) $prediction->safety_stock, 0.01);
        $this->assertEqualsWithDelta(96.45, (float) $prediction->reorder_point, 0.01);
        $this->assertSame(180, (int) $prediction->recommended_reorder_qty);
        $this->assertSame(4, (int) $prediction->lead_time_days);
    }

    public function test_available_stock_includes_reserved_and_open_po_quantities(): void
    {
        $this->seed(DemoDataSeeder::class);

        $branch = Branch::query()->firstOrFail();
        $product = Product::query()->where('sku', 'RICE-1KG-002')->firstOrFail();
        $supplier = Supplier::query()->firstOrFail();
        $user = User::query()->where('email', 'purchase@intellishop.com')->firstOrFail();

        // On-hand = 50, Reserved = 15, Open PO = 25 -> Available = 50 - 15 + 25 = 60
        Inventory::query()->updateOrCreate(
            ['product_id' => $product->id, 'branch_id' => $branch->id],
            ['quantity_available' => 50, 'quantity_reserved' => 15, 'max_capacity' => null]
        );

        PurchaseOrderItem::query()->where('product_id', $product->id)->delete();
        $po = PurchaseOrder::query()->create([
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'order_date' => now()->toDateString(),
            'expected_date' => now()->addDays(2)->toDateString(),
            'status' => 'ordered',
            'total_amount' => 250.00,
            'created_by' => $user->id,
        ]);

        PurchaseOrderItem::query()->create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'quantity' => 25,
            'unit_price' => 10.00,
            'subtotal' => 250.00,
        ]);

        /** @var ReorderCalculator $calculator */
        $calculator = app(ReorderCalculator::class);
        $result = $calculator->calculate($product->id, $branch->id, [
            'forecast_demand' => 20.0,
            'demand_stddev' => 5.0,
            'service_level_z' => 1.645,
            'lead_time_days' => 4,
            'review_period_days' => 7,
            'moq' => 100,
            'order_multiple' => 12,
            'capacity' => null,
            'shelf_life_days' => null,
        ]);

        $this->assertEqualsWithDelta(60.0, $result['parameters']['available_stock'], 0.01);
        $this->assertEqualsWithDelta(176.45, $result['calculation']['raw_quantity'], 0.01);
        $this->assertSame(180, $result['calculation']['final_quantity']);
    }

    public function test_sequential_constraints_capacity_and_perishable_shelf_life_and_conflict_flag(): void
    {
        $this->seed(DemoDataSeeder::class);

        $branch = Branch::query()->firstOrFail();
        $product = Product::query()->where('sku', 'MILK-1L-001')->firstOrFail();

        /** @var ReorderCalculator $calculator */
        $calculator = app(ReorderCalculator::class);

        // Case A: Capacity caps order at 140 (which is >= MOQ 100) -> valid order of 140
        $capResult = $calculator->calculate($product->id, $branch->id, [
            'forecast_demand' => 20.0,
            'demand_stddev' => 5.0,
            'service_level_z' => 1.645,
            'lead_time_days' => 4,
            'review_period_days' => 7,
            'stock' => 60.0,
            'reserved' => 0.0,
            'on_order' => 0.0,
            'moq' => 100,
            'order_multiple' => 12,
            'capacity' => 200.0, // 200 - 60 = 140
            'shelf_life_days' => null,
        ]);

        $this->assertSame(180, $capResult['calculation']['after_multiple']);
        $this->assertSame(140, $capResult['calculation']['after_capacity']);
        $this->assertSame(140, $capResult['calculation']['final_quantity']);
        $this->assertFalse($capResult['calculation']['conflict']);

        // Case B: Perishable shelf-life (6 days * 20/day = 120 >= MOQ 100) caps order at 120
        $shelfResult = $calculator->calculate($product->id, $branch->id, [
            'forecast_demand' => 20.0,
            'demand_stddev' => 5.0,
            'service_level_z' => 1.645,
            'lead_time_days' => 4,
            'review_period_days' => 7,
            'stock' => 60.0,
            'reserved' => 0.0,
            'on_order' => 0.0,
            'moq' => 100,
            'order_multiple' => 12,
            'capacity' => 300.0,
            'shelf_life_days' => 6,
        ]);

        $this->assertSame(120, $shelfResult['calculation']['after_shelf_life']);
        $this->assertSame(120, $shelfResult['calculation']['final_quantity']);
        $this->assertFalse($shelfResult['calculation']['conflict']);
        $this->assertContains('shelf_life', $shelfResult['calculation']['constraints']);

        // Case C: Capacity headroom (130 - 60 = 70) reduces Q below MOQ (100)
        // Must flag with "cannot satisfy MOQ within capacity" and not silently place an invalid order
        $conflictResult = $calculator->calculate($product->id, $branch->id, [
            'forecast_demand' => 20.0,
            'demand_stddev' => 5.0,
            'service_level_z' => 1.645,
            'lead_time_days' => 4,
            'review_period_days' => 7,
            'stock' => 60.0,
            'reserved' => 0.0,
            'on_order' => 0.0,
            'moq' => 100,
            'order_multiple' => 12,
            'capacity' => 130.0,
            'shelf_life_days' => null,
        ]);

        $this->assertTrue($conflictResult['calculation']['conflict']);
        $this->assertSame('cannot satisfy MOQ within capacity', $conflictResult['calculation']['conflict_flag']);
        $this->assertContains('cannot satisfy MOQ within capacity', $conflictResult['calculation']['constraints']);
        $this->assertSame(0, $conflictResult['calculation']['final_quantity']);
        $this->assertSame(70, $conflictResult['calculation']['capped_quantity']);

        $recommendation = AiRecommendation::query()
            ->where('type', 'REORDER_RECOMMENDATION')
            ->where('related_entity_id', $product->id)
            ->first();

        $this->assertNotNull($recommendation);
        $this->assertStringContainsString('cannot satisfy MOQ within capacity', $recommendation->recommendation_text);
    }

    public function test_role_authorization_and_manager_review_screen(): void
    {
        $this->seed(DemoDataSeeder::class);

        $purchaseManager = User::query()->where('email', 'purchase@intellishop.com')->firstOrFail();
        $cashier = User::query()->where('email', 'cashier@intellishop.com')->firstOrFail();
        $sales = User::query()->where('email', 'sales@intellishop.com')->firstOrFail();
        $branch = Branch::query()->firstOrFail();
        $product = Product::query()->firstOrFail();

        // Cashier and Sales Employee are forbidden (403)
        $this->actingAs($cashier)->postJson('/api/v1/reorder/calculate', [
            'product_id' => $product->id,
            'branch_id' => $branch->id,
        ])->assertForbidden();

        $this->actingAs($sales)->postJson('/api/v1/reorder/calculate', [
            'product_id' => $product->id,
            'branch_id' => $branch->id,
        ])->assertForbidden();

        $this->actingAs($cashier)->get('/reorders')->assertForbidden();

        // Purchase Manager can view the Manager Review Screen (/reorders)
        $webResponse = $this->actingAs($purchaseManager)->get('/reorders?branch_id=' . $branch->id);
        $webResponse->assertOk()
            ->assertSee('Dynamic Replenishment')
            ->assertSee('Safety Stock (SS)')
            ->assertSee('Reorder Point (ROP)')
            ->assertSee('Target Stock')
            ->assertDontSee('<style', false);
    }

    public function test_purchase_manager_can_approve_reorder_with_selected_supplier(): void
    {
        $this->seed(DemoDataSeeder::class);

        $purchaseManager = User::query()->where('email', 'purchase@intellishop.com')->firstOrFail();
        $branch = Branch::query()->firstOrFail();
        $product = Product::query()->firstOrFail();
        $supplier = Supplier::query()->firstOrFail();

        $response = $this->actingAs($purchaseManager)->post('/reorders/approve', [
            'product_id' => $product->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'quantity' => 24,
            'unit_price' => 4.25,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('purchase_orders', [
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'status' => 'pending',
            'total_amount' => 102.00,
        ]);

        $this->assertDatabaseHas('purchase_order_items', [
            'product_id' => $product->id,
            'quantity' => 24,
            'unit_price' => 4.25,
            'subtotal' => 102.00,
        ]);
    }
}

