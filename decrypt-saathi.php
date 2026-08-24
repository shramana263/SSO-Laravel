<?php

/**
 * Star Saathi Response Decryptor
 *
 * Usage: php decrypt-saathi.php <hex_encrypted_string>
 * Or run interactively: php decrypt-saathi.php
 *
 * This script uses the same encryption constants as the StarSaathiAdapter
 * to decrypt the legacy AES-128-CBC encrypted hex responses.
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Services\Sso\Adapters\StarSaathiAdapter;

$adapter = new StarSaathiAdapter();

if ($argc > 1) {
    // Decrypt from command line argument
    $hexData = $argv[1];
    decryptAndDisplay($adapter, $hexData);
} else {
    // Interactive mode
    echo "=== Star Saathi Response Decryptor ===\n";
    echo "Paste the encrypted hex string (or 'quit' to exit):\n\n";

    while (true) {
        echo "> ";
        $input = trim(fgets(STDIN));

        if (strtolower($input) === 'quit' || strtolower($input) === 'exit') {
            break;
        }

        if (empty($input)) {
            continue;
        }

        decryptAndDisplay($adapter, $input);
        echo "\n";
    }
}

function decryptAndDisplay(StarSaathiAdapter $adapter, string $hexData): void
{
    try {
        $decryptedJson = $adapter->decrypt($hexData);
        $payload = json_decode($decryptedJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "❌ Decrypted but invalid JSON:\n";
            echo $decryptedJson . "\n";
            return;
        }

        echo "✅ Decrypted Successfully:\n";
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

    } catch (\Exception $e) {
        echo "❌ Decryption Failed: " . $e->getMessage() . "\n";
    }
}