<?php

namespace Tests\Feature\Analytics;

use App\Models\AiRecommendation;
use App\Models\Branch;
use App\Models\DemandForecast;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OverstockAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_slow_moving_detection_flags_product_with_low_sell_through_rate(): void
    {
        $this->seed(DemoDataSeeder::class);

        $analyst = User::query()->where('email', 'analyst@intellishop.com')->firstOrFail();
        $cashier = User::query()->where('email', 'cashier@intellishop.com')->firstOrFail();
        $branch = Branch::query()->firstOrFail();
        $product = Product::query()->firstOrFail();

        SystemSetting::query()->updateOrCreate(
            ['setting_key' => 'slow_moving_sell_through_threshold'],
            ['setting_value' => '0.20', 'setting_group' => 'analytics']
        );

        // Seed high stock (100 units) and very low sales over 30 days (5 units)
        // Sell-through = 5 / (5 + 100) = 0.0476 < 0.20 -> slow-moving
        Inventory::query()->updateOrCreate(
            ['product_id' => $product->id, 'branch_id' => $branch->id],
            ['quantity_available' => 100, 'quantity_reserved' => 0]
        );

        SaleItem::query()->where('product_id', $product->id)->delete();
        $sale = Sale::query()->create([
            'invoice_no' => 'INV-SLOW-001',
            'branch_id' => $branch->id,
            'cashier_id' => $cashier->id,
            'sale_date' => now()->subDays(5),
            'subtotal' => 25.00,
            'discount_amount' => 0.00,
            'tax_amount' => 0.00,
            'total_amount' => 25.00,
            'payment_method' => 'cash',
            'status' => 'completed',
        ]);
        SaleItem::query()->create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 5,
            'unit_price' => 5.00,
            'discount' => 0.00,
            'subtotal' => 25.00,
        ]);

        $response = $this->actingAs($analyst)->postJson('/api/v1/overstock/run', [
            'product_id' => $product->id,
            'branch_id' => $branch->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('is_slow_moving', true);

        $this->assertEqualsWithDelta(0.0476, (float) $response->json('sell_through_rate'), 0.001);
    }

    public function test_overstock_alert_persists_in_ai_recommendations_and_is_returned_by_alerts_endpoint(): void
    {
        $this->seed(DemoDataSeeder::class);

        $branchManager = User::query()->where('email', 'manager@intellishop.com')->firstOrFail();
        $cashier = User::query()->where('email', 'cashier@intellishop.com')->firstOrFail();
        $branch = Branch::query()->firstOrFail();
        $product = Product::query()->firstOrFail();

        // Seed 14-day forecast demand: 3 units/day * 14 days = 42 units
        DemandForecast::query()
            ->where('product_id', $product->id)
            ->where('branch_id', $branch->id)
            ->delete();

        for ($d = 0; $d < 14; $d++) {
            DemandForecast::query()->create([
                'product_id' => $product->id,
                'branch_id' => $branch->id,
                'forecast_date' => now()->addDays($d)->toDateString(),
                'predicted_demand' => 3.0,
                'lower_bound' => 1.0,
                'upper_bound' => 5.0,
                'model_used' => 'mean',
                'confidence_score' => 0.90,
                'generated_at' => now(),
            ]);
        }

        // Seed high stock: 150 units on hand (> 42 units 14-day forecast -> 108 overstock units)
        Inventory::query()->updateOrCreate(
            ['product_id' => $product->id, 'branch_id' => $branch->id],
            ['quantity_available' => 150, 'quantity_reserved' => 0]
        );

        $runResponse = $this->actingAs($branchManager)->postJson('/api/v1/overstock/run', [
            'product_id' => $product->id,
            'branch_id' => $branch->id,
        ]);

        $runResponse->assertOk()
            ->assertJsonPath('is_overstock', true);

        $this->assertEqualsWithDelta(108.0, (float) $runResponse->json('overstock_units'), 0.1);

        // Verify alert row exists in ai_recommendations
        $this->assertDatabaseHas('ai_recommendations', [
            'type' => 'OVERSTOCK_ALERT',
            'related_entity_type' => 'Product',
            'related_entity_id' => $product->id,
            'status' => 'open',
        ]);

        // Verify GET /api/v1/overstock/alerts returns the alert
        $alertsResponse = $this->actingAs($branchManager)
            ->getJson('/api/v1/overstock/alerts?branch_id=' . $branch->id);

        $alertsResponse->assertOk();
        $alertProductIds = array_column($alertsResponse->json('data'), 'product_id');
        $this->assertContains($product->id, $alertProductIds);

        // Verify web view /overstock renders with zero inline <style> blocks
        $webResponse = $this->actingAs($branchManager)
            ->get('/overstock?branch_id=' . $branch->id);

        $webResponse->assertOk()
            ->assertSee('Slow-Moving & Overstock Detection')
            ->assertSee($product->name)
            ->assertDontSee('<style', false);

        // Cashier is forbidden from overstock alerts
        $this->actingAs($cashier)->getJson('/api/v1/overstock/alerts')->assertForbidden();
    }
}

