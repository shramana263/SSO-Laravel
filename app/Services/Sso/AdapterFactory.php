<?php
namespace App\Services\Sso;

use App\Services\Sso\Adapters\ProductAdapterInterface;
use App\Services\Sso\Adapters\StarSaathiAdapter;
use App\Services\Sso\Adapters\StarSfaAdapter;
use App\Services\Sso\Adapters\StarLinkAdapter;
use App\Services\Sso\Adapters\StarStellerAdapter;
use InvalidArgumentException;

class AdapterFactory
{
    public static function make(string $productKey): ProductAdapterInterface
    {
        return match ($productKey) {
            'star_saathi' => new StarSaathiAdapter(),
            'star_sfa' => new StarSfaAdapter(),
            'star_link' => new StarLinkAdapter(),
            'star_steller' => new StarStellerAdapter(),
            default => throw new InvalidArgumentException("Unsupported product adapter: {$productKey}")
        };
    }
}