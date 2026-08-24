<?php

namespace App\Services;

use App\Models\Otp;
use Carbon\Carbon;
use Exception;

class OtpService
{
    /**
     * Generate an OTP using the same algorithm as the legacy apps.
     *
     * Dynamic codes are 4 digits, ported from StarLink's generateNumericOTP(4)
     * (charset "1357902468"). StarStellar uses rand(1,9).rand(0,9).rand(0,9).rand(1,9),
     * which differs only in edge-digit distribution. Local/testing keeps the
     * fixed 123456 code so automated API testing stays predictable.
     */
    public function generateOtp(string $mobileNumber, ?int $expiryMinutes = null): string
    {
        // Invalidate older unused OTPs for this number
        Otp::where('mobile_number', $mobileNumber)
            ->where('is_used', false)
            ->update(['is_used' => true]);

        $code = app()->environment('local', 'testing')
            ? '123456'
            : $this->generateNumericOtp();

        // Legacy parity: no expiry by default - valid until used or replaced.
        Otp::create([
            'mobile_number' => $mobileNumber,
            'otp_code' => $code,
            'expires_at' => $expiryMinutes ? Carbon::now()->addMinutes($expiryMinutes) : null,
            'attempts' => 0,
            'is_used' => false,
            'purpose' => 'login',
        ]);

        return $code;
    }

    private function generateNumericOtp(int $length = 4): string
    {
        $generator = '1357902468';

        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $result .= substr($generator, rand() % strlen($generator), 1);
        }

        return $result;
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

        if ($otpRecord->expires_at !== null && Carbon::now()->gt($otpRecord->expires_at)) {
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