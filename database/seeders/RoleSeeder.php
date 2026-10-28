<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Seeds the 7 canonical roles with their descriptions into the roles table.
     * Per Decision M11, permission sets live in config/permissions.php.
     * Uses updateOrCreate to guarantee idempotency across multiple runs.
     */
    public function run(): void
    {
        $canonicalRoles = config('permissions.canonical_roles');

        if (empty($canonicalRoles)) {
            $canonicalRoles = [
                Role::ADMIN => [
                    'description' => 'System-wide administrative control across all branches and modules.',
                ],
                Role::BRANCH_MANAGER => [
                    'description' => 'Manages branch operations, branch inventory, stock transfers, sales, and branch-level analytics.',
                ],
                Role::INVENTORY_MANAGER => [
                    'description' => 'Oversees product catalog, stock adjustments, goods receiving, and low-stock alerts.',
                ],
                Role::CASHIER => [
                    'description' => 'Operates the Point of Sale terminal, processes customer sales, and prints receipts.',
                ],
                Role::SALES_EMPLOYEE => [
                    'description' => 'Manages customer relationships, customer segments, loyalty points, and promotional campaigns.',
                ],
                Role::PURCHASE_MANAGER => [
                    'description' => 'Manages suppliers, procurement terms, purchase orders, and supplier evaluation rankings.',
                ],
                Role::SYSTEM_ANALYST => [
                    'description' => 'Monitors analytics data pipelines, demand forecasting backtests, anomaly detection, and business queries.',
                ],
            ];
        }

        foreach ($canonicalRoles as $roleName => $data) {
            Role::updateOrCreate(
                ['name' => $roleName],
                [
                    'description' => $data['description'] ?? null,
                ]
            );
        }
    }
}
