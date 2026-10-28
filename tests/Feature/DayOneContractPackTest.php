<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DayOneContractPackTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_v2_tables_are_available_after_migration(): void
    {
        $tables = [
            'roles', 'branches', 'users', 'units', 'categories', 'brands', 'products', 'suppliers',
            'product_suppliers', 'inventory', 'inventory_transactions', 'stock_transfers',
            'purchase_orders', 'purchase_order_items', 'purchases', 'customers', 'sales',
            'sale_items', 'discounts', 'demand_forecasts', 'stock_predictions', 'sales_anomalies',
            'market_basket_rules', 'product_promotions', 'ai_recommendations', 'audit_logs',
            'inventory_batches', 'sale_item_batches', 'system_settings', 'cycle_counts',
            'markdown_recommendations', 'model_runs',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected Schema v2 table [$table] to exist.");
        }
    }

    public function test_both_tracks_shared_service_contracts_are_interfaces(): void
    {
        foreach ([
            \App\Contracts\AuditLogger::class,
            \App\Contracts\InventoryService::class,
            \App\Contracts\FefoAllocator::class,
            \App\Contracts\SettingsService::class,
            \App\Contracts\BranchScope::class,
            \App\Contracts\AnalyticsClient::class,
        ] as $contract) {
            $this->assertTrue(interface_exists($contract), "Expected [$contract] to be a loadable interface.");
        }

        $this->assertTrue(is_subclass_of(\App\Services\AuditLogger::class, \App\Contracts\AuditLogger::class));
    }

    public function test_analytics_contract_fixtures_are_valid_json(): void
    {
        foreach ([
            base_path('docs/contracts/analytics-endpoints.json'),
            base_path('docs/contracts/fixtures/forecast-request.json'),
            base_path('docs/contracts/fixtures/forecast-response.json'),
            base_path('docs/contracts/fixtures/error-response.json'),
        ] as $fixture) {
            $this->assertIsArray(json_decode(file_get_contents($fixture), true), "Expected valid JSON in [$fixture].");
        }
    }
}
