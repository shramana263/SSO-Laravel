<?php

namespace App\Services\Sso\Adapters;

use App\Models\User;
use Illuminate\Http\Response;

class StarSaathiAdapter implements ProductAdapterInterface
{
    // Legacy encryption constants from StarSaathi Apicommonfunction
    private const ENCRYPTION_KEY = '0123456789abcdef'; // 16 bytes = 128 bits
    private const ENCRYPTION_IV = 'fedcba9876543210';   // 16 bytes = 128 bits
    private const ENCRYPTION_CIPHER = 'AES-128-CBC';

    public function formatResponse(User $user, array $attributes): Response
    {
        $custCode = $attributes['customer_code'] ?? ($attributes['emp_code'] ?? ($user->emp_code ?? ''));
        $dnsCustCode = $attributes['dns_emp_code'] ?? ($attributes['dns_customer_code'] ?? $custCode);

        $payload = [
            'process_status' => 'YES',
            'process_message' => 'OTP IS SUCCESSFULLY VERIFIED.',
            'user_type' => $attributes['user_type'] ?? 'dealer',
            'emp_code' => $custCode,
            'customer_code' => $custCode,
            'dns_emp_code' => $dnsCustCode,
            'emp_name' => $attributes['emp_name'] ?? $user->name,
            'sale_access' => $attributes['sale_access'] ?? 'PRIMARY',
            'newpassword' => $attributes['newpassword'] ?? '1234',
            'deviceid' => $attributes['deviceid'] ?? '',
            'phonenumber' => $attributes['phonenumber'] ?? $user->mobile_number,
            'acedns' => $attributes['acedns'] ?? 'Y',
            'broker_id' => $attributes['broker_id'] ?? '',
            'dns_broker_id' => $attributes['dns_broker_id'] ?? '',
            'contact_person' => $attributes['contact_person'] ?? '',
            'mail_id' => $attributes['mail_id'] ?? ($user->email ?? ''),
            'brokerage_cost' => $attributes['brokerage_cost'] ?? '',
            'state_code' => $attributes['state_code'] ?? '',
            'the_profile_image_url' => $attributes['the_profile_image_url'] ?? '',
            'belong_dealer_code' => $attributes['belong_dealer_code'] ?? '',
            'belong_dealer_dns_code' => $attributes['belong_dealer_dns_code'] ?? '',
            'belong_dealer_name' => $attributes['belong_dealer_name'] ?? '',
            'is_survey_form_submitted' => $attributes['is_survey_form_submitted'] ?? 'NO',
            'token' => $attributes['token'] ?? hash('sha256', ($user->emp_code ?? $user->id) . '|' . date('YmdHis') . '|' . bin2hex(random_bytes(16))),
        ];

        // Legacy returns raw encrypted hex string as HTTP body
        $encryptedData = $this->encrypt(json_encode($payload));

        return response($encryptedData, 200)->header('Content-Type', 'text/plain; charset=utf-8');
    }

    /**
     * Encrypt data using StarSaathi's legacy encryption (AES-128-CBC)
     * Compatible with Apicommonfunction::encrypt() which uses mcrypt (zero-padding)
     *
     * IMPORTANT: mcrypt_generic uses zero-byte (NULL) padding, NOT PKCS7.
     * OpenSSL uses PKCS7 by default, which would produce different ciphertext
     * that the legacy Java/Kotlin mobile app CANNOT decrypt.
     * We must manually zero-pad and use OPENSSL_ZERO_PADDING to match.
     */
    public function encrypt(string $data): string
    {
        $key = self::ENCRYPTION_KEY;
        $iv = self::ENCRYPTION_IV;
        $blockSize = 16; // AES block size = 128 bits = 16 bytes

        // Manual zero-padding to match mcrypt behavior
        $pad = $blockSize - (strlen($data) % $blockSize);
        if ($pad > 0 && $pad < $blockSize) {
            $data .= str_repeat("\0", $pad);
        }

        // OPENSSL_ZERO_PADDING tells OpenSSL NOT to add its own PKCS7 padding
        // (we already handled padding manually above)
        $encrypted = openssl_encrypt(
            $data,
            self::ENCRYPTION_CIPHER,
            $key,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            $iv
        );

        if ($encrypted === false) {
            throw new \RuntimeException('Encryption failed: ' . openssl_error_string());
        }

        return bin2hex($encrypted);
    }

    /**
     * Decrypt data using StarSaathi's legacy encryption (AES-128-CBC)
     * Compatible with Apicommonfunction::decrypt() which uses mcrypt (zero-padding)
     */
    public function decrypt(string $hexData): string
    {
        $binaryData = $this->hex2bin($hexData);

        $key = self::ENCRYPTION_KEY;
        $iv = self::ENCRYPTION_IV;

        // Use OPENSSL_ZERO_PADDING to match mcrypt's zero-padding behavior
        $decrypted = openssl_decrypt(
            $binaryData,
            self::ENCRYPTION_CIPHER,
            $key,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            $iv
        );

        if ($decrypted === false) {
            throw new \RuntimeException('Decryption failed: ' . openssl_error_string());
        }

        // Remove trailing NULL bytes (zero-padding) and whitespace, same as legacy trim()
        return rtrim($decrypted, "\0");
    }

    /**
     * Convert hex string to binary (equivalent to legacy hex2bin function)
     */
    private function hex2bin(string $hexData): string
    {
        // Use PHP's built-in hex2bin if available (PHP 5.4+), fallback to manual implementation
        if (function_exists('hex2bin')) {
            return hex2bin($hexData);
        }

        // Fallback for older PHP versions (same as legacy implementation)
        $bindata = '';
        for ($i = 0; $i < strlen($hexData); $i += 2) {
            $bindata .= chr(hexdec(substr($hexData, $i, 2)));
        }
        return $bindata;
    }
}