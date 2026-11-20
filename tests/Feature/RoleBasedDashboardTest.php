<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleBasedDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch1;
    protected Branch $branch2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch1 = Branch::firstOrCreate(
            ['name' => 'North Branch'],
            ['address' => '123 North St', 'phone' => '111111', 'status' => 'active']
        );

        $this->branch2 = Branch::firstOrCreate(
            ['name' => 'South Branch'],
            ['address' => '456 South St', 'phone' => '222222', 'status' => 'active']
        );
    }

    protected function createUserWithRole(string $roleName, ?Branch $branch = null): User
    {
        $role = Role::firstOrCreate(['name' => $roleName], ['description' => $roleName . ' role']);

        return User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $branch?->id,
            'status' => 'active',
        ]);
    }

    public function test_cashier_sees_only_own_dashboard_and_branch()
    {
        $cashier = $this->createUserWithRole(Role::CASHIER, $this->branch1);

        $this->actingAs($cashier);

        // Allowed to view cashier dashboard
        $response = $this->getJson('/api/v1/dashboard/cashier');
        $response->assertStatus(200);
        $response->assertJsonPath('data.role_slug', 'cashier');
        $response->assertJsonPath('data.branch.id', $this->branch1->id);
        $response->assertJsonPath('data.branch.name', 'North Branch');
        $response->assertJsonStructure([
            'data' => [
                'role',
                'role_slug',
                'branch' => ['id', 'name'],
                'widgets',
                'modules',
            ],
        ]);

        // Denied from viewing admin dashboard
        $forbiddenResponse = $this->getJson('/api/v1/dashboard/admin');
        $forbiddenResponse->assertStatus(403);

        // Denied from viewing branch manager dashboard
        $forbiddenResponse2 = $this->getJson('/api/v1/dashboard/branch-manager');
        $forbiddenResponse2->assertStatus(403);
    }

    public function test_branch_manager_sees_own_branch_and_widgets()
    {
        $bm = $this->createUserWithRole(Role::BRANCH_MANAGER, $this->branch2);

        $this->actingAs($bm);

        $response = $this->getJson('/api/v1/dashboard/branch-manager');
        $response->assertStatus(200);
        $response->assertJsonPath('data.role_slug', 'branch-manager');
        $response->assertJsonPath('data.branch.id', $this->branch2->id);

        // Denied from viewing purchase manager
        $this->getJson('/api/v1/dashboard/purchase-manager')
            ->assertStatus(403);
    }

    public function test_admin_can_access_all_role_dashboards()
    {
        $admin = $this->createUserWithRole(Role::ADMIN, $this->branch1);

        $this->actingAs($admin);

        $response = $this->getJson('/api/v1/dashboard/admin');
        $response->assertStatus(200);
        $response->assertJsonPath('data.role_slug', 'admin');

        // Admin has bypass privilege to inspect other dashboards
        $this->getJson('/api/v1/dashboard/cashier')->assertStatus(200);
        $this->getJson('/api/v1/dashboard/system-analyst')->assertStatus(200);
    }

    public function test_web_role_dashboard_rendering()
    {
        $analyst = $this->createUserWithRole(Role::SYSTEM_ANALYST, $this->branch1);

        $this->actingAs($analyst);

        $res = $this->get('/dashboard/system-analyst');
        $res->assertStatus(200);
        $res->assertSee('Analytics & Decision Intelligence Hub');
    }
}
