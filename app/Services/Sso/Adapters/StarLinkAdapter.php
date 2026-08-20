<?php

namespace App\Services\Sso\Adapters;

use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;

class StarLinkAdapter implements ProductAdapterInterface
{
    public function formatResponse(User $user, array $attributes): Response | JsonResponse
    {
        return response()->json([
            'access_token' => \Tymon\JWTAuth\Facades\JWTAuth::fromUser($user),
            'token_type' => 'Bearer',
            'role' => $attributes['role'] ?? 1,
            'role_name' => $attributes['role_name'] ?? 'Mason',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'mobile' => $user->mobile_number,
                'city' => $attributes['city'] ?? ''
            ]
        ], 200);
    }
}