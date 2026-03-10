<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Create default tenant
        $tenant = Tenant::firstOrCreate(
            ['code' => 'default'],
            [
                'name'      => 'Default Organization',
                'plan'      => 'enterprise',
                'is_active' => true,
                'settings'  => [],
            ]
        );

        // Create super admin
        User::firstOrCreate(
            ['email' => 'admin@default.com'],
            [
                'name'        => 'Super Admin',
                'password'    => Hash::make('password'),
                'tenant_id'   => $tenant->id,
                'role'        => 'super_admin',
                'permissions' => ['*'],
                'is_active'   => true,
            ]
        );

        // Create a regular staff user
        User::firstOrCreate(
            ['email' => 'staff@default.com'],
            [
                'name'        => 'Staff User',
                'password'    => Hash::make('password'),
                'tenant_id'   => $tenant->id,
                'role'        => 'staff',
                'permissions' => ['view_products', 'view_inventory', 'create_orders'],
                'is_active'   => true,
            ]
        );

        // Create a second tenant
        $tenant2 = Tenant::firstOrCreate(
            ['code' => 'acme'],
            [
                'name'      => 'Acme Corporation',
                'plan'      => 'professional',
                'is_active' => true,
                'settings'  => [],
            ]
        );

        User::firstOrCreate(
            ['email' => 'admin@acme.com'],
            [
                'name'        => 'Acme Admin',
                'password'    => Hash::make('password'),
                'tenant_id'   => $tenant2->id,
                'role'        => 'admin',
                'permissions' => ['manage_products', 'manage_inventory', 'manage_orders', 'manage_users'],
                'is_active'   => true,
            ]
        );
    }
}
