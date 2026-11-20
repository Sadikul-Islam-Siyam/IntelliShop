<?php

namespace App\Services\Nlq;

use App\Contracts\BranchScope;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * NlqAuthorizer (Sub-Feature #62)
 *
 * Enforces role-based permission validation and branch-scoped access controls
 * on resolved natural-language queries before any database execution occurs.
 * Strictly prevents role escalation and cross-branch data leaks.
 */
class NlqAuthorizer implements BranchScope
{
    /**
     * Map of intents to permitted canonical role names.
     */
    protected const INTENT_ROLE_MAP = [
        'sales_summary' => [
            Role::ADMIN,
            'super-admin',
            Role::SYSTEM_ANALYST,
            Role::BRANCH_MANAGER,
            Role::SALES_EMPLOYEE,
        ],
        'top_selling_products' => [
            Role::ADMIN,
            'super-admin',
            Role::SYSTEM_ANALYST,
            Role::BRANCH_MANAGER,
            Role::SALES_EMPLOYEE,
        ],
        'inventory_summary' => [
            Role::ADMIN,
            'super-admin',
            Role::SYSTEM_ANALYST,
            Role::BRANCH_MANAGER,
            Role::INVENTORY_MANAGER,
            Role::CASHIER,
            Role::SALES_EMPLOYEE,
        ],
        'low_stock_alerts' => [
            Role::ADMIN,
            'super-admin',
            Role::SYSTEM_ANALYST,
            Role::BRANCH_MANAGER,
            Role::INVENTORY_MANAGER,
            Role::PURCHASE_MANAGER,
        ],
        'stock_out_risk' => [
            Role::ADMIN,
            'super-admin',
            Role::SYSTEM_ANALYST,
            Role::BRANCH_MANAGER,
            Role::INVENTORY_MANAGER,
            Role::PURCHASE_MANAGER,
        ],
        'overstock_slow_moving' => [
            Role::ADMIN,
            'super-admin',
            Role::SYSTEM_ANALYST,
            Role::BRANCH_MANAGER,
            Role::INVENTORY_MANAGER,
            Role::PURCHASE_MANAGER,
        ],
        'expiring_batches_markdown' => [
            Role::ADMIN,
            'super-admin',
            Role::SYSTEM_ANALYST,
            Role::BRANCH_MANAGER,
            Role::INVENTORY_MANAGER,
        ],
        'supplier_ranking' => [
            Role::ADMIN,
            'super-admin',
            Role::SYSTEM_ANALYST,
            Role::BRANCH_MANAGER,
            Role::PURCHASE_MANAGER,
        ],
        'sales_anomalies' => [
            Role::ADMIN,
            'super-admin',
            Role::SYSTEM_ANALYST,
            Role::BRANCH_MANAGER,
        ],
        'phantom_inventory' => [
            Role::ADMIN,
            'super-admin',
            Role::SYSTEM_ANALYST,
            Role::BRANCH_MANAGER,
            Role::INVENTORY_MANAGER,
        ],
        'market_basket_cross_sell' => [
            Role::ADMIN,
            'super-admin',
            Role::SYSTEM_ANALYST,
            Role::BRANCH_MANAGER,
            Role::INVENTORY_MANAGER,
            Role::SALES_EMPLOYEE,
            Role::CASHIER,
        ],
    ];

    /**
     * Authorize user against the resolved intent and enforce strict branch scoping.
     *
     * @param  User  $user
     * @param  string  $intent
     * @param  array<string, mixed>  $slots
     * @return array<string, mixed> Sanitized slots with enforced branch scope
     *
     * @throws AuthorizationException
     */
    public function authorizeAndScope(User $user, string $intent, array $slots = []): array
    {
        $roleName = $user->role ? strtolower((string) $user->role->name) : 'guest';

        // 1. Role permission check for the specific intent
        $allowedRoles = self::INTENT_ROLE_MAP[$intent] ?? [Role::ADMIN, 'super-admin', Role::SYSTEM_ANALYST];
        $isRoleAllowed = in_array($roleName, array_map('strtolower', $allowedRoles), true)
            || $user->isAdmin()
            || $user->isSuperAdmin();

        if (!$isRoleAllowed) {
            throw new AuthorizationException(
                "Access denied: Role '{$roleName}' does not have permission to query '{$intent}'."
            );
        }

        // 2. Branch scope validation and enforcement
        $isGlobalUser = $user->isAdmin()
            || $user->isSuperAdmin()
            || $roleName === strtolower(Role::SYSTEM_ANALYST)
            || empty($user->branch_id);

        if (!$isGlobalUser) {
            $userBranchId = (int) $user->branch_id;

            // If a specific branch_id was provided in slots, verify it matches the user's branch
            if (!empty($slots['branch_id'])) {
                $requestedBranchId = (int) $slots['branch_id'];
                if ($requestedBranchId !== $userBranchId) {
                    throw new AuthorizationException(
                        "Cross-branch access denied: You are assigned to Branch #{$userBranchId} and cannot query Branch #{$requestedBranchId}."
                    );
                }
            } else {
                // Automatically restrict query to user's assigned branch
                $slots['branch_id'] = $userBranchId;
            }
        }

        return $slots;
    }

    /**
     * BranchScope interface implementation for Eloquent queries.
     */
    public function apply(Builder $query, ?int $branchId): Builder
    {
        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        return $query;
    }
}
