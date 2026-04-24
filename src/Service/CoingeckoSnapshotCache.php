<?php

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class CoingeckoSnapshotCache
{
    public const CACHE_KEY = 'coingecko.coins.stellar';
    private const CACHE_TTL_SECONDS = 300;

    private const STELLAR_URL = 'https://api.coingecko.com/api/v3/coins/stellar';
    private const GLOBAL_URL = 'https://api.coingecko.com/api/v3/global';
    private const STELLAR_DASHBOARD_URL = 'https://dashboard.stellar.org/api/v2/lumens';

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
        private readonly HttpClientInterface $httpClient,
        private readonly AssetMetricsHistoryRecorder $historyRecorder,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function refresh(): array
    {
        $payload = $this->fetchLivePayload(false);
        $this->savePayloadToCache($payload);
        $this->historyRecorder->recordXlmNativeSnapshot($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getCachedPayload(): ?array
    {
        $item = $this->cache->getItem(self::CACHE_KEY);
        if (!$item->isHit()) {
            return null;
        }

        $value = $item->get();
        return is_array($value) ? $value : null;
    }

    /**
     * @return array<string,mixed>
     */
    public function fetchLivePayload(bool $recordHistory = true): array
    {
        $payload = [
            'coingecko_stellar' => $this->request(self::STELLAR_URL),
            'coingecko_global' => $this->request(self::GLOBAL_URL),
            'stellar_dashboard' => $this->request(self::STELLAR_DASHBOARD_URL),
        ];
        if ($recordHistory) {
            $this->historyRecorder->recordXlmNativeSnapshot($payload);
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function savePayloadToCache(array $payload): void
    {
        $item = $this->cache->getItem(self::CACHE_KEY);
        $item->set($payload);
        $item->expiresAfter(self::CACHE_TTL_SECONDS);
        $this->cache->save($item);
    }

    private function request(string $url): array
    {
        $response = $this->httpClient->request('GET', $url, [
            'max_duration' => 8.0,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'Stellarchain/1.0',
            ],
        ]);

        return $response->toArray(false);
    }
}
