<?php

namespace App\Command\Support;

use App\Service\Stellar\StellarNetworkResolver;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

trait NetworkOptionTrait
{
    protected function addNetworkOption(string $description = 'mainnet|testnet|futurenet', string $default = 'testnet'): self
    {
        $this->addOption('network', null, InputOption::VALUE_REQUIRED, $description, $default);

        return $this;
    }

    protected function resolveNetworkOption(InputInterface $input, StellarNetworkResolver $stellarNetworkResolver): string
    {
        return $stellarNetworkResolver->normalizeNetwork((string) $input->getOption('network'));
    }

    protected function resolveNetworkCodeOption(
        InputInterface $input,
        StellarNetworkResolver $stellarNetworkResolver,
        int $fallback = 1
    ): int {
        $network = $this->resolveNetworkOption($input, $stellarNetworkResolver);

        return $stellarNetworkResolver->resolveNetworkCode($network) ?? $fallback;
    }
}
