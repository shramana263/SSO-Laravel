<?php

namespace App\Services;

use App\Models\Otp;
use Carbon\Carbon;
use Exception;

class OtpService
{
    public function generateOtp(string $mobileNumber, int $expiryMinutes = 5): string
    {
        // Invalidate older unused OTPs for this number
        Otp::where('mobile_number', $mobileNumber)
            ->where('is_used', false)
            ->update(['is_used' => true]);

        // Static OTP for testing environments, dynamic 6-digit for production
        $code = app()->environment('local', 'testing') ? '123456' : (string) random_int(100000, 999999);

        Otp::create([
            'mobile_number' => $mobileNumber,
            'otp_code' => $code,
            'expires_at' => Carbon::now()->addMinutes($expiryMinutes),
            'attempts' => 0,
            'is_used' => false,
            'purpose' => 'login',
        ]);

        return $code;
    }

    public function validateOtp(string $mobileNumber, string $otpCode): array
    {
        $otpRecord = Otp::where('mobile_number', $mobileNumber)
            ->where('is_used', false)
            ->latest()
            ->first();

        if (!$otpRecord) {
            return ['status' => false, 'code' => 'INVALID_OTP', 'message' => 'Invalid OTP code.'];
        }

        if ($otpRecord->attempts >= 3) {
            return ['status' => false, 'code' => 'MAX_ATTEMPTS_EXCEEDED', 'message' => 'Maximum OTP attempts exceeded. Please request a new OTP.'];
        }

        if (Carbon::now()->gt($otpRecord->expires_at)) {
            return ['status' => false, 'code' => 'OTP_EXPIRED', 'message' => 'OTP has expired. Please request a new one.'];
        }

        if ($otpRecord->otp_code !== $otpCode) {
            $otpRecord->increment('attempts');
            return [
                'status' => false, 
                'code' => 'INVALID_OTP', 
                'message' => 'Invalid OTP code.',
                'attempts_remaining' => 3 - $otpRecord->attempts
            ];
        }

        // Mark OTP as used
        $otpRecord->update(['is_used' => true]);

        return ['status' => true];
    }
}