<?php

namespace Tests\Feature;

use App\Contracts\SettingsService;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\LoyaltyService;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyPointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoDataSeeder::class);
    }

    public function test_loyalty_service_calculates_points_correctly(): void
    {
        $service = app(LoyaltyService::class);
        $settings = app(SettingsService::class);
        $settings->set('loyalty_points_currency_rate', 10.0);

        // $105.50 / 10 = 10 points
        $this->assertSame(10, $service->calculatePoints(105.50));
        // $9.99 / 10 = 0 points
        $this->assertSame(0, $service->calculatePoints(9.99));
        // $250.00 / 10 = 25 points
        $this->assertSame(25, $service->calculatePoints(250.00));
    }

    public function test_points_increment_on_completed_sale_creation(): void
    {
        $customer = Customer::create([
            'name' => 'Alice Loyal',
            'email' => 'alice@example.com',
            'loyalty_points' => 0,
        ]);

        $branch = Branch::first();
        $cashier = User::whereHas('role', fn ($q) => $q->where('name', 'Cashier'))->first() ?: User::first();

        $sale = Sale::create([
            'invoice_no' => 'INV-TEST-LOYALTY-001',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'cashier_id' => $cashier->id,
            'sale_date' => now(),
            'subtotal' => 125.00,
            'discount_amount' => 0.00,
            'tax_amount' => 0.00,
            'total_amount' => 125.00,
            'payment_method' => 'cash',
            'status' => 'completed',
        ]);

        $customer->refresh();
        // 125.00 / 10 = 12 points
        $this->assertSame(12, $customer->loyalty_points);
    }

    public function test_points_increment_when_pending_sale_is_updated_to_completed(): void
    {
        $customer = Customer::create([
            'name' => 'Bob Loyal',
            'email' => 'bob@example.com',
            'loyalty_points' => 5,
        ]);

        $branch = Branch::first();
        $cashier = User::first();

        $sale = Sale::create([
            'invoice_no' => 'INV-TEST-LOYALTY-002',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'cashier_id' => $cashier->id,
            'sale_date' => now(),
            'subtotal' => 80.00,
            'total_amount' => 80.00,
            'payment_method' => 'card',
            'status' => 'pending',
        ]);

        $customer->refresh();
        $this->assertSame(5, $customer->loyalty_points); // no change while pending

        $sale->update(['status' => 'completed']);

        $customer->refresh();
        // 5 + floor(80 / 10) = 5 + 8 = 13 points
        $this->assertSame(13, $customer->loyalty_points);
    }

    public function test_sale_without_customer_completes_safely(): void
    {
        $branch = Branch::first();
        $cashier = User::first();

        $sale = Sale::create([
            'invoice_no' => 'INV-TEST-GUEST-001',
            'branch_id' => $branch->id,
            'customer_id' => null,
            'cashier_id' => $cashier->id,
            'sale_date' => now(),
            'subtotal' => 200.00,
            'total_amount' => 200.00,
            'payment_method' => 'cash',
            'status' => 'completed',
        ]);

        $this->assertDatabaseHas('sales', ['id' => $sale->id]);
    }
}
