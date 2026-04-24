<?php

namespace App\Service\Stellar\Soroban;

use App\Service\Stellar\StellarNetworkResolver;
use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\Network;
use Soneso\StellarSDK\Soroban\Contract\ClientOptions;
use Soneso\StellarSDK\Soroban\Contract\SorobanClient;

final class ContractReadMethodsProbe
{
    public function __construct(
        private readonly StellarNetworkResolver $stellarNetworkResolver,
        private readonly SorobanScValMapper $scValMapper,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function probe(string $contractId, ?string $network): array
    {
        $readMethods = ['name', 'symbol', 'decimals'];

        try {
            $options = new ClientOptions(
                sourceAccountKeyPair: KeyPair::random(),
                contractId: $contractId,
                network: $this->resolveSdkNetwork($network),
                rpcUrl: $this->stellarNetworkResolver->resolveSorobanRpcUrl($network),
            );
            $client = SorobanClient::forClientOptions($options);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
            ];
        }

        $results = [];
        foreach ($readMethods as $method) {
            try {
                $retval = $client->invokeMethod(name: $method, args: []);
                $results[$method] = [
                    'ok' => true,
                    'retvalXdr' => $retval->toBase64Xdr(),
                    'retval' => $this->scValMapper->scValToNative($retval),
                ];
            } catch (\Throwable $e) {
                $results[$method] = [
                    'ok' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'ok' => true,
            'methods' => $results,
        ];
    }

    private function resolveSdkNetwork(?string $network): Network
    {
        return match ($this->stellarNetworkResolver->normalizeNetwork($network)) {
            'testnet' => Network::testnet(),
            'futurenet' => Network::futurenet(),
            default => Network::public(),
        };
    }
}
