<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DemandForecast;
use App\Models\Discount;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\MarketBasketRule;
use App\Models\Product;
use App\Models\ProductPromotion;
use App\Models\PurchaseOrder;
use App\Models\Sale;
use App\Models\SalesAnomaly;
use App\Models\Supplier;
use App\Models\User;

class DashboardWidgetService
{
    /**
     * Build role-specific and branch-scoped widgets.
     */
    public function getWidgetsForRole(User $user, string $roleSlug): array
    {
        $branchId = $user->branch_id;

        return match ($roleSlug) {
            'admin', 'super-admin' => [
                [
                    'key' => 'total_revenue',
                    'label' => 'Total System Revenue',
                    'value' => (float) Sale::where('status', 'completed')->sum('total_amount'),
                    'format' => 'currency',
                ],
                [
                    'key' => 'active_branches',
                    'label' => 'Active Branches',
                    'value' => Branch::where('status', 'active')->count(),
                    'format' => 'number',
                ],
                [
                    'key' => 'total_products',
                    'label' => 'Catalog Products',
                    'value' => Product::count(),
                    'format' => 'number',
                ],
                [
                    'key' => 'audit_logs_count',
                    'label' => 'Total Audit Records',
                    'value' => AuditLog::count(),
                    'format' => 'number',
                ],
            ],

            'branch-manager' => [
                [
                    'key' => 'branch_sales_today',
                    'label' => 'Branch Sales Today',
                    'value' => (float) Sale::when($branchId, fn($q) => $q->where('branch_id', $branchId))
                        ->where('status', 'completed')
                        ->whereDate('created_at', today())
                        ->sum('total_amount'),
                    'format' => 'currency',
                ],
                [
                    'key' => 'branch_inventory_units',
                    'label' => 'Branch Stock Units',
                    'value' => (int) Inventory::when($branchId, fn($q) => $q->where('branch_id', $branchId))->sum('quantity_available'),
                    'format' => 'number',
                ],
                [
                    'key' => 'branch_transactions_today',
                    'label' => 'Branch Transactions Today',
                    'value' => Sale::when($branchId, fn($q) => $q->where('branch_id', $branchId))
                        ->where('status', 'completed')
                        ->whereDate('created_at', today())
                        ->count(),
                    'format' => 'number',
                ],
            ],

            'inventory-manager' => [
                [
                    'key' => 'total_skus',
                    'label' => 'Total Monitored SKUs',
                    'value' => Product::count(),
                    'format' => 'number',
                ],
                [
                    'key' => 'active_batches',
                    'label' => 'Active Batches in Stock',
                    'value' => InventoryBatch::where('quantity_remaining', '>', 0)
                        ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
                        ->count(),
                    'format' => 'number',
                ],
                [
                    'key' => 'low_stock_items',
                    'label' => 'Low Stock Items',
                    'value' => Product::whereColumn('min_stock_level', '>=', 'reorder_point')->count(),
                    'format' => 'number',
                ],
            ],

            'cashier' => [
                [
                    'key' => 'cashier_sales_today',
                    'label' => 'Register Sales Today',
                    'value' => (float) Sale::where('cashier_id', $user->id)
                        ->where('status', 'completed')
                        ->whereDate('created_at', today())
                        ->sum('total_amount'),
                    'format' => 'currency',
                ],
                [
                    'key' => 'cashier_orders_count',
                    'label' => 'Receipts Processed Today',
                    'value' => Sale::where('cashier_id', $user->id)
                        ->where('status', 'completed')
                        ->whereDate('created_at', today())
                        ->count(),
                    'format' => 'number',
                ],
                [
                    'key' => 'active_discounts',
                    'label' => 'Active Discounts Available',
                    'value' => Discount::active()->count(),
                    'format' => 'number',
                ],
            ],

            'sales-employee' => [
                [
                    'key' => 'total_customers',
                    'label' => 'Total Registered Customers',
                    'value' => Customer::count(),
                    'format' => 'number',
                ],
                [
                    'key' => 'vip_customers',
                    'label' => 'VIP Customers',
                    'value' => Customer::where('segment', 'VIP')->count(),
                    'format' => 'number',
                ],
                [
                    'key' => 'promotions_sent',
                    'label' => 'Promotions Dispatched',
                    'value' => ProductPromotion::count(),
                    'format' => 'number',
                ],
            ],

            'purchase-manager' => [
                [
                    'key' => 'active_suppliers',
                    'label' => 'Registered Suppliers',
                    'value' => Supplier::count(),
                    'format' => 'number',
                ],
                [
                    'key' => 'open_purchase_orders',
                    'label' => 'Open Purchase Orders',
                    'value' => PurchaseOrder::whereIn('status', ['draft', 'pending', 'ordered'])->count(),
                    'format' => 'number',
                ],
            ],

            'system-analyst' => [
                [
                    'key' => 'demand_forecasts_count',
                    'label' => 'Demand Forecast Data Points',
                    'value' => DemandForecast::count(),
                    'format' => 'number',
                ],
                [
                    'key' => 'anomaly_alerts',
                    'label' => 'Detected Anomaly Count',
                    'value' => SalesAnomaly::count(),
                    'format' => 'number',
                ],
                [
                    'key' => 'market_basket_rules',
                    'label' => 'Active Association Rules',
                    'value' => MarketBasketRule::count(),
                    'format' => 'number',
                ],
            ],

            default => [],
        };
    }
}
