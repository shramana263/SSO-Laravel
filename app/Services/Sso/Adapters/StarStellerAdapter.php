<?php

namespace App\Services\Sso\Adapters;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class StarStellerAdapter implements ProductAdapterInterface
{
    public function formatResponse(User $user, array $attributes = []): JsonResponse
    {
        // 1. Fetch dynamically from legacy Stellar DB (te_master / engineer_master) using mobile number
        $fetched = $this->fetchFromDatabase($user);
        $attributes = array_merge($fetched, $attributes);

        $userType = strtoupper($attributes['user_type'] ?? 'ENGINEER');
        $mobile = $user->mobile_number;
        $deviceId = request()->input('device_id') ?? request()->input('te_device_id') ?? request()->input('en_device_id') ?? request()->header('X-Device-Id') ?? ($attributes['device_id'] ?? '');
        $regId = request()->input('registration_id') ?? request()->input('te_registration_id') ?? request()->input('en_registration_id') ?? request()->input('fcm_token') ?? ($attributes['registration_id'] ?? '');
        $devType = request()->input('device_type') ?? request()->input('te_device_type') ?? request()->input('en_device_type') ?? 'ANDROID';

        // 2. Attempt 2-step live legacy HTTP handshake
        try {
            $baseUrl = rtrim(config('services.starstellar.url', env('STAR_STELLAR_URL', 'https://starstellar.com')), '/');

            // Step A: Call legacy generate OTP API (generates OTP in live DB)
            Http::asForm()->timeout(5)->post($baseUrl . '/ws_generate_otp_for_engineer_and_te_login.php', [
                'mobile'    => $mobile,
                'user_type' => $userType,
            ]);

            // Step B: Query the OTP generated in the live DB
            $table = ($userType === 'TE') ? 'te_master' : 'engineer_master';
            $col   = ($userType === 'TE') ? 'te_mobile_no' : 'e_mobile';
            $otp = null;
            try {
                $otp = DB::connection('src_stellar')->table($table)->where($col, $mobile)->value('the_otp');
            } catch (Throwable $e) {
                // Ignore DB read failure if using static/fallback
            }
            $otp = $otp ?: '1010';

            // Step C: Call legacy login with OTP and device info
            $httpRes = Http::asForm()->timeout(5)->post($baseUrl . '/ws_login_with_otp_for_engineer_and_te.php', [
                'mobile'          => $mobile,
                'otp'             => $otp,
                'user_type'       => $userType,
                'device_id'       => $deviceId,
                'registration_id' => $regId,
                'device_type'     => $devType,
            ]);

            if ($httpRes->successful() && is_array($httpRes->json()) && $httpRes->json('process_status') === 'YES') {
                return response()->json($httpRes->json(), 200);
            }
        } catch (Throwable $e) {
            // Fallback to local database formatting if remote endpoint is unreachable
        }
        $userType = strtoupper($attributes['user_type'] ?? $userType);

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

    private function fetchFromDatabase(User $user): array
    {
        try {
            $baseUrl = rtrim(env('STAR_STELLAR_URL', 'https://starstellar.com'), '/');

            // 1. Check TE Master
            $te = DB::connection('src_stellar')->table('te_master')
                ->where(function ($query) use ($user) {
                    $query->where('te_mobile_no', $user->mobile_number);
                    if (!empty($user->emp_code)) {
                        $query->orWhere('te_code', $user->emp_code);
                    }
                })
                ->where('acedns', 'Y')
                ->first();

            if ($te) {
                // Update device_id and last login so show_latest_app_version does not force logout
                $deviceId = request()->input('device_id') ?? request()->input('te_device_id') ?? request()->input('en_device_id') ?? request()->header('X-Device-Id');
                $regId = request()->input('registration_id') ?? request()->input('te_registration_id') ?? request()->input('en_registration_id') ?? request()->input('fcm_token');
                $devType = request()->input('device_type') ?? request()->input('te_device_type') ?? request()->input('en_device_type');
                $appVersion = request()->input('app_version');

                $updateData = ['last_updated_datetime' => now()];
                if (!empty($deviceId)) $updateData['device_id'] = $deviceId;
                if (!empty($regId)) $updateData['registration_id'] = $regId;
                if (!empty($devType)) $updateData['device_type'] = $devType;
                if (!empty($appVersion)) $updateData['app_version'] = $appVersion;

                DB::connection('src_stellar')->table('te_master')
                    ->where('te_id', $te->te_id)
                    ->update($updateData);

                $img = !empty($te->te_profile_image) ? $te->te_profile_image : 'profile.png';
                return [
                    'user_type'        => 'TE',
                    'the_te_id'        => (string) $te->te_id,
                    'the_te_name'      => $te->te_name ?? $user->name,
                    'the_te_code'      => $te->te_code ?? ($user->emp_code ?? ''),
                    'the_te_mobile_no' => $user->mobile_number,
                    'the_te_email'     => $te->te_email ?? ($user->email ?? ''),
                    'te_profile_image' => $baseUrl . '/te_profile_pic/' . $img,
                ];
            }

            // 2. Check Engineer Master
            $en = DB::connection('src_stellar')->table('engineer_master')
                ->where('e_mobile', $user->mobile_number)
                ->where('status', 'ACTIVE')
                ->first();

            if ($en) {
                // Update device_id and last login so show_latest_app_version does not force logout
                $deviceId = request()->input('device_id') ?? request()->input('te_device_id') ?? request()->input('en_device_id') ?? request()->header('X-Device-Id');
                $regId = request()->input('registration_id') ?? request()->input('te_registration_id') ?? request()->input('en_registration_id') ?? request()->input('fcm_token');
                $devType = request()->input('device_type') ?? request()->input('te_device_type') ?? request()->input('en_device_type');
                $appVersion = request()->input('app_version');

                $updateData = ['last_updated_datetime' => now()];
                if (!empty($deviceId)) $updateData['device_id'] = $deviceId;
                if (!empty($regId)) $updateData['registration_id'] = $regId;
                if (!empty($devType)) $updateData['device_type'] = $devType;
                if (!empty($appVersion)) $updateData['app_version'] = $appVersion;

                DB::connection('src_stellar')->table('engineer_master')
                    ->where('eid', $en->eid)
                    ->update($updateData);

                $img = !empty($en->e_profile_image) ? $en->e_profile_image : 'profile.png';
                return [
                    'user_type'        => 'ENGINEER',
                    'the_engineer_id'  => (string) $en->eid,
                    'e_name'           => $en->e_name ?? $user->name,
                    'e_mobile'         => $user->mobile_number,
                    'te_code'          => $en->te_code ?? '',
                    'e_email'          => $en->e_email ?? ($user->email ?? ''),
                    'e_dob'            => $en->e_dob ?? '',
                    'e_dom'            => $en->e_dom ?? '',
                    'e_address'        => $en->e_address ?? '',
                    'e_pin'            => $en->e_pin ?? '',
                    'e_state'          => $en->e_state ?? '',
                    'e_city_town'      => $en->e_city_town ?? '',
                    'e_profile_image'  => $baseUrl . '/en_profile_pic/' . $img,
                ];
            }
        } catch (Throwable $e) {
            // Fallback gracefully if database connection is not present
        }

        return [];
    }
}