<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Seed the application's users table with default role accounts.
     */
    public function run(): void
    {
        $demoUsers = [
            Role::ADMIN => [
                'name' => 'Admin',
                'email' => 'admin@intellishop.com',
            ],
            Role::BRANCH_MANAGER => [
                'name' => 'Branch Manager',
                'email' => 'manager@intellishop.com',
            ],
            Role::INVENTORY_MANAGER => [
                'name' => 'Inventory Manager',
                'email' => 'inventory@intellishop.com',
            ],
            Role::CASHIER => [
                'name' => 'Cashier Employee',
                'email' => 'cashier@intellishop.com',
            ],
            Role::SALES_EMPLOYEE => [
                'name' => 'Sales Employee',
                'email' => 'sales@intellishop.com',
            ],
            Role::PURCHASE_MANAGER => [
                'name' => 'Purchase Manager',
                'email' => 'purchase@intellishop.com',
            ],
            Role::SYSTEM_ANALYST => [
                'name' => 'System Analyst',
                'email' => 'analyst@intellishop.com',
            ],
        ];

        foreach ($demoUsers as $roleName => $userInfo) {
            $role = Role::where('name', $roleName)->first();

            User::updateOrCreate(
                ['email' => $userInfo['email']],
                [
                    'name' => $userInfo['name'],
                    'password' => Hash::make('password'),
                    'role_id' => $role?->id,
                    'status' => 'active',
                ]
            );
        }
    }
}
