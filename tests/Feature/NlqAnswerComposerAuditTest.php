<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NlqAnswerComposerAuditTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch1;
    protected Branch $branch2;
    protected User $adminUser;
    protected User $branchManager1;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['name' => Role::ADMIN], ['description' => 'Administrator']);
        $bmRole = Role::firstOrCreate(['name' => Role::BRANCH_MANAGER], ['description' => 'Branch Manager']);

        $this->branch1 = Branch::create([
            'name' => 'Downtown Superstore',
            'address' => '100 Main St',
            'phone' => '111111',
            'status' => 'active',
        ]);

        $this->branch2 = Branch::create([
            'name' => 'Uptown Depot',
            'address' => '200 High St',
            'phone' => '222222',
            'status' => 'active',
        ]);

        $this->adminUser = User::factory()->create([
            'role_id' => $adminRole->id,
            'branch_id' => null,
            'status' => 'active',
        ]);

        $this->branchManager1 = User::factory()->create([
            'role_id' => $bmRole->id,
            'branch_id' => $this->branch1->id,
            'status' => 'active',
        ]);

        $unit = Unit::firstOrCreate(['name' => 'Piece'], ['abbreviation' => 'pc']);
        $this->product = Product::create([
            'name' => 'Organic Orange Juice 1L',
            'sku' => 'BEV-OJ-1L',
            'selling_price' => 4.50,
            'cost_price' => 2.50,
            'unit_id' => $unit->id,
            'status' => 'active',
        ]);

        // Seed sample completed sale
        $sale = Sale::create([
            'invoice_no' => 'INV-TEST-001',
            'branch_id' => $this->branch1->id,
            'cashier_id' => $this->adminUser->id,
            'subtotal' => 9.00,
            'total_amount' => 9.00,
            'payment_method' => 'cash',
            'status' => 'completed',
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'quantity' => 2,
            'unit_price' => 4.50,
            'subtotal' => 9.00,
        ]);
    }

    public function test_ask_endpoint_returns_narrative_table_sources_and_writes_audit_log(): void
    {
        $response = $this->actingAs($this->adminUser)->postJson('/api/v1/nlq/ask', [
            'query' => 'Show top 5 best selling products this month',
            'reference_date' => now()->toDateString(),
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'query',
            'intent',
            'confidence',
            'narrative_answer',
            'table' => [
                'columns',
                'rows',
                'count',
            ],
            'sources' => [
                'primary_source_tables',
                'filter_constraints',
                'query_intent',
                'rows_matched',
                'query_latency_ms',
                'audit_verified',
            ],
            'metadata' => [
                'execution_time_ms',
                'generated_at',
            ],
        ]);

        $this->assertEquals('top_selling_products', $response->json('intent'));
        $this->assertNotEmpty($response->json('narrative_answer'));
        $this->assertStringContainsString('Organic Orange Juice', $response->json('narrative_answer'));

        // Verify audit log row written
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->adminUser->id,
            'action' => 'nlq.ask',
            'entity_type' => 'nlq_queries',
        ]);
    }

    public function test_cross_branch_query_returns_403_and_logs_audit_forbidden(): void
    {
        $response = $this->actingAs($this->branchManager1)->postJson('/api/v1/nlq/ask', [
            'query' => "What were total sales at Uptown Depot in the last 7 days?",
        ]);

        $response->assertStatus(403);
        $response->assertJsonFragment([
            'message' => "Cross-branch access denied: You are assigned to Branch #{$this->branch1->id} and cannot query Branch #{$this->branch2->id}.",
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->branchManager1->id,
            'action' => 'nlq.forbidden',
            'entity_type' => 'nlq_queries',
        ]);
    }

    public function test_web_interface_executes_query_and_displays_evidence_citations(): void
    {
        $response = $this->actingAs($this->adminUser)->post(route('nlq.ask'), [
            'query' => 'Show top 5 best selling products this month',
        ]);

        $response->assertStatus(200);
        $response->assertSee('Executive Narrative Answer');
        $response->assertSee('Supporting Tabular Evidence');
        $response->assertSee('Evidence Citations &amp; Audit Trace', false);
        $response->assertSee('Organic Orange Juice 1L');
    }
}
