<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\UserProductAccess;

class EnsureProductAccess
{
    public function handle(Request $request, Closure $next)
    {
        $user = auth('api')->user();
        $productKey = $request->route('productKey');

        $product = Product::where('slug', $productKey)->first();

        if (!$product) {
            return response()->json(['status' => false, 'message' => 'Invalid product key'], 404);
        }

        $hasAccess = UserProductAccess::where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->exists();

        if (!$hasAccess) {
            return response()->json(['status' => false, 'message' => 'Unauthorized panel access'], 403);
        }

        return $next($request);
    }
}