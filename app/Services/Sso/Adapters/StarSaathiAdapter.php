<?php

namespace App\Services\Sso\Adapters;

use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;

class StarSaathiAdapter implements ProductAdapterInterface
{
    public function formatResponse(User $user, array $attributes): Response|JsonResponse
    {
        $payload = [
            'status' => 'SUCCESS',
            'customer_code' => $attributes['customer_code'] ?? '',
            'sale_access' => $attributes['sale_access'] ?? 'PRIMARY',
            'deviceid' => $attributes['deviceid'] ?? '',
        ];

        // Replace with actual legacy encryption logic (e.g., AES)
        $encryptedData = base64_encode(json_encode($payload));

        return response()->json(['data' => $encryptedData], 200);
    }
}