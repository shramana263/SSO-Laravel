<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    /**
     * Send OTP via Star Cement's MyVFirst SMS Gateway
     * Same gateway used across all 4 legacy products (StarSFA, StarLink, StarSaathi, StarStellar)
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
            $templateId = config('services.sms.template_id');

            $response = Http::timeout(20)->get('https://http.myvfirst.com/smpp/sendsms', [
                'username' => $username,
                'password' => $password,
                'to'       => $mobileNumber,
                'from'     => config('services.sms.sender_id', 'STARCM'),
                'text'     => $message,
                'tempid'   => $templateId,
                'dlr-mask' => '19',
                'dlr-url'  => '',
            ]);

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