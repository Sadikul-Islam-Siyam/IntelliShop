<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UxNavigationAndPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $cashier;
    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create([
            'name' => 'Main Branch',
            'code' => 'MAIN-UX',
            'address' => '123 Main St',
            'status' => 'active',
        ]);

        $adminRole = Role::where('name', Role::ADMIN)->firstOrFail();
        $cashierRole = Role::where('name', Role::CASHIER)->firstOrFail();

        $this->admin = User::create([
            'name' => 'Admin UX Tester',
            'email' => 'admin.ux@intellishop.test',
            'password' => Hash::make('password'),
            'role_id' => $adminRole->id,
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);

        $this->cashier = User::create([
            'name' => 'Cashier UX Tester',
            'email' => 'cashier.ux@intellishop.test',
            'password' => Hash::make('password'),
            'role_id' => $cashierRole->id,
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);
    }

    public function test_unified_sidebar_breadcrumbs_and_ux_engine_render_across_modules(): void
    {
        $routes = [
            '/dashboard/admin',
            '/branches',
            '/customers',
            '/suppliers',
            '/reports',
            '/anomalies',
            '/recommendations',
            '/reorders',
            '/stock-risk',
            '/overstock',
            '/market-basket',
            '/nlq',
            '/audit-logs',
        ];

        foreach ($routes as $uri) {
            $response = $this->actingAs($this->admin)->get($uri);
            $response->assertOk();
            $response->assertSee('id="is-sidebar"', false);
            $response->assertSee('aria-label="Breadcrumb"', false);
            $response->assertSee('intellishop-ux.js', false);
        }
    }

    public function test_sidebar_respects_role_based_access_control(): void
    {
        $response = $this->actingAs($this->cashier)->get('/dashboard/cashier');
        $response->assertOk();
        $response->assertSee('id="is-sidebar"', false);
        // Cashier can see Customers and Market Basket
        $response->assertSee(route('customers.index'), false);
        $response->assertSee(route('market-basket.index'), false);
        // Cashier must NOT see Admin-only Audit Logs or Settings in sidebar
        $response->assertDontSee(route('audit-logs.index'), false);
        $response->assertDontSee(route('settings.index'), false);
    }

    public function test_async_filter_requests_return_target_containers(): void
    {
        $response = $this->actingAs($this->admin)
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get('/customers?search=nonexistent');

        $response->assertOk();
        $response->assertSee('id="customers-results"', false);
        $response->assertSee('data-async-target', false);

        $reports = $this->actingAs($this->admin)
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get('/reports?branch_id=' . $this->branch->id);

        $reports->assertOk();
        $reports->assertSee('id="reports-results"', false);
        $reports->assertSee('id="reports-chart-data"', false);
    }
}
