<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * IntelliShop Role Model
 *
 * Implements Sub-Feature #1: Roles & Permissions Schema (Sharif, Week 1).
 * Per Decision M11:
 * "roles table has no permissions column; permission sets in config/permissions.php."
 * Schema v2 / Appendix D:
 * Table roles: id, name, description, timestamps.
 */
class Role extends Model
{
    use HasFactory;

    protected $table = 'roles';

    protected $fillable = [
        'name',
        'description',
    ];

    // Canonical Role Names (per IntelliShop Design Documentation)
    public const SUPER_ADMIN = 'Super Admin';
    public const BRANCH_MANAGER = 'Branch Manager';
    public const INVENTORY_MANAGER = 'Inventory Manager';
    public const CASHIER = 'Cashier';
    public const SALES_EMPLOYEE = 'Sales Employee';
    public const PURCHASE_MANAGER = 'Purchase Manager';
    public const SYSTEM_ANALYST = 'System Analyst';

    public const ALL_ROLES = [
        self::SUPER_ADMIN,
        self::BRANCH_MANAGER,
        self::INVENTORY_MANAGER,
        self::CASHIER,
        self::SALES_EMPLOYEE,
        self::PURCHASE_MANAGER,
        self::SYSTEM_ANALYST,
    ];

    // System Permissions Constants
    // 1. Auth & Admin
    public const PERM_AUTH_LOGIN = 'auth.login';
    public const PERM_ROLES_VIEW = 'roles.view';
    public const PERM_ROLES_MANAGE = 'roles.manage';
    public const PERM_USERS_VIEW = 'users.view';
    public const PERM_USERS_MANAGE = 'users.manage';
    public const PERM_AUDIT_LOGS_VIEW = 'audit_logs.view';

    // 2. Branch Operations
    public const PERM_BRANCHES_VIEW = 'branches.view';
    public const PERM_BRANCHES_MANAGE = 'branches.manage';

    // 3. Product Catalog
    public const PERM_UNITS_MANAGE = 'units.manage';
    public const PERM_CATEGORIES_MANAGE = 'categories.manage';
    public const PERM_BRANDS_MANAGE = 'brands.manage';
    public const PERM_PRODUCTS_VIEW = 'products.view';
    public const PERM_PRODUCTS_MANAGE = 'products.manage';
    public const PERM_PRODUCTS_IMAGES = 'products.images';

    // 4. Supplier Management
    public const PERM_SUPPLIERS_VIEW = 'suppliers.view';
    public const PERM_SUPPLIERS_MANAGE = 'suppliers.manage';
    public const PERM_SUPPLIER_TERMS_MANAGE = 'supplier_terms.manage';

    // 5. Inventory Management
    public const PERM_INVENTORY_VIEW = 'inventory.view';
    public const PERM_INVENTORY_ADJUST = 'inventory.adjust';
    public const PERM_INVENTORY_TRANSACTIONS = 'inventory.transactions.view';
    public const PERM_STOCK_TRANSFERS_REQUEST = 'stock_transfers.request';
    public const PERM_STOCK_TRANSFERS_APPROVE = 'stock_transfers.approve';
    public const PERM_LOW_STOCK_ALERTS = 'inventory.alerts.view';

    // 6. POS & Sales
    public const PERM_POS_ACCESS = 'pos.access';
    public const PERM_POS_SCAN = 'pos.scan';
    public const PERM_POS_CART = 'pos.cart';
    public const PERM_POS_DISCOUNTS = 'pos.discounts';
    public const PERM_POS_CHECKOUT = 'pos.checkout';
    public const PERM_POS_INVOICES = 'pos.invoices';
    public const PERM_SALES_VIEW = 'sales.view';

    // 7. Purchase & Receiving
    public const PERM_PURCHASE_ORDERS_VIEW = 'purchase_orders.view';
    public const PERM_PURCHASE_ORDERS_CREATE = 'purchase_orders.create';
    public const PERM_PURCHASE_ORDERS_APPROVE = 'purchase_orders.approve';
    public const PERM_GOODS_RECEIVING = 'goods.receiving';

    // 8. Customer Management
    public const PERM_CUSTOMERS_VIEW = 'customers.view';
    public const PERM_CUSTOMERS_MANAGE = 'customers.manage';
    public const PERM_LOYALTY_POINTS = 'customers.loyalty';
    public const PERM_CUSTOMER_PROMOTIONS = 'customers.promotions';

    // 9. Reports & Dashboards
    public const PERM_DASHBOARD_VIEW = 'dashboard.view';
    public const PERM_REPORTS_SALES = 'reports.sales';
    public const PERM_REPORTS_INVENTORY = 'reports.inventory';
    public const PERM_REPORTS_CHARTS = 'reports.charts';

    // 10. AI / ML & Decision Support
    public const PERM_ML_DATA_EXPORT = 'ml.data_export';
    public const PERM_ML_FORECAST_VIEW = 'ml.forecast.view';
    public const PERM_ML_STOCK_RISK_VIEW = 'ml.stock_risk.view';
    public const PERM_ML_REORDER_CALCULATE = 'ml.reorder.calculate';
    public const PERM_ML_SUPPLIER_EVAL = 'ml.supplier_eval.view';
    public const PERM_ML_SLOW_MOVING = 'ml.slow_moving.view';
    public const PERM_ML_ANOMALY_VIEW = 'ml.anomaly.view';
    public const PERM_ML_ANOMALY_REVIEW = 'ml.anomaly.review';
    public const PERM_ML_MARKET_BASKET = 'ml.market_basket.view';
    public const PERM_ML_RECOMMENDATIONS_VIEW = 'ml.recommendations.view';
    public const PERM_ML_RECOMMENDATIONS_REVIEW = 'ml.recommendations.review';
    public const PERM_NLQ_QUERY = 'nlq.query';

    /**
     * Relationship: A role has many users.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Slug attribute helper (e.g. 'super_admin', 'branch_manager').
     */
    public function getSlugAttribute(): string
    {
        return Str::snake(Str::studly($this->name));
    }

    /**
     * Accessor for permissions attribute.
     * Per Decision M11, permissions live in config/permissions.php.
     *
     * @return array<string>
     */
    public function getPermissionsAttribute(): array
    {
        return config("permissions.roles.{$this->name}", []);
    }

    /**
     * Check if this role possesses a specific permission.
     */
    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /**
     * Check if this role has any of the specified permissions.
     */
    public function hasAnyPermission(array $permissions): bool
    {
        $perms = $this->permissions;
        foreach ($permissions as $permission) {
            if (in_array($permission, $perms, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if this role has all of the specified permissions.
     */
    public function hasAllPermissions(array $permissions): bool
    {
        $perms = $this->permissions;
        foreach ($permissions as $permission) {
            if (!in_array($permission, $perms, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Get default permissions for a role name directly from config.
     */
    public static function getPermissionsFor(string $roleName): array
    {
        return config("permissions.roles.{$roleName}", []);
    }

    /**
     * Return all permission keys across all groups as a flat array.
     */
    public static function allPermissionKeys(): array
    {
        $groups = config('permissions.permission_groups', []);
        $keys = [];
        foreach ($groups as $groupPerms) {
            $keys = array_merge($keys, array_keys($groupPerms));
        }
        return array_values(array_unique($keys));
    }
}
