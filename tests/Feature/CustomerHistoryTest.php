<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_purchase_history_api_returns_sales_and_items_for_customer(): void
    {
        $this->seed(DemoDataSeeder::class);

        $salesEmployee = User::query()->where('email', 'sales@intellishop.com')->firstOrFail();
        $cashier = User::query()->where('email', 'cashier@intellishop.com')->firstOrFail();
        $branch = Branch::query()->firstOrFail();
        $customer = Customer::query()->firstOrFail();
        $otherCustomer = Customer::query()->where('id', '!=', $customer->id)->first()
            ?? Customer::query()->create([
                'name' => 'Other Customer',
                'phone' => '01700000999',
                'email' => 'other.customer@example.com',
                'segment' => 'regular',
            ]);
        $productA = Product::query()->firstOrFail();
        $productB = Product::query()->where('id', '!=', $productA->id)->firstOrFail();

        SaleItem::query()->delete();
        Sale::query()->delete();

        $sale1 = Sale::query()->create([
            'invoice_no' => 'INV-HIST-1001',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'cashier_id' => $cashier->id,
            'sale_date' => now()->subDays(3),
            'subtotal' => 25.00,
            'discount_amount' => 2.50,
            'tax_amount' => 0.00,
            'total_amount' => 22.50,
            'payment_method' => 'cash',
            'status' => 'completed',
        ]);

        SaleItem::query()->create([
            'sale_id' => $sale1->id,
            'product_id' => $productA->id,
            'quantity' => 2,
            'unit_price' => 10.00,
            'discount' => 1.50,
            'subtotal' => 18.50,
        ]);

        SaleItem::query()->create([
            'sale_id' => $sale1->id,
            'product_id' => $productB->id,
            'quantity' => 1,
            'unit_price' => 5.00,
            'discount' => 1.00,
            'subtotal' => 4.00,
        ]);

        $sale2 = Sale::query()->create([
            'invoice_no' => 'INV-HIST-1002',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'cashier_id' => $cashier->id,
            'sale_date' => now()->subDay(),
            'subtotal' => 15.00,
            'discount_amount' => 0.00,
            'tax_amount' => 0.00,
            'total_amount' => 15.00,
            'payment_method' => 'card',
            'status' => 'completed',
        ]);

        SaleItem::query()->create([
            'sale_id' => $sale2->id,
            'product_id' => $productA->id,
            'quantity' => 3,
            'unit_price' => 5.00,
            'discount' => 0.00,
            'subtotal' => 15.00,
        ]);

        // Unrelated customer sale should not appear in $customer's history
        Sale::query()->create([
            'invoice_no' => 'INV-OTHER-9999',
            'branch_id' => $branch->id,
            'customer_id' => $otherCustomer->id,
            'cashier_id' => $cashier->id,
            'sale_date' => now(),
            'subtotal' => 99.00,
            'discount_amount' => 0.00,
            'tax_amount' => 0.00,
            'total_amount' => 99.00,
            'payment_method' => 'cash',
            'status' => 'completed',
        ]);

        $response = $this->actingAs($salesEmployee)
            ->getJson("/api/v1/customers/{$customer->id}/history");

        $response->assertOk()
            ->assertJsonPath('customer.id', $customer->id)
            ->assertJsonPath('summary.total_orders', 2)
            ->assertJsonPath('summary.completed_orders', 2)
            ->assertJsonCount(2, 'data');

        $this->assertEqualsWithDelta(37.50, (float) $response->json('summary.total_spent'), 0.01);

        $invoices = array_column($response->json('data'), 'invoice_no');
        $this->assertSame(['INV-HIST-1002', 'INV-HIST-1001'], $invoices);
        $this->assertCount(2, $response->json('data.1.items'));
    }

    public function test_customer_purchase_history_web_view_and_role_access(): void
    {
        $this->seed(DemoDataSeeder::class);

        $salesEmployee = User::query()->where('email', 'sales@intellishop.com')->firstOrFail();
        $inventoryManager = User::query()->where('email', 'inventory@intellishop.com')->firstOrFail();
        $branch = Branch::query()->firstOrFail();
        $customer = Customer::query()->firstOrFail();

        Sale::query()->create([
            'invoice_no' => 'INV-WEB-2001',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'cashier_id' => $salesEmployee->id,
            'sale_date' => now(),
            'subtotal' => 42.00,
            'discount_amount' => 2.00,
            'tax_amount' => 0.00,
            'total_amount' => 40.00,
            'payment_method' => 'mobile',
            'status' => 'completed',
        ]);

        $webResponse = $this->actingAs($salesEmployee)
            ->get("/customers/{$customer->id}/history");

        $webResponse->assertOk()
            ->assertSee($customer->name)
            ->assertSee('INV-WEB-2001')
            ->assertDontSee('<style', false);

        // Inventory manager does not have Sales Employee+ access to customer history
        $this->actingAs($inventoryManager)
            ->getJson("/api/v1/customers/{$customer->id}/history")
            ->assertForbidden();
    }
}

