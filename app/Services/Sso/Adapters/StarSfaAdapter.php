<?php

namespace App\Services\Sso\Adapters;

use App\Models\User;
use Illuminate\Http\Response;

class StarSfaAdapter implements ProductAdapterInterface
{
    public function formatResponse(User $user, array $attributes): Response
    {
        $xml = "<?xml version='1.0' encoding='UTF-8'?>
        <recordset>
            <data>
                <emp_code><![CDATA[" . ($attributes['emp_code'] ?? $user->emp_code) . "]]></emp_code>
                <emp_name><![CDATA[{$user->name}]]></emp_name>
                <sale_access><![CDATA[" . ($attributes['sale_access'] ?? '') . "]]></sale_access>
                <deviceid><![CDATA[" . ($attributes['deviceid'] ?? '') . "]]></deviceid>
                <acedns><![CDATA[Y]]></acedns>
            </data>
        </recordset>";

        return response($xml, 200)->header('Content-Type', 'text/xml');
    }
}
