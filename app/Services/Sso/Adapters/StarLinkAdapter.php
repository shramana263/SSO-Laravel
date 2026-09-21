<?php

namespace App\Services\Sso\Adapters;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Throwable;

class StarLinkAdapter implements ProductAdapterInterface
{
    public function formatResponse(User $user, array $attributes = []): JsonResponse
    {
        // 1. Fetch dynamically from legacy Starlink DB (users) using mobile number
        $fetched = $this->fetchFromDatabase($user);
        $attributes = array_merge($fetched, $attributes);

        $phone = $user->mobile_number;
        $deviceType = request()->input('device_type') ?? 'ANDROID';
        $deviceName = request()->input('device_name') ?? '';
        $appVersion = request()->input('app_version') ?? '';
        $fcmToken = request()->input('fcm_token') ?? request()->input('registration_id') ?? '';
        $preferredLang = request()->input('preferred_app_lang') ?? '';

        // 2. Attempt live legacy login to obtain authentic Laravel Passport token
        try {
            $baseUrl = rtrim(config('services.starlink.url', env('STAR_LINK_URL', 'https://starlinkinfluencers.in')), '/');

            // Option A: Try direct sso-login if enabled
            $httpRes = Http::timeout(5)->asJson()->post($baseUrl . '/api/v1/auth/sso-login', [
                'phone'               => $phone,
                'mobile_number'       => $phone,
                'device_type'         => $deviceType,
                'device_name'         => $deviceName,
                'app_version'         => $appVersion,
                'fcm_token'           => $fcmToken,
                'preferred_app_lang'  => $preferredLang,
            ]);

            if ($httpRes->successful() && $httpRes->json('status') === true) {
                return response()->json($httpRes->json(), 200);
            }

            // Option B: 2-Step Send-OTP -> Login
            Http::timeout(5)->asJson()->post($baseUrl . '/api/v1/auth/send-otp', [
                'phone' => $phone,
            ]);

            $loginRes = Http::timeout(5)->asJson()->post($baseUrl . '/api/v1/auth/login', [
                'phone'              => $phone,
                'otp'                => 1234,
                'device_type'        => $deviceType,
                'device_name'        => $deviceName,
                'app_version'        => $appVersion,
                'fcm_token'          => $fcmToken,
                'preferred_app_lang' => $preferredLang,
            ]);

            if ($loginRes->successful() && $loginRes->json('status') === true) {
                return response()->json($loginRes->json(), 200);
            }
        } catch (Throwable $e) {
            // Fallback to database formatting if remote endpoint is unreachable
        }

        // 2. Fallback DB formatting
        $fetched = $this->fetchFromDatabase($user);
        $attributes = array_merge($fetched, $attributes);

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

    private function fetchFromDatabase(User $user): array
    {
        try {
            $u = DB::connection('src_link')->table('users')
                ->where(function ($query) use ($user) {
                    $query->where('phone', $user->mobile_number);
                    if (!empty($user->emp_code)) {
                        $query->orWhere('emp_code', $user->emp_code);
                    }
                })
                ->whereIn('role', [1, 2])
                ->where('status', 1)
                ->first();

            if ($u) {
                $role = (int) $u->role;
                $roleName = $role === 1 ? 'BDE' : 'Contractor';

                $attributes = [];
                foreach ((array) $u as $k => $v) {
                    if (in_array($k, ['password', 'remember_token'], true)) {
                        continue;
                    }
                    $attributes[$k] = $v;
                }
                $attributes['phone'] = $user->mobile_number;
                $attributes['role_name'] = $roleName;

                // Categories
                $category = null;
                if (!empty($u->points)) {
                    $categories = DB::connection('src_link')->table('mason_categories')->get();
                    foreach ($categories as $c) {
                        if ((int) $c->from_point <= (int) $u->points && (int) $c->to_point >= (int) $u->points) {
                            $category = (array) $c;
                            break;
                        }
                    }
                }
                $attributes['mason_category'] = $category;

                // Dealers
                $dealers = [];
                if ($role === 2) {
                    $dealerRows = DB::connection('src_link')->table('mason_dealers as md')
                        ->join('users as d', 'd.id', '=', 'md.dealer_id')
                        ->where('md.mason_id', $u->id)
                        ->select('d.id', 'd.name', 'd.phone', 'd.emp_code')
                        ->get();

                    foreach ($dealerRows as $d) {
                        $dealers[] = [
                            'id'       => (int) $d->id,
                            'name'     => $d->name,
                            'phone'    => $d->phone,
                            'emp_code' => $d->emp_code,
                        ];
                    }
                }
                $attributes['dealers'] = $dealers;

                // Parent TE / Mason dynamic lookup
                $attributes['te'] = null;
                $attributes['mason'] = null;
                if (!empty($u->parent)) {
                    if ($role === 2) {
                        $teRow = DB::connection('src_link')->table('users')
                            ->select('id', 'name', 'phone', 'emp_code')
                            ->where('id', $u->parent)
                            ->first();
                        if ($teRow) {
                            $attributes['te'] = (array) $teRow;
                        }
                    } elseif ($role === 3 || $role === 4) {
                        $masonRow = DB::connection('src_link')->table('users')
                            ->select('id', 'name', 'phone', 'parent as te_id')
                            ->where('id', $u->parent)
                            ->first();
                        if ($masonRow) {
                            $masonArr = (array) $masonRow;
                            $teDetail = null;
                            if (!empty($masonRow->te_id)) {
                                $teDetailRow = DB::connection('src_link')->table('users')
                                    ->select('name as te_name', 'phone as te_phone', 'emp_code')
                                    ->where('id', $masonRow->te_id)
                                    ->first();
                                $teDetail = $teDetailRow ? (array) $teDetailRow : null;
                            }
                            $masonArr['te_detail'] = $teDetail;
                            $attributes['mason'] = $masonArr;
                        }
                    }
                }

                return $attributes;
            }
        } catch (Throwable $e) {
            // Graceful fallback if database connection is not present
        }

        return [];
    }
}