<?php

namespace App\Services\Sso\Adapters;

use App\Models\User;
use Illuminate\Http\JsonResponse;

class StarStellerAdapter implements ProductAdapterInterface
{
    public function formatResponse(User $user, array $attributes): JsonResponse
    {
        $userType = strtoupper($attributes['user_type'] ?? 'ENGINEER');

        if ($userType === 'TE') {
            $response = [
                'process_status' => 'YES',
                'process_message' => 'Success.',
                'user_type' => 'TE',
                'the_te_id' => (string) ($attributes['the_te_id'] ?? $user->id),
                'the_te_name' => $attributes['the_te_name'] ?? $user->name,
                'the_te_code' => $attributes['the_te_code'] ?? ($user->emp_code ?? ''),
                'the_te_mobile_no' => $attributes['the_te_mobile_no'] ?? $user->mobile_number,
                'the_te_email' => $attributes['the_te_email'] ?? ($user->email ?? ''),
                'te_profile_image' => $attributes['te_profile_image'] ?? '',
            ];
        } else {
            $response = [
                'process_status' => 'YES',
                'process_message' => 'Success.',
                'user_type' => 'ENGINEER',
                'the_engineer_id' => (string) ($attributes['the_engineer_id'] ?? $user->id),
                'e_name' => $attributes['e_name'] ?? $user->name,
                'e_mobile' => $attributes['e_mobile'] ?? $user->mobile_number,
                'te_code' => $attributes['te_code'] ?? '',
                'e_email' => $attributes['e_email'] ?? ($user->email ?? ''),
                'e_dob' => $attributes['e_dob'] ?? '',
                'e_dom' => $attributes['e_dom'] ?? '',
                'e_address' => $attributes['e_address'] ?? '',
                'e_pin' => $attributes['e_pin'] ?? '',
                'e_state' => $attributes['e_state'] ?? '',
                'e_city_town' => $attributes['e_city_town'] ?? '',
                'e_profile_image' => $attributes['e_profile_image'] ?? '',
            ];
        }

        return response()->json($response, 200);
    }
}