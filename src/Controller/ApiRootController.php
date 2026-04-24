<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Status\ExternalServicesStatusProbe;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ApiRootController
{
    private const STATUS_CACHE_TTL_SECONDS = 30;

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
        private readonly ExternalServicesStatusProbe $externalServicesStatusProbe,
        #[Autowire('%kernel.environment%')]
        private readonly string $appEnv,
    ) {
    }

    #[Route('/', name: 'api_root', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $networkInput = $request->query->get('network');
        $network = is_string($networkInput) ? $networkInput : null;
        $preferBackupMainnetRpc = $this->appEnv === 'prod';
        $cacheKey = sprintf(
            'api_root.status.%s.%s',
            md5((string) $network),
            $preferBackupMainnetRpc ? 'backup' : 'primary'
        );

        $cacheItem = $this->cache->getItem($cacheKey);
        $cachedValue = $cacheItem->isHit() ? $cacheItem->get() : null;
        if (is_array($cachedValue)) {
            $status = $cachedValue;
        } else {
            $status = $this->externalServicesStatusProbe->probe($network, $preferBackupMainnetRpc);
            $cacheItem->set($status);
            $cacheItem->expiresAfter(self::STATUS_CACHE_TTL_SECONDS);
            $this->cache->save($cacheItem);
        }

        $isHealthy = ($status['overall'] ?? null) === 'ok';

        return new JsonResponse([
            'service' => 'stellarchain-api',
            'status' => $isHealthy ? 'ok' : 'degraded',
            'rpcStatus' => (($status['services']['sorobanRpc']['status'] ?? null) === 'up') ? 'up' : 'down',
            'docs' => '/v1/docs',
            'health' => $status,
            'cacheTtlSeconds' => self::STATUS_CACHE_TTL_SECONDS,
        ], $isHealthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
    }
}
