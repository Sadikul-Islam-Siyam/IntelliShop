<?php

/**
 * IntelliShop — Role & Permission Matrix Configuration
 *
 * Implements Sub-Feature #1: Roles & Permissions Schema (Sharif, Week 1).
 * Master Implementation Plan Decision M11:
 * "roles table has no permissions column; permission sets in config/permissions.php."
 * Section 2.3: Roles and default access matrix.
 */

use App\Models\Role;

return [

    /*
    |--------------------------------------------------------------------------
    | The 7 Canonical System Roles
    |--------------------------------------------------------------------------
    |
    | IntelliShop defines exactly 7 canonical roles. The roles table in the DB
    | stores only role names and descriptions (Schema v2, Appendix D).
    |
    */
    'canonical_roles' => [
        Role::SUPER_ADMIN => [
            'slug' => 'super_admin',
            'description' => 'System-wide administrative control across all branches and modules.',
        ],
        Role::BRANCH_MANAGER => [
            'slug' => 'branch_manager',
            'description' => 'Manages branch operations, branch inventory, stock transfers, sales, and branch-level analytics.',
        ],
        Role::INVENTORY_MANAGER => [
            'slug' => 'inventory_manager',
            'description' => 'Oversees product catalog, stock adjustments, goods receiving, and low-stock alerts.',
        ],
        Role::CASHIER => [
            'slug' => 'cashier',
            'description' => 'Operates the Point of Sale terminal, processes customer sales, and prints receipts.',
        ],
        Role::SALES_EMPLOYEE => [
            'slug' => 'sales_employee',
            'description' => 'Manages customer relationships, customer segments, loyalty points, and promotional campaigns.',
        ],
        Role::PURCHASE_MANAGER => [
            'slug' => 'purchase_manager',
            'description' => 'Manages suppliers, procurement terms, purchase orders, and supplier evaluation rankings.',
        ],
        Role::SYSTEM_ANALYST => [
            'slug' => 'system_analyst',
            'description' => 'Monitors analytics data pipelines, demand forecasting backtests, anomaly detection, and business queries.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Granular Permission Definitions Grouped by Module
    |--------------------------------------------------------------------------
    */
    'permission_groups' => [
        'Authentication & RBAC' => [
            'auth.login' => 'Log into the system',
            'roles.view' => 'View system roles & permissions',
            'roles.manage' => 'Manage roles & permissions schema',
            'users.view' => 'View user accounts',
            'users.manage' => 'Register and manage user accounts',
            'audit_logs.view' => 'Access system audit logs',
        ],
        'Branch Management' => [
            'branches.view' => 'View branch profiles',
            'branches.manage' => 'Create, edit and manage branches',
        ],
        'Product & Catalog' => [
            'units.manage' => 'Manage unit definitions',
            'categories.manage' => 'Manage category taxonomy',
            'brands.manage' => 'Manage brand records',
            'products.view' => 'Browse product catalog',
            'products.manage' => 'Create and modify products (SKU, price, stock levels)',
            'products.images' => 'Upload and update product images',
        ],
        'Supplier Management' => [
            'suppliers.view' => 'View supplier listings and profiles',
            'suppliers.manage' => 'Create and edit suppliers',
            'supplier_terms.manage' => 'Manage product-supplier terms (price, MOQ, lead time)',
        ],
        'Inventory Management' => [
            'inventory.view' => 'Check branch inventory balances',
            'inventory.adjust' => 'Make inventory adjustments',
            'inventory.transactions.view' => 'Inspect immutable inventory transaction log',
            'stock_transfers.request' => 'Request stock transfer between branches',
            'stock_transfers.approve' => 'Approve stock transfers between branches',
            'inventory.alerts.view' => 'Receive low-stock & reorder-point alerts',
        ],
        'POS & Sales' => [
            'pos.access' => 'Access Barcode POS terminal',
            'pos.scan' => 'Scan barcodes and lookup products',
            'pos.cart' => 'Build sales cart',
            'pos.discounts' => 'Apply active promotional discounts',
            'pos.checkout' => 'Process customer payments',
            'pos.invoices' => 'Generate and print sale invoices',
            'sales.view' => 'Review historical sales receipts',
        ],
        'Purchase & Receiving' => [
            'purchase_orders.view' => 'View purchase orders',
            'purchase_orders.create' => 'Create new purchase orders and add line items',
            'purchase_orders.approve' => 'Approve purchase orders',
            'goods.receiving' => 'Receive incoming goods with batch and expiry tracking',
        ],
        'Customer Management' => [
            'customers.view' => 'View customer records',
            'customers.manage' => 'Create and edit customer profiles & segments',
            'customers.loyalty' => 'Track and manage customer loyalty points',
            'customers.promotions' => 'Trigger targeted customer promotion emails',
        ],
        'Reports & Dashboards' => [
            'dashboard.view' => 'Access role-specific dashboard',
            'reports.sales' => 'Generate sales analytical reports',
            'reports.inventory' => 'Generate inventory movement and valuation reports',
            'reports.charts' => 'Access Chart.js analytical visualizations',
        ],
        'AI / ML & Decision Support' => [
            'ml.data_export' => 'Export operational data to analytics service',
            'ml.forecast.view' => 'View demand forecast projections',
            'ml.stock_risk.view' => 'View stock-out risk predictions',
            'ml.reorder.calculate' => 'Calculate optimized reorder quantities',
            'ml.supplier_eval.view' => 'Access SAW supplier rankings',
            'ml.slow_moving.view' => 'Review overstock and slow-moving items',
            'ml.anomaly.view' => 'View detected sales anomalies',
            'ml.anomaly.review' => 'Acknowledge, explain, or dismiss sales anomalies',
            'ml.market_basket.view' => 'Inspect market basket association rules',
            'ml.recommendations.view' => 'View prioritized AI recommendations',
            'ml.recommendations.review' => 'Approve, modify or reject AI recommendations',
            'nlq.query' => 'Execute natural-language business queries',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Role to Permission Mappings (M11 & Section 2.3)
    |--------------------------------------------------------------------------
    */
    'roles' => [
        Role::SUPER_ADMIN => [
            'auth.login',
            'roles.view',
            'roles.manage',
            'users.view',
            'users.manage',
            'audit_logs.view',
            'branches.view',
            'branches.manage',
            'units.manage',
            'categories.manage',
            'brands.manage',
            'products.view',
            'products.manage',
            'products.images',
            'suppliers.view',
            'suppliers.manage',
            'supplier_terms.manage',
            'inventory.view',
            'inventory.adjust',
            'inventory.transactions.view',
            'stock_transfers.request',
            'stock_transfers.approve',
            'inventory.alerts.view',
            'pos.access',
            'pos.scan',
            'pos.cart',
            'pos.discounts',
            'pos.checkout',
            'pos.invoices',
            'sales.view',
            'purchase_orders.view',
            'purchase_orders.create',
            'purchase_orders.approve',
            'goods.receiving',
            'customers.view',
            'customers.manage',
            'customers.loyalty',
            'customers.promotions',
            'dashboard.view',
            'reports.sales',
            'reports.inventory',
            'reports.charts',
            'ml.data_export',
            'ml.forecast.view',
            'ml.stock_risk.view',
            'ml.reorder.calculate',
            'ml.supplier_eval.view',
            'ml.slow_moving.view',
            'ml.anomaly.view',
            'ml.anomaly.review',
            'ml.market_basket.view',
            'ml.recommendations.view',
            'ml.recommendations.review',
            'nlq.query',
        ],

        Role::BRANCH_MANAGER => [
            'auth.login',
            'dashboard.view',
            'branches.view',
            'products.view',
            'inventory.view',
            'inventory.transactions.view',
            'stock_transfers.request',
            'stock_transfers.approve',
            'inventory.alerts.view',
            'pos.invoices',
            'sales.view',
            'purchase_orders.view',
            'customers.view',
            'reports.sales',
            'reports.inventory',
            'reports.charts',
            'ml.forecast.view',
            'ml.stock_risk.view',
            'ml.slow_moving.view',
            'ml.anomaly.view',
            'ml.anomaly.review',
            'ml.recommendations.view',
            'nlq.query',
        ],

        Role::INVENTORY_MANAGER => [
            'auth.login',
            'dashboard.view',
            'units.manage',
            'categories.manage',
            'brands.manage',
            'products.view',
            'products.manage',
            'products.images',
            'inventory.view',
            'inventory.adjust',
            'inventory.transactions.view',
            'stock_transfers.request',
            'inventory.alerts.view',
            'goods.receiving',
            'reports.inventory',
            'ml.forecast.view',
            'ml.stock_risk.view',
            'ml.slow_moving.view',
        ],

        Role::CASHIER => [
            'auth.login',
            'dashboard.view',
            'pos.access',
            'pos.scan',
            'pos.cart',
            'pos.discounts',
            'pos.checkout',
            'pos.invoices',
            'products.view',
            'inventory.view',
            'customers.view',
            'ml.market_basket.view',
        ],

        Role::SALES_EMPLOYEE => [
            'auth.login',
            'dashboard.view',
            'customers.view',
            'customers.manage',
            'customers.loyalty',
            'customers.promotions',
            'products.view',
            'sales.view',
            'pos.invoices',
            'reports.sales',
            'ml.market_basket.view',
        ],

        Role::PURCHASE_MANAGER => [
            'auth.login',
            'dashboard.view',
            'suppliers.view',
            'suppliers.manage',
            'supplier_terms.manage',
            'purchase_orders.view',
            'purchase_orders.create',
            'purchase_orders.approve',
            'products.view',
            'inventory.view',
            'ml.supplier_eval.view',
            'ml.reorder.calculate',
            'ml.recommendations.view',
            'ml.recommendations.review',
        ],

        Role::SYSTEM_ANALYST => [
            'auth.login',
            'dashboard.view',
            'reports.sales',
            'reports.inventory',
            'reports.charts',
            'ml.data_export',
            'ml.forecast.view',
            'ml.stock_risk.view',
            'ml.supplier_eval.view',
            'ml.slow_moving.view',
            'ml.anomaly.view',
            'ml.market_basket.view',
            'nlq.query',
            'audit_logs.view',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Section 2.3 Roles and Default Access Matrix (Proposed)
    |--------------------------------------------------------------------------
    |
    | Values: 'full' (●) or 'limited' (○), or specific action like 'receive', 'request', 'approve'.
    |
    */
    'access_matrix' => [
        'users_roles_settings' => [
            Role::SUPER_ADMIN => 'full',
        ],
        'branches' => [
            Role::SUPER_ADMIN => 'full',
            Role::BRANCH_MANAGER => 'limited',
        ],
        'catalog' => [
            Role::SUPER_ADMIN => 'full',
            Role::BRANCH_MANAGER => 'limited',
            Role::INVENTORY_MANAGER => 'full',
            Role::CASHIER => 'limited',
            Role::SALES_EMPLOYEE => 'limited',
            Role::PURCHASE_MANAGER => 'limited',
            Role::SYSTEM_ANALYST => 'limited',
        ],
        'suppliers_terms' => [
            Role::SUPER_ADMIN => 'full',
            Role::BRANCH_MANAGER => 'limited',
            Role::INVENTORY_MANAGER => 'limited',
            Role::PURCHASE_MANAGER => 'full',
            Role::SYSTEM_ANALYST => 'limited',
        ],
        'inventory' => [
            Role::SUPER_ADMIN => 'full',
            Role::BRANCH_MANAGER => 'full',
            Role::INVENTORY_MANAGER => 'full',
            Role::CASHIER => 'limited',
            Role::SALES_EMPLOYEE => 'limited',
            Role::PURCHASE_MANAGER => 'limited',
        ],
        'stock_transfers' => [
            Role::SUPER_ADMIN => 'full',
            Role::BRANCH_MANAGER => 'full',
            Role::INVENTORY_MANAGER => 'request',
        ],
        'pos' => [
            Role::SUPER_ADMIN => 'full',
            Role::BRANCH_MANAGER => 'full',
            Role::CASHIER => 'full',
            Role::SALES_EMPLOYEE => 'full',
        ],
        'discounts_markdown_under_threshold' => [
            Role::SUPER_ADMIN => 'full',
            Role::BRANCH_MANAGER => 'full',
        ],
        'markdown_approval_above_threshold' => [
            Role::SUPER_ADMIN => 'full',
        ],
        'purchases_receiving' => [
            Role::SUPER_ADMIN => 'full',
            Role::BRANCH_MANAGER => 'limited',
            Role::INVENTORY_MANAGER => 'receive',
            Role::PURCHASE_MANAGER => 'full',
            Role::SYSTEM_ANALYST => 'limited',
        ],
        'customers_promotions' => [
            Role::SUPER_ADMIN => 'full',
            Role::BRANCH_MANAGER => 'full',
            Role::CASHIER => 'limited',
            Role::SALES_EMPLOYEE => 'full',
        ],
        'reports_dashboards' => [
            Role::SUPER_ADMIN => 'full',
            Role::BRANCH_MANAGER => 'full',
            Role::INVENTORY_MANAGER => 'limited',
            Role::CASHIER => 'limited',
            Role::SALES_EMPLOYEE => 'limited',
            Role::PURCHASE_MANAGER => 'limited',
            Role::SYSTEM_ANALYST => 'full',
        ],
        'ai_recommendations' => [
            Role::SUPER_ADMIN => 'full',
            Role::BRANCH_MANAGER => 'full',
            Role::INVENTORY_MANAGER => 'limited',
            Role::PURCHASE_MANAGER => 'reorder',
            Role::SYSTEM_ANALYST => 'view',
        ],
        'cycle_count' => [
            Role::SUPER_ADMIN => 'full',
            Role::BRANCH_MANAGER => 'approve',
            Role::INVENTORY_MANAGER => 'perform',
        ],
        'analytics_jobs' => [
            Role::SUPER_ADMIN => 'full',
            Role::SYSTEM_ANALYST => 'full',
        ],
        'natural_language_queries' => [
            Role::SUPER_ADMIN => 'full',
            Role::BRANCH_MANAGER => 'full',
            Role::INVENTORY_MANAGER => 'limited',
            Role::SALES_EMPLOYEE => 'limited',
            Role::SYSTEM_ANALYST => 'full',
        ],
        'audit_log' => [
            Role::SUPER_ADMIN => 'full',
            Role::BRANCH_MANAGER => 'limited',
            Role::SYSTEM_ANALYST => 'limited',
        ],
    ],
];
