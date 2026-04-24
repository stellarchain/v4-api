<?php

namespace App\Service\Stellar\Soroban;

use App\Service\Stellar\StellarNetworkResolver;
use Soneso\StellarSDK\Soroban\SorobanServer;

final class SorobanServerFactory
{
    public function __construct(
        private readonly StellarNetworkResolver $stellarNetworkResolver,
    ) {
    }

    public function create(?string $network = null): SorobanServer
    {
        return new SorobanServer($this->stellarNetworkResolver->resolveSorobanRpcUrl($network));
    }
}
