<?php

declare(strict_types=1);

namespace App\Service\Stellar\Soroban;

use App\Service\Stellar\StellarNetworkResolver;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Soneso\StellarSDK\Crypto\StrKey;
use Soneso\StellarSDK\Xdr\XdrContractExecutableType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ContractRpcFallbackResolver implements ContractRpcFallbackResolverInterface
{
    private const FOUND_CACHE_TTL_SECONDS = 300;
    private const NOT_FOUND_CACHE_TTL_SECONDS = 30;

    public function __construct(
        private readonly StellarNetworkResolver $stellarNetworkResolver,
        private readonly SorobanServerFactory $sorobanServerFactory,
        private readonly SorobanContractInspector $sorobanContractInspector,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function resolve(string $contractId, ?string $network = null): ?array
    {
        $normalizedContractId = $this->sorobanContractInspector->normalizeContractId($contractId);
        if ($normalizedContractId === null) {
            return null;
        }

        $normalizedNetwork = $this->stellarNetworkResolver->normalizeNetwork($network, 'mainnet');
        $cacheItem = $this->cache->getItem($this->cacheKey($normalizedNetwork, $normalizedContractId));
        if ($cacheItem->isHit()) {
            $cached = $cacheItem->get();

            return is_array($cached['contract'] ?? null) ? $cached['contract'] : null;
        }

        try {
            $server = $this->sorobanServerFactory->create($normalizedNetwork);
            $meta = $this->sorobanContractInspector->loadContractExecutableMetaForContractId(
                $server,
                $normalizedContractId,
            );
            $executableType = $meta['executableType'] ?? null;
            if (!is_int($executableType)) {
                $this->saveCacheItem($cacheItem, null, self::NOT_FOUND_CACHE_TTL_SECONDS);

                return null;
            }

            $wasmId = is_string($meta['wasmId'] ?? null)
                && preg_match('/^[0-9a-fA-F]{64}$/', $meta['wasmId']) === 1
                    ? strtolower($meta['wasmId'])
                    : null;
            $contract = [
                'contractId' => $normalizedContractId,
                'contractIdHex' => StrKey::decodeContractIdHex($normalizedContractId),
                'wasmId' => $wasmId,
                'executableType' => $executableType,
                'isSac' => $executableType === XdrContractExecutableType::CONTRACT_EXECUTABLE_STELLAR_ASSET,
            ];
            $this->saveCacheItem($cacheItem, $contract, self::FOUND_CACHE_TTL_SECONDS);

            return $contract;
        } catch (\Throwable $exception) {
            $this->logger->warning('Unable to resolve missing contract from Soroban RPC.', [
                'contractId' => $normalizedContractId,
                'network' => $normalizedNetwork,
                'exception' => $exception,
            ]);
            $this->saveCacheItem($cacheItem, null, self::NOT_FOUND_CACHE_TTL_SECONDS);

            return null;
        }
    }

    private function cacheKey(string $network, string $contractId): string
    {
        return 'contract.rpc_fallback.'.hash('sha256', $network.':'.$contractId);
    }

    /**
     * @param array<string,mixed>|null $contract
     */
    private function saveCacheItem(CacheItemInterface $cacheItem, ?array $contract, int $ttl): void
    {
        $cacheItem->set(['contract' => $contract]);
        $cacheItem->expiresAfter($ttl);
        $this->cache->save($cacheItem);
    }
}
