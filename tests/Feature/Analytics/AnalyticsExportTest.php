<?php

namespace Tests\Feature\Analytics;

use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class AnalyticsExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('analytics_service.token', 'test-analytics-service-token');
    }

    public function test_unauthenticated_export_request_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/analytics/export/sales');
        $response->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_sales_export_returns_cleaned_ready_json_schema_with_service_token(): void
    {
        $this->seed(DemoDataSeeder::class);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer test-analytics-service-token',
        ])->getJson('/api/v1/analytics/export/sales');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'product_id',
                        'branch_id',
                        'date',
                        'quantity',
                        'revenue',
                        'discount',
                        'transactions',
                        'promotion',
                        'stockout',
                        'closing_stock',
                    ],
                ],
                'meta' => [
                    'from',
                    'to',
                    'window_days',
                    'total_rows',
                ],
            ]);

        $this->assertGreaterThan(100, count($response->json('data')));
    }

    public function test_inventory_batches_and_purchases_export_endpoints_validate_schema(): void
    {
        $this->seed(DemoDataSeeder::class);

        $headers = ['Authorization' => 'Bearer test-analytics-service-token'];

        $inv = $this->withHeaders($headers)->getJson('/api/v1/analytics/export/inventory');
        $inv->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'product_id',
                        'branch_id',
                        'quantity_available',
                        'quantity_reserved',
                        'reorder_point',
                        'reorder_quantity',
                        'shelf_life_days',
                    ],
                ],
                'meta' => ['total_rows'],
            ]);

        $batches = $this->withHeaders($headers)->getJson('/api/v1/analytics/export/batches');
        $batches->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'batch_id',
                        'product_id',
                        'branch_id',
                        'batch_no',
                        'expiry_date',
                        'quantity_received',
                        'quantity_remaining',
                        'status',
                    ],
                ],
                'meta' => ['total_rows'],
            ]);

        $purchases = $this->withHeaders($headers)->getJson('/api/v1/analytics/export/purchases');
        $purchases->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'purchase_order_id',
                        'supplier_id',
                        'branch_id',
                        'product_id',
                        'quantity_ordered',
                        'quantity_received',
                    ],
                ],
                'meta' => ['total_rows'],
            ]);
    }
}
