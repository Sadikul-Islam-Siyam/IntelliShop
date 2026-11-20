<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;

class NavigationBuilder
{
    /**
     * Resolve the user's canonical role dashboard URL directly (avoids /dashboard -> 302 redirect hop).
     */
    public static function dashboardUrl(?User $user): string
    {
        if ($user && $user->role) {
            $map = config('permissions.role_redirect_map', []);
            $roleName = $user->role->name;
            if (isset($map[$roleName])) {
                return $map[$roleName];
            }
        }

        return '/dashboard';
    }

    /**
     * Build grouped, role-aware sidebar navigation items with active state.
     *
     * @return array<int, array{group: string, items: array<int, array<string, mixed>>}>
     */
    public static function sidebarGroups(?User $user): array
    {
        $roleSlug = self::resolveRoleSlug($user);
        $currentPath = '/' . ltrim(request()->path(), '/');

        $groups = [
            [
                'group' => 'Overview',
                'items' => [
                    [
                        'label' => 'Dashboard',
                        'href' => self::dashboardUrl($user),
                        'icon' => 'grid',
                        'match' => ['/dashboard'],
                        'roles' => ['*'],
                    ],
                ],
            ],
            [
                'group' => 'Operations',
                'items' => [
                    [
                        'label' => 'Branches',
                        'href' => route('branches.index'),
                        'icon' => 'building',
                        'match' => ['/branches'],
                        'roles' => ['admin', 'super-admin', 'branch-manager'],
                    ],
                    [
                        'label' => 'Customers',
                        'href' => route('customers.index'),
                        'icon' => 'users',
                        'match' => ['/customers'],
                        'roles' => ['admin', 'super-admin', 'branch-manager', 'sales-employee', 'cashier'],
                    ],
                    [
                        'label' => 'Promotions',
                        'href' => route('promotions.index'),
                        'icon' => 'tag',
                        'match' => ['/promotions'],
                        'roles' => ['admin', 'super-admin', 'branch-manager', 'sales-employee', 'cashier'],
                    ],
                    [
                        'label' => 'Suppliers',
                        'href' => route('suppliers.index'),
                        'icon' => 'truck',
                        'match' => ['/suppliers'],
                        'exclude' => ['/suppliers/evaluate', '/suppliers/ranking'],
                        'roles' => ['admin', 'super-admin', 'branch-manager', 'inventory-manager', 'purchase-manager', 'system-analyst'],
                    ],
                    [
                        'label' => 'Supplier Ranking',
                        'href' => route('suppliers.evaluate'),
                        'icon' => 'award',
                        'match' => ['/suppliers/evaluate', '/suppliers/ranking'],
                        'roles' => ['admin', 'super-admin', 'branch-manager', 'inventory-manager', 'purchase-manager', 'system-analyst'],
                    ],
                ],
            ],
            [
                'group' => 'Inventory & Planning',
                'items' => [
                    [
                        'label' => 'Demand Forecasting',
                        'href' => route('forecasts.index'),
                        'icon' => 'trending',
                        'match' => ['/forecasting', '/forecasts'],
                        'roles' => ['admin', 'super-admin', 'system-analyst', 'branch-manager', 'inventory-manager', 'purchase-manager'],
                    ],
                    [
                        'label' => 'Stock-Out Risk',
                        'href' => route('stock-risk.index'),
                        'icon' => 'alert',
                        'match' => ['/stock-risk'],
                        'roles' => ['admin', 'super-admin', 'system-analyst', 'branch-manager', 'inventory-manager', 'purchase-manager'],
                    ],
                    [
                        'label' => 'Replenishment',
                        'href' => route('reorder.index'),
                        'icon' => 'refresh',
                        'match' => ['/reorders', '/reorder'],
                        'roles' => ['admin', 'super-admin', 'system-analyst', 'branch-manager', 'inventory-manager', 'purchase-manager'],
                    ],
                    [
                        'label' => 'Overstock Alerts',
                        'href' => route('overstock.index'),
                        'icon' => 'layers',
                        'match' => ['/overstock', '/slow-moving'],
                        'roles' => ['admin', 'super-admin', 'system-analyst', 'branch-manager', 'inventory-manager', 'purchase-manager'],
                    ],
                ],
            ],
            [
                'group' => 'Analytics & AI',
                'items' => [
                    [
                        'label' => 'Reports & Charts',
                        'href' => route('reports.index'),
                        'icon' => 'bar-chart',
                        'match' => ['/reports'],
                        'roles' => ['admin', 'super-admin', 'branch-manager', 'inventory-manager', 'purchase-manager', 'sales-employee', 'system-analyst'],
                    ],
                    [
                        'label' => 'Recommendations',
                        'href' => route('recommendations.index'),
                        'icon' => 'sparkles',
                        'match' => ['/recommendations'],
                        'roles' => ['admin', 'super-admin', 'system-analyst', 'branch-manager', 'inventory-manager', 'purchase-manager'],
                    ],
                    [
                        'label' => 'Sales Anomalies',
                        'href' => route('anomalies.index'),
                        'icon' => 'activity',
                        'match' => ['/anomalies'],
                        'roles' => ['admin', 'super-admin', 'system-analyst', 'branch-manager', 'inventory-manager', 'purchase-manager'],
                    ],
                    [
                        'label' => 'Market Basket',
                        'href' => route('market-basket.index'),
                        'icon' => 'shopping-bag',
                        'match' => ['/market-basket'],
                        'roles' => ['admin', 'super-admin', 'system-analyst', 'branch-manager', 'inventory-manager', 'purchase-manager', 'sales-employee', 'cashier'],
                    ],
                    [
                        'label' => 'NLQ Query',
                        'href' => route('nlq.index'),
                        'icon' => 'search',
                        'match' => ['/nlq'],
                        'roles' => ['admin', 'super-admin', 'system-analyst', 'branch-manager', 'inventory-manager', 'purchase-manager', 'sales-employee'],
                    ],
                ],
            ],
            [
                'group' => 'Administration',
                'items' => [
                    [
                        'label' => 'Audit Logs',
                        'href' => route('audit-logs.index'),
                        'icon' => 'shield',
                        'match' => ['/audit-logs'],
                        'roles' => ['admin', 'super-admin'],
                    ],
                    [
                        'label' => 'System Settings',
                        'href' => route('settings.index'),
                        'icon' => 'sliders',
                        'match' => ['/settings'],
                        'roles' => ['admin', 'super-admin'],
                    ],
                ],
            ],
        ];

        $filtered = [];
        foreach ($groups as $group) {
            $visibleItems = [];
            foreach ($group['items'] as $item) {
                if (!self::canAccess($roleSlug, $item['roles'])) {
                    continue;
                }
                $item['active'] = self::isActive($currentPath, $item['match'], $item['exclude'] ?? []);
                $visibleItems[] = $item;
            }
            if (!empty($visibleItems)) {
                $filtered[] = [
                    'group' => $group['group'],
                    'items' => $visibleItems,
                ];
            }
        }

        return $filtered;
    }

    /**
     * Build breadcrumbs for the current route.
     *
     * @return array<int, array{label: string, href: ?string}>
     */
    public static function breadcrumbs(?User $user): array
    {
        $dashUrl = self::dashboardUrl($user);
        $currentPath = '/' . ltrim(request()->path(), '/');

        if (Str::startsWith($currentPath, '/dashboard')) {
            return [
                ['label' => 'Dashboard', 'href' => null],
            ];
        }

        $crumbs = [
            ['label' => 'Dashboard', 'href' => $dashUrl],
        ];

        foreach (self::sidebarGroups($user) as $group) {
            foreach ($group['items'] as $item) {
                if ($item['active']) {
                    $crumbs[] = ['label' => $group['group'], 'href' => null];
                    $crumbs[] = ['label' => $item['label'], 'href' => null];
                    return $crumbs;
                }
            }
        }

        $segment = trim($currentPath, '/');
        if ($segment !== '') {
            $crumbs[] = [
                'label' => Str::headline(str_replace('/', ' / ', $segment)),
                'href' => null,
            ];
        }

        return $crumbs;
    }

    protected static function resolveRoleSlug(?User $user): string
    {
        if (!$user || !$user->role) {
            return '';
        }

        $name = Str::kebab(str_replace('_', '-', strtolower((string) $user->role->name)));
        if ($name === 'administrator') {
            return 'admin';
        }

        return $name;
    }

    /**
     * @param  array<int, string>  $allowedRoles
     */
    protected static function canAccess(string $roleSlug, array $allowedRoles): bool
    {
        if (in_array('*', $allowedRoles, true)) {
            return true;
        }

        if ($roleSlug === 'admin' || $roleSlug === 'super-admin') {
            return true;
        }

        return in_array($roleSlug, $allowedRoles, true);
    }

    /**
     * @param  array<int, string>  $prefixes
     * @param  array<int, string>  $excludes
     */
    protected static function isActive(string $currentPath, array $prefixes, array $excludes = []): bool
    {
        foreach ($excludes as $ex) {
            if ($currentPath === $ex || Str::startsWith($currentPath, $ex . '/')) {
                return false;
            }
        }

        foreach ($prefixes as $prefix) {
            if ($currentPath === $prefix || Str::startsWith($currentPath, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }
}
