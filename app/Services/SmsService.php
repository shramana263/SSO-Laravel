<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    /**
     * Send OTP via Star Cement's MyVFirst SMS Gateway.
     *
     * Single central message for the Star One app - the user picks which
     * product to open AFTER login, so the OTP stage must stay product-neutral.
     *
     * DLT NOTE: this wording is new and must be registered as its own template
     * with the gateway provider. Once approved, put the template id in
     * SMS_TEMPLATE_ID (.env). Until then carriers may drop these SMS in
     * production because content won't match any registered template.
     */
    public function sendOtp(string $mobileNumber, string $otp): bool
    {
        // In testing/local environment, just log and return
        if (app()->environment('local', 'testing')) {
            Log::info("[SSO SMS] OTP {$otp} → {$mobileNumber} (test mode, not sent)");
            return true;
        }

        try {
            $username = config('services.sms.username');
            $password = config('services.sms.password');

            if (!$username || !$password) {
                Log::error('[SSO SMS] Gateway credentials missing. Set SMS_GATEWAY_USERNAME / SMS_GATEWAY_PASSWORD.');
                return false;
            }

            $message = "{$otp} is your OTP for Star One login. Regards, Star Cement";

            $params = [
                'username' => $username,
                'password' => $password,
                'to'       => $mobileNumber,
                'from'     => config('services.sms.sender_id'),
                'text'     => $message,
                'dlr-mask' => '19',
                'dlr-url'  => '',
            ];

            if ($templateId = config('services.sms.template_id')) {
                $params['tempid'] = $templateId;
            }

            $response = Http::timeout(20)->get('https://http.myvfirst.com/smpp/sendsms', $params);

            Log::info("[SSO SMS] OTP sent to {$mobileNumber}", [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error("[SSO SMS] Failed to send OTP to {$mobileNumber}: {$e->getMessage()}");
            return false;
        }
    }
}