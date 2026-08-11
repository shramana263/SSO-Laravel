<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SmsService
{
    public function sendOtp(string $mobileNumber, string $otp): bool
    {
        // Replace with actual SMS gateway integration logic
        Log::info("Sending OTP {$otp} to mobile number {$mobileNumber}");
        return true;
    }
}