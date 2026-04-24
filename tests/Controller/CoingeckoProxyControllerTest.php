<?php

namespace App\Tests\Controller;

use App\Controller\CoingeckoProxyController;
use App\Service\CoingeckoSnapshotCache;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class CoingeckoProxyControllerTest extends TestCase
{
    public function testStellarEndpointReturnsCachedSnapshot(): void
    {
        $payload = [
            'coingecko_stellar' => ['foo' => 'stellar'],
            'coingecko_global' => ['bar' => 'global'],
            'stellar_dashboard' => ['baz' => 'lumens'],
            'stellar_expert' => ['qux' => 'expert'],
        ];

        $snapshotCache = $this->createMock(CoingeckoSnapshotCache::class);
        $snapshotCache->expects(self::once())
            ->method('getCachedPayload')
            ->willReturn($payload);

        $controller = new CoingeckoProxyController($snapshotCache);
        $response = $controller->stellar();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame($payload, json_decode((string) $response->getContent(), true));
        self::assertSame('public, max-age=60', $response->headers->get('Cache-Control'));
    }

    public function testStellarEndpointReturnsServiceUnavailableWhenCacheIsEmpty(): void
    {
        $snapshotCache = $this->createMock(CoingeckoSnapshotCache::class);
        $snapshotCache->expects(self::once())
            ->method('getCachedPayload')
            ->willReturn(null);

        $controller = new CoingeckoProxyController($snapshotCache);
        $response = $controller->stellar();

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        self::assertSame(
            [
                'error' => 'cache_unavailable',
                'message' => 'Coingecko cache is empty. Run app:warm-coingecko-cache first.',
            ],
            json_decode((string) $response->getContent(), true)
        );
    }
}
