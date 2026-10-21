<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['forecast', 'forecast_horizon_days', '14', 'int', 'Default forecast and replenishment horizon in days.'],
            ['forecast', 'forecast_metric_primary', 'smape', 'string', 'Primary forecast comparison metric.'],
            ['replenishment', 'replenishment_service_level', '0.95', 'decimal', 'Target service level used to select the z value.'],
            ['replenishment', 'replenishment_review_period_days', '7', 'int', 'Replenishment review period in days.'],
            ['replenishment', 'replenishment_safety_stock_formula', 'z*sigma_d*sqrt(L)', 'string', 'Safety stock formula; sigma_d is daily demand deviation and L is lead time.'],
            ['supplier', 'supplier_weights_normal', '{"price":0.70,"speed":0.10,"reliability":0.20}', 'json', 'Supplier evaluation weights when stock is normal.'],
            ['supplier', 'supplier_weights_warning', '{"price":0.30,"speed":0.30,"reliability":0.40}', 'json', 'Supplier evaluation weights when stock is low.'],
            ['supplier', 'supplier_weights_panic', '{"price":0.10,"speed":0.70,"reliability":0.20}', 'json', 'Supplier evaluation weights during a stock emergency.'],
            ['supplier', 'supplier_panic_ratio_of_rop', '0.10', 'decimal', 'Panic threshold as a fraction of reorder point.'],
            ['supplier', 'supplier_fulfillment_window_days', '90', 'int', 'Lookback window for supplier fulfillment rate.'],
            ['supplier', 'supplier_max_acceptable_lead_days', '14', 'int', 'Maximum lead time for supplier eligibility.'],
            ['phantom', 'phantom_baseline_days', '30', 'int', 'Historical baseline window for phantom inventory detection.'],
            ['phantom', 'phantom_z_threshold', '2.0', 'decimal', 'Absolute z-score threshold for phantom inventory.'],
            ['phantom', 'phantom_persistence_days', '3', 'int', 'Consecutive days required before raising a phantom alert.'],
            ['phantom', 'phantom_min_history_days', '30', 'int', 'Minimum history required before phantom detection.'],
            ['cycle_count', 'cycle_count_large_variance_super_admin_units', '50', 'int', 'Variance threshold requiring Super Admin review.'],
            ['markdown', 'markdown_max_markdown_pct', '40', 'int', 'Maximum markdown percentage.'],
            ['markdown', 'markdown_horizon_days', '14', 'int', 'Markdown projection horizon in days.'],
            ['markdown', 'markdown_step_pct', '5', 'int', 'Markdown recommendation increment in percentage points.'],
            ['markdown', 'markdown_approval_threshold_pct', '30', 'int', 'Markdown percentage above which Super Admin approval is required.'],
            ['substitution', 'substitution_top_k', '5', 'int', 'Number of product substitutes to return.'],
            ['substitution', 'substitution_price_band_pct', '30', 'int', 'Maximum price difference for a substitute, as a percentage.'],
            ['substitution', 'substitution_unit_size_band_pct', '50', 'int', 'Maximum unit-size difference for a substitute, as a percentage.'],
            ['substitution', 'substitution_category_weight', '2.0', 'decimal', 'Category similarity weight.'],
            ['anomaly', 'anomaly_mad_threshold', '3.5', 'decimal', 'Modified z-score threshold for sales anomalies.'],
            ['anomaly', 'anomaly_iqr_k', '1.5', 'decimal', 'IQR fence multiplier for anomaly detection.'],
            ['auth', 'auth_lockout_attempts', '5', 'int', 'Failed login attempts before lockout.'],
            ['auth', 'auth_lockout_minutes', '15', 'int', 'Login lockout duration in minutes.'],
            ['inventory', 'inventory_reconcile_schedule', '02:00', 'string', 'Nightly stock-ledger reconciliation time.'],
        ];

        foreach ($defaults as [$group, $key, $value, $type, $description]) {
            SystemSetting::query()->firstOrCreate(
                ['setting_key' => $key],
                [
                    'setting_group' => $group,
                    'setting_value' => $value,
                    'value_type' => $type,
                    'description' => $description,
                    'updated_by' => null,
                    'updated_at' => now(),
                ]
            );
            Cache::forget('settings.value.' . $key);
        }
    }
}
