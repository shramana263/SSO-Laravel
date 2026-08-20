<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Product;
use App\Models\UserProductAccess;
use App\Models\UserProductMetadata;

class SsoTestDataSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create the Products 
        // (Keeping 'star_steller' as the slug to match your earlier adapter setup)
        $products = [
            'star_saathi' => Product::create(['slug' => 'star_saathi', 'name' => 'Star Saathi']),
            'star_steller' => Product::create(['slug' => 'star_steller', 'name' => 'Star Stellar']), 
            'star_link' => Product::create(['slug' => 'star_link', 'name' => 'Star Link']),
            'star_sfa' => Product::create(['slug' => 'star_sfa', 'name' => 'Star SFA']),
        ];

        // 2. Define Test Users and map them to their permitted applications
        $testUsers = [
            [
                'name' => 'Rajesh Dealer', 
                'mobile' => '9000000001', 
                'role' => 'Dealer',
                'access' => ['star_saathi'] // Dealers only get Star Saathi
            ],
            [
                'name' => 'Amit Site Engineer', 
                'mobile' => '9000000002', 
                'role' => 'Site Engineer',
                'access' => ['star_steller'] // Site Engineers only get Star Stellar
            ],
            [
                'name' => 'Vikram Mason', 
                'mobile' => '9000000003', 
                'role' => 'Mason',
                'access' => ['star_link'] // Masons only get Star Link
            ],
            [
                'name' => 'Priya BDE', 
                'mobile' => '9000000004', 
                'role' => 'BDE',
                'access' => ['star_steller', 'star_link', 'star_sfa'] // BDEs get multi-app access
            ],
            [
                'name' => 'Arjun Sales', 
                'mobile' => '9000000005', 
                'role' => 'Sales Team',
                'access' => ['star_sfa'] // Sales Team gets Star SFA
            ]
        ];

        // 3. Loop through and populate the database
        foreach ($testUsers as $userData) {
            $user = User::create([
                'mobile_number' => $userData['mobile'],
                'name' => $userData['name'],
                'email' => strtolower(str_replace(' ', '.', $userData['name'])) . '@starone.com',
                'emp_code' => 'EMP' . rand(1000, 9999),
                'status' => true,
            ]);

            foreach ($userData['access'] as $productSlug) {
                $product = $products[$productSlug];

                // Grant Access Entry
                UserProductAccess::create([
                    'user_id' => $user->id,
                    'product_id' => $product->id,
                    'role_name' => $userData['role'],
                ]);

                // Generate contextual legacy metadata for each specific app
                $attributes = match($productSlug) {
                    'star_saathi' => ['customer_code' => 'CUST_' . $user->id, 'sale_access' => 'PRIMARY', 'deviceid' => 'DEV_' . $user->id],
                    'star_sfa' => ['sale_access' => 'ALL', 'deviceid' => 'DEV_' . $user->id],
                    'star_link' => ['role' => 2, 'role_name' => $userData['role'], 'city' => 'Kolkata'],
                    'star_steller' => ['user_type' => strtoupper($userData['role']), 'the_engineer_id' => 'TE_' . $user->id, 'e_address' => 'Site A', 'e_pin' => '700001'],
                };

                UserProductMetadata::create([
                    'user_id' => $user->id,
                    'product_id' => $product->id,
                    'attributes' => $attributes,
                ]);
            }
        }
    }
}