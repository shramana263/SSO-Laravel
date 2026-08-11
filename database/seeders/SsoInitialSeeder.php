<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class SsoInitialSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Seed Products
        $products = [
            ['slug' => 'product_1', 'redirect_url' => 'https://product1.company.com/dashboard'],
            ['slug' => 'product_2', 'redirect_url' => 'https://product2.company.com/dashboard'],
            ['slug' => 'product_3', 'redirect_url' => 'https://product3.company.com/dashboard'],
            ['slug' => 'product_4', 'redirect_url' => 'https://product4.company.com/dashboard'],
        ];

        foreach ($products as $prod) {
            Product::updateOrCreate(['slug' => $prod['slug']], $prod);
        }

        // 2. Seed Roles
        $roles = [
            'global_admin',
            'product_1_user',
            'product_2_user',
            'product_3_user',
            'product_4_user',
        ];

        foreach ($roles as $roleName) {
            Role::findOrCreate($roleName, 'api');
        }

        // 3. Seed Sample Global Admin
        $admin = User::firstOrCreate([
            'mobile_number' => '+919876543210'
        ], [
            'country_code' => '+91',
            'name' => 'Global Admin',
            'email' => 'admin@company.com',
            'is_active' => true,
        ]);
        $admin->assignRole('global_admin');

        // 4. Seed Sample Product User
        $user1 = User::firstOrCreate([
            'mobile_number' => '+919123456789'
        ], [
            'country_code' => '+91',
            'name' => 'Product One User',
            'email' => 'user1@company.com',
            'is_active' => true,
        ]);
        $user1->assignRole('product_1_user');
    }
}