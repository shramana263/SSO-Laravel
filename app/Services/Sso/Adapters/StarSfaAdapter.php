<?php

namespace App\Services\Sso\Adapters;

use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class StarSfaAdapter implements ProductAdapterInterface
{
    public function formatResponse(User $user, array $attributes = []): Response
    {
        // 1. Fetch dynamically from legacy SFA DB (employee_master) using mobile number
        $fetched = $this->fetchFromDatabase($user);
        $attributes = array_merge($fetched, $attributes);

        $empCode = $attributes['emp_code'] ?? ($user->emp_code ?? '');
        $newPassword = $attributes['newpassword'] ?? '1234';
        $deviceId = request()->input('device_id') ?? request()->input('deviceid') ?? ($attributes['deviceid'] ?? '');
        $regId = request()->input('registration_id') ?? request()->input('registrationid') ?? request()->input('fcm_token') ?? '';
        $empName = $attributes['emp_name'] ?? $user->name;
        $saleAccess = $attributes['sale_access'] ?? 'Primary';
        $acedns = $attributes['acedns'] ?? 'Y';

        // 2. Attempt live HTTP call to legacy SFA login endpoint
        try {
            $baseUrl = rtrim(config('services.starsfa.url', env('STAR_SFA_URL', 'https://starsfa.starcement.co.in')), '/');
            $httpRes = Http::asForm()->timeout(5)->post($baseUrl . '/emplogin-check-6.0.1.php', [
                'emp_code'       => $empCode,
                'newpassword'    => $newPassword,
                'deviceid'       => $deviceId,
                'registrationid' => $regId,
            ]);

            if ($httpRes->successful() && str_contains($httpRes->body(), '<recordset>')) {
                return response($httpRes->body(), 200)->header('Content-Type', 'text/xml; charset=utf-8');
            }
        } catch (Throwable $e) {
            // Fallback to local database formatting if remote endpoint is unreachable
        }

        // Persist new device ID and login timestamp to src_sfa changepassword table
        try {
            if (!empty($empCode)) {
                $saveData = [
                    'status'                  => 'true',
                    'loggedin_date_time'      => now(),
                    'last_operation_datetime' => now(),
                ];
                if (!empty($deviceId)) {
                    $saveData['deviceid'] = $deviceId;
                }
                if (!empty($reqRegId)) {
                    $saveData['registrationid'] = $reqRegId;
                }

                $existing = DB::connection('src_sfa')->table('changepassword')
                    ->where('emp_code', $empCode)
                    ->first();

                if ($existing) {
                    DB::connection('src_sfa')->table('changepassword')
                        ->where('emp_code', $empCode)
                        ->update($saveData);
                } else {
                    $saveData['emp_code']    = $empCode;
                    $saveData['newpassword'] = $newPassword;
                    $saveData['oldpassword'] = $newPassword;
                    $saveData['is_licensed'] = '1';
                    DB::connection('src_sfa')->table('changepassword')->insert($saveData);
                }
            }
        } catch (Throwable $e) {
            // Fallback gracefully if database table is not writable
        }

        $xml = "<?xml version='1.0' encoding='UTF-8'?><recordset><data>"
            . "<emp_code><![CDATA[{$empCode}]]></emp_code>"
            . "<emp_name><![CDATA[{$empName}]]></emp_name>"
            . "<sale_access><![CDATA[{$saleAccess}]]></sale_access>"
            . "<newpassword><![CDATA[{$newPassword}]]></newpassword>"
            . "<deviceid><![CDATA[{$deviceId}]]></deviceid>"
            . "<acedns><![CDATA[{$acedns}]]></acedns>"
            . "</data></recordset>";

        return response($xml, 200)->header('Content-Type', 'text/xml; charset=utf-8');
    }

    private function fetchFromDatabase(User $user): array
    {
        try {
            $row = DB::connection('src_sfa')->table('employee_master as e')
                ->leftJoin('changepassword as c', 'e.emp_code', '=', 'c.emp_code')
                ->where(function ($query) use ($user) {
                    $query->where('e.phone_no', $user->mobile_number);
                    if (!empty($user->emp_code)) {
                        $query->orWhere('e.emp_code', $user->emp_code);
                    }
                })
                ->whereRaw("UPPER(e.acedns) = 'Y'")
                ->select(
                    'e.emp_code',
                    'e.emp_name',
                    'e.sale_access',
                    'e.acedns',
                    'c.newpassword',
                    'c.deviceid'
                )
                ->first();

            if ($row) {
                return [
                    'emp_code'    => $row->emp_code,
                    'emp_name'    => $row->emp_name ?? $user->name,
                    'sale_access' => $row->sale_access ?? 'Primary',
                    'newpassword' => $row->newpassword ?? '1234',
                    'deviceid'    => $row->deviceid ?? '',
                    'acedns'      => $row->acedns ?? 'Y',
                ];
            }
        } catch (Throwable $e) {
            // Log or ignore if connection is not available (e.g. testing)
        }

        return [];
    }
}
