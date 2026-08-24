<?php

namespace App\Services\Sso\Adapters;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class StarLinkAdapter implements ProductAdapterInterface
{
    public function formatResponse(User $user, array $attributes): JsonResponse
    {
        $token = JWTAuth::fromUser($user);
        $role = $attributes['role'] ?? 1;
        $roleName = $attributes['role_name'] ?? ($role == 1 ? 'BDE' : 'Contractor');

        $userData = [
            'id' => $attributes['id'] ?? $user->id,
            'name' => $attributes['name'] ?? $user->name,
            'phone' => $attributes['phone'] ?? $user->mobile_number,
            'email' => $attributes['email'] ?? $user->email,
            'emp_code' => $attributes['emp_code'] ?? $user->emp_code,
            'role' => $role,
            'role_name' => $roleName,
            'category_id' => $attributes['category_id'] ?? null,
            'points' => $attributes['points'] ?? 0,
            'city' => $attributes['city'] ?? '',
            'state' => $attributes['state'] ?? '',
            'status' => $attributes['status'] ?? 1,
            'dealers' => $attributes['dealers'] ?? [],
            'te' => $attributes['te'] ?? null,
            'mason' => $attributes['mason'] ?? null,
            'mason_category' => $attributes['mason_category'] ?? null,
        ];

        // Include any additional metadata attributes
        foreach ($attributes as $key => $val) {
            if (!array_key_exists($key, $userData)) {
                $userData[$key] = $val;
            }
        }

        return response()->json([
            'status' => true,
            'data' => $userData,
            'access_token' => $token,
            'msg' => 'Log in successfull',
        ], 200);
    }
}