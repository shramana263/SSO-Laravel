<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;

class AccessScopeService
{
    public function buildAccessScope(User $user): array
    {
        $isGlobal = $user->hasRole('global_admin');

        if ($isGlobal) {
            $products = Product::where('is_active', true)
                ->get(['slug', 'redirect_url']);

            return [
                'is_global' => true,
                'allowed_products' => $products,
                'default_redirect' => null,
            ];
        }

        // Map non-global user roles to individual products
        $userRoles = $user->getRoleNames();
        $allowedProductSlugs = [];

        foreach ($userRoles as $role) {
            // Extracts 'product_1' from 'product_1_user'
            if (str_contains($role, '_user') || str_contains($role, '_admin')) {
                $slug = str_replace(['_user', '_admin'], '', $role);
                $allowedProductSlugs[] = $slug;
            }
        }

        $products = Product::whereIn('slug', $allowedProductSlugs)
            ->where('is_active', true)
            ->get(['slug', 'redirect_url']);

        return [
            'is_global' => false,
            'allowed_products' => $products,
            // Automatically grab the exact URL of their only allowed product
            'default_redirect' => $products->first() ? $products->first()->redirect_url : null,
        ];
    }
}