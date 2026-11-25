<?php

namespace Tests\Feature;

use App\Jobs\PromotionJob;
use App\Mail\PromotionMail;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\Product;
use App\Models\ProductPromotion;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Unit;
use App\Models\User;
use App\Services\PromotionEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CustomerPromotionEmailTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Branch $branch;
    protected Category $category;
    protected Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => 'Admin'], ['description' => 'Administrator']);
        $this->branch = Branch::firstOrCreate(
            ['name' => 'Main Branch'],
            ['address' => 'Dhaka', 'phone' => '123456789', 'status' => 'active']
        );

        $this->admin = User::firstOrCreate(
            ['email' => 'admin_test@intellishop.local'],
            [
                'name' => 'Admin Test',
                'password' => bcrypt('password'),
                'role_id' => $role->id,
                'branch_id' => $this->branch->id,
            ]
        );

        $this->unit = Unit::firstOrCreate(['name' => 'Piece'], ['abbreviation' => 'pc']);
        $this->category = Category::firstOrCreate(['name' => 'Beverages']);
    }

    public function test_promotions_send_matches_history_queues_job_and_delivers_and_logs()
    {
        Mail::fake();
        Queue::fake();

        $customer = Customer::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '01711111111',
            'segment' => 'VIP',
        ]);

        $product = Product::create([
            'name' => 'Premium Coffee Beans 500g',
            'sku' => 'COFF-PREM-500',
            'selling_price' => 25.00,
            'cost_price' => 15.00,
            'unit_id' => $this->unit->id,
            'category_id' => $this->category->id,
            'status' => 'active',
        ]);

        // Create completed sale history for this customer
        $sale = Sale::create([
            'invoice_no' => 'INV-TEST-001',
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->admin->id,
            'customer_id' => $customer->id,
            'subtotal' => 50.00,
            'total_amount' => 50.00,
            'tax_amount' => 0.00,
            'discount_amount' => 0.00,
            'payment_method' => 'cash',
            'status' => 'completed',
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 25.00,
            'subtotal' => 50.00,
        ]);

        // Create active discount on the product
        $discount = Discount::create([
            'name' => 'Coffee Lovers 20% Off',
            'type' => 'percentage',
            'value' => 20.00,
            'product_id' => $product->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(7),
        ]);

        // Run artisan command
        $this->artisan('promotions:send')
            ->assertExitCode(0);

        // Verify PromotionJob was pushed to queue
        Queue::assertPushed(PromotionJob::class, function ($job) use ($customer, $product) {
            return $job->customer->id === $customer->id && $job->product->id === $product->id;
        });

        // Now manually execute the job to test delivery and logging
        $job = new PromotionJob(
            $customer,
            $product,
            $discount,
            "Special promotion for {$customer->name}"
        );
        $record = $job->handle();

        // Check Mail was sent
        Mail::assertSent(PromotionMail::class, function ($mail) use ($customer) {
            return $mail->hasTo($customer->email);
        });

        // Check ProductPromotion row was logged
        $this->assertDatabaseHas('product_promotions', [
            'id' => $record->id,
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'promotion_type' => 'email',
            'status' => 'sent',
        ]);
    }

    public function test_no_match_ends_the_run_without_sending()
    {
        Mail::fake();
        Queue::fake();

        $customer = Customer::create([
            'name' => 'Jane Smith',
            'email' => 'jane@example.com',
            'phone' => '01722222222',
            'segment' => 'Regular',
        ]);

        // Customer has no purchases, or purchases without any matching discount
        $this->artisan('promotions:send')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        $this->assertEquals(0, ProductPromotion::count());
    }

    public function test_api_promotions_send_and_index()
    {
        $this->actingAs($this->admin);

        $customer = Customer::create([
            'name' => 'Alice Wonder',
            'email' => 'alice@example.com',
            'phone' => '01733333333',
        ]);

        $product = Product::create([
            'name' => 'Green Tea 100g',
            'sku' => 'TEA-GRN-100',
            'selling_price' => 10.00,
            'cost_price' => 5.00,
            'unit_id' => $this->unit->id,
            'category_id' => $this->category->id,
            'status' => 'active',
        ]);

        $sale = Sale::create([
            'invoice_no' => 'INV-TEST-002',
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->admin->id,
            'customer_id' => $customer->id,
            'subtotal' => 20.00,
            'total_amount' => 20.00,
            'payment_method' => 'cash',
            'status' => 'completed',
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 10.00,
            'subtotal' => 20.00,
        ]);

        Discount::create([
            'name' => 'Tea Time $2 Off',
            'type' => 'fixed',
            'value' => 2.00,
            'product_id' => $product->id,
            'status' => 'active',
        ]);

        $res = $this->postJson('/api/v1/promotions/send', [
            'customer_id' => $customer->id,
        ]);
        $res->assertStatus(200);

        $getRes = $this->getJson('/api/v1/promotions');
        $getRes->assertStatus(200);
    }
}
