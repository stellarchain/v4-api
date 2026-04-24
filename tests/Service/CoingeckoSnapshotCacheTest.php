<?php

namespace App\Tests\Service;

use App\Service\AssetMetricsHistoryRecorder;
use App\Service\CoingeckoSnapshotCache;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CoingeckoSnapshotCacheTest extends TestCase
{
    public function testRefreshFetchesAllSourcesAndCachesSnapshot(): void
    {
        $fixtures = [
            'https://api.coingecko.com/api/v3/coins/stellar' => ['foo' => 'stellar'],
            'https://api.coingecko.com/api/v3/global' => ['bar' => 'global'],
            'https://dashboard.stellar.org/api/v2/lumens' => ['baz' => 'lumens'],
            'https://api.stellar.expert/explorer/public/asset?search=&sort=rating&order=desc&limit=50' => ['qux' => 'expert'],
        ];

        $calls = [];
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$calls, $fixtures): MockResponse {
            $calls[] = $url;
            return new MockResponse(json_encode($fixtures[$url], JSON_THROW_ON_ERROR), [
                'response_headers' => ['content-type' => 'application/json'],
            ]);
        });

        $cachePool = new ArrayAdapter();
        $historyRecorder = $this->createMock(AssetMetricsHistoryRecorder::class);
        $historyRecorder->expects(self::once())
            ->method('recordXlmNativeSnapshot')
            ->with([
                'coingecko_stellar' => $fixtures['https://api.coingecko.com/api/v3/coins/stellar'],
                'coingecko_global' => $fixtures['https://api.coingecko.com/api/v3/global'],
                'stellar_dashboard' => $fixtures['https://dashboard.stellar.org/api/v2/lumens'],
                'stellar_expert' => $fixtures['https://api.stellar.expert/explorer/public/asset?search=&sort=rating&order=desc&limit=50'],
            ])
            ->willReturn(3);

        $service = new CoingeckoSnapshotCache($cachePool, $httpClient, $historyRecorder);

        $payload = $service->refresh();
        self::assertCount(4, $calls);
        self::assertSame([
            'coingecko_stellar' => $fixtures['https://api.coingecko.com/api/v3/coins/stellar'],
            'coingecko_global' => $fixtures['https://api.coingecko.com/api/v3/global'],
            'stellar_dashboard' => $fixtures['https://dashboard.stellar.org/api/v2/lumens'],
            'stellar_expert' => $fixtures['https://api.stellar.expert/explorer/public/asset?search=&sort=rating&order=desc&limit=50'],
        ], $payload);

        $cached = $service->getCachedPayload();
        self::assertSame($payload, $cached);
    }
}
