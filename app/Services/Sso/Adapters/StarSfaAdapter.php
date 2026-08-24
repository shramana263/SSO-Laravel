<?php

namespace App\Services\Sso\Adapters;

use App\Models\User;
use Illuminate\Http\Response;

class StarSfaAdapter implements ProductAdapterInterface
{
    public function formatResponse(User $user, array $attributes): Response
    {
        $empCode = $attributes['emp_code'] ?? ($user->emp_code ?? '');
        $empName = $attributes['emp_name'] ?? $user->name;
        $saleAccess = $attributes['sale_access'] ?? 'Primary';
        $newPassword = $attributes['newpassword'] ?? '1234';
        $deviceId = $attributes['deviceid'] ?? '';
        $acedns = $attributes['acedns'] ?? 'Y';

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
}
