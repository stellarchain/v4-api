<?php

namespace App\Service\Stellar;

use Soneso\StellarSDK\StellarSDK;

final class StellarNetworkResolver
{
    /**
     * @return 'mainnet'|'testnet'|'futurenet'
     */
    public function normalizeNetwork(?string $network, string $default = 'mainnet'): string
    {
        $value = is_string($network) ? strtolower(trim($network)) : '';
        $fallback = strtolower(trim($default));
        if (!in_array($fallback, ['mainnet', 'testnet', 'futurenet'], true)) {
            $fallback = 'mainnet';
        }

        return match ($value) {
            'mainnet', 'public' => 'mainnet',
            'testnet', 'test' => 'testnet',
            'futurenet', 'future' => 'futurenet',
            default => $fallback,
        };
    }

    public function resolveHorizonUrl(?string $network): string
    {
        return match ($this->normalizeNetwork($network)) {
            'mainnet' => $this->readNonEmptyEnv('STELLAR_HORIZON_MAINNET_URL') ?? StellarSDK::$PUBLIC_NET_HORIZON_URL,
            'testnet' => $this->readNonEmptyEnv('STELLAR_HORIZON_TESTNET_URL') ?? StellarSDK::$TEST_NET_HORIZON_URL,
            'futurenet' => $this->readNonEmptyEnv('STELLAR_HORIZON_FUTURENET_URL') ?? StellarSDK::$FUTURE_NET_HORIZON_URL,
        };
    }

    public function resolveSorobanRpcUrl(?string $network): string
    {
        $normalizedNetwork = $this->normalizeNetwork($network);
        $perNetwork = match ($normalizedNetwork) {
            'mainnet' => $this->readNonEmptyEnv('SOROBAN_RPC_MAINNET_URL'),
            'testnet' => $this->readNonEmptyEnv('SOROBAN_RPC_TESTNET_URL'),
            'futurenet' => $this->readNonEmptyEnv('SOROBAN_RPC_FUTURENET_URL'),
        };
        if ($perNetwork !== null) {
            return $perNetwork;
        }

        $generic = $this->readNonEmptyEnv('SOROBAN_RPC_URL');
        if ($generic !== null) {
            return $generic;
        }

        throw new \RuntimeException(sprintf(
            'Missing Soroban RPC env for network "%s". Set one of: %s, SOROBAN_RPC_URL',
            $normalizedNetwork,
            match ($normalizedNetwork) {
                'mainnet' => 'SOROBAN_RPC_MAINNET_URL',
                'testnet' => 'SOROBAN_RPC_TESTNET_URL',
                'futurenet' => 'SOROBAN_RPC_FUTURENET_URL',
            }
        ));
    }

    public function resolveNetworkCode(?string $network): ?int
    {
        return match ($this->normalizeNetwork($network)) {
            'mainnet' => 1,
            'testnet' => 2,
            'futurenet' => 3,
            default => null,
        };
    }

    private function readNonEmptyEnv(string $name): ?string
    {
        $candidates = [
            getenv($name),
            $_ENV[$name] ?? null,
            $_SERVER[$name] ?? null,
        ];

        foreach ($candidates as $value) {
            if (!is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }
}
