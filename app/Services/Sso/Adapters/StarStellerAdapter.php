<?php

namespace App\Services\Sso\Adapters;

use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;

class StarStellerAdapter implements ProductAdapterInterface
{
    public function formatResponse(User $user, array $attributes): Response|JsonResponse
    {
        return response()->json([
            'process_status' => 'YES',
            'user_type' => $attributes['user_type'] ?? 'ENGINEER',
            'the_engineer_id' => $attributes['the_engineer_id'] ?? $user->id,
            'te_code' => $attributes['te_code'] ?? '',
            'e_address' => $attributes['e_address'] ?? '',
            'e_pin' => $attributes['e_pin'] ?? ''
        ], 200);
    }
}