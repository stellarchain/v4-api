<?php

declare(strict_types=1);

namespace App\Service\Orders;

use App\Service\Stellar\StellarNetworkResolver;

final class OrderMonitorAccountResolver
{
    public function __construct(
        private readonly StellarNetworkResolver $networkResolver,
        private readonly string $mainnetMonitorAccount,
        private readonly string $testnetMonitorAccount,
        private readonly string $futurenetMonitorAccount,
    ) {
    }

    public function resolveByNetwork(?string $network): string
    {
        return match ($this->networkResolver->normalizeNetwork($network)) {
            'mainnet' => trim($this->mainnetMonitorAccount),
            'testnet' => trim($this->testnetMonitorAccount),
            'futurenet' => trim($this->futurenetMonitorAccount),
            default => '',
        };
    }

    public function resolveByNetworkCode(int $networkCode): string
    {
        return match ($networkCode) {
            1 => trim($this->mainnetMonitorAccount),
            2 => trim($this->testnetMonitorAccount),
            3 => trim($this->futurenetMonitorAccount),
            default => '',
        };
    }
}

