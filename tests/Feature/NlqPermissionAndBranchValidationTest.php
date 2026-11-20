<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\Nlq\NlqAuthorizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NlqPermissionAndBranchValidationTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch1;
    protected Branch $branch2;
    protected User $adminUser;
    protected User $branchManager1;
    protected User $cashierUser;
    protected User $systemAnalyst;
    protected NlqAuthorizer $authorizer;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['name' => Role::ADMIN], ['description' => 'Administrator']);
        $bmRole = Role::firstOrCreate(['name' => Role::BRANCH_MANAGER], ['description' => 'Branch Manager']);
        $cashierRole = Role::firstOrCreate(['name' => Role::CASHIER], ['description' => 'Cashier']);
        $analystRole = Role::firstOrCreate(['name' => Role::SYSTEM_ANALYST], ['description' => 'System Analyst']);

        $this->branch1 = Branch::create([
            'name' => 'Branch Alpha',
            'address' => '100 North Road',
            'phone' => '111111',
            'status' => 'active',
        ]);

        $this->branch2 = Branch::create([
            'name' => 'Branch Beta',
            'address' => '200 South Road',
            'phone' => '222222',
            'status' => 'active',
        ]);

        $this->adminUser = User::factory()->create([
            'role_id' => $adminRole->id,
            'branch_id' => null,
            'status' => 'active',
        ]);

        $this->systemAnalyst = User::factory()->create([
            'role_id' => $analystRole->id,
            'branch_id' => null,
            'status' => 'active',
        ]);

        $this->branchManager1 = User::factory()->create([
            'role_id' => $bmRole->id,
            'branch_id' => $this->branch1->id,
            'status' => 'active',
        ]);

        $this->cashierUser = User::factory()->create([
            'role_id' => $cashierRole->id,
            'branch_id' => $this->branch1->id,
            'status' => 'active',
        ]);

        $this->authorizer = app(NlqAuthorizer::class);
    }

    public function test_role_escalation_is_denied(): void
    {
        // Cashier attempting to access supplier rankings must be denied
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage("Access denied: Role 'cashier' does not have permission to query 'supplier_ranking'.");

        $this->authorizer->authorizeAndScope($this->cashierUser, 'supplier_ranking', []);
    }

    public function test_cross_branch_access_is_denied_for_branch_manager(): void
    {
        // Branch 1 manager attempting to access Branch 2 data must be denied
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage("Cross-branch access denied: You are assigned to Branch #{$this->branch1->id} and cannot query Branch #{$this->branch2->id}.");

        $this->authorizer->authorizeAndScope(
            $this->branchManager1,
            'sales_summary',
            ['branch_id' => $this->branch2->id]
        );
    }

    public function test_branch_scope_is_automatically_enforced_when_omitted(): void
    {
        // Branch 1 manager queries without specifying branch_id -> branch_id=1 automatically injected
        $scopedSlots = $this->authorizer->authorizeAndScope(
            $this->branchManager1,
            'sales_summary',
            ['start_date' => '2026-11-01']
        );

        $this->assertEquals($this->branch1->id, $scopedSlots['branch_id']);
        $this->assertEquals('2026-11-01', $scopedSlots['start_date']);
    }

    public function test_administrator_and_system_analyst_have_global_unrestricted_scope(): void
    {
        // Admin can query any branch without cross-branch block
        $adminScoped = $this->authorizer->authorizeAndScope(
            $this->adminUser,
            'sales_summary',
            ['branch_id' => $this->branch2->id]
        );
        $this->assertEquals($this->branch2->id, $adminScoped['branch_id']);

        // System analyst can query all branches without branch_id injected
        $analystScoped = $this->authorizer->authorizeAndScope(
            $this->systemAnalyst,
            'sales_anomalies',
            []
        );
        $this->assertArrayNotHasKey('branch_id', $analystScoped);
    }
}
