<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\MarketController;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class MarketControllerTest extends TestCase
{
    public function testIndexReturnsMarketPayloadWithNextCursor(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->willReturn([
                $this->row(1, 1, 'USDC'),
                $this->row(2, 2, 'EURC'),
                $this->row(3, 3, 'AQUA'),
            ]);

        $controller = new MarketController($connection, new StellarNetworkResolver());
        $request = new Request(['network' => 'testnet', 'limit' => '2']);
        $response = $controller->index($request);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame(2, $payload['meta']['count']);
        self::assertSame('2:2', $payload['meta']['next_cursor']);
        self::assertCount(2, $payload['market']);
    }

    public function testIndexReturnsNotModifiedWhenEtagMatches(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))
            ->method('fetchAllAssociative')
            ->willReturn([
                $this->row(1, 1, 'USDC'),
            ]);

        $controller = new MarketController($connection, new StellarNetworkResolver());

        $first = $controller->index(new Request(['network' => 'mainnet', 'limit' => '1']));
        $etag = $first->headers->get('ETag');
        self::assertNotNull($etag);

        $secondRequest = new Request(['network' => 'mainnet', 'limit' => '1']);
        $secondRequest->headers->set('If-None-Match', $etag);
        $second = $controller->index($secondRequest);

        self::assertSame(Response::HTTP_NOT_MODIFIED, $second->getStatusCode());
    }

    /**
     * @return array<string,mixed>
     */
    private function row(int $snapshotId, int $rank, string $code): array
    {
        return [
            'snapshot_id' => $snapshotId,
            'rank_position' => $rank,
            'score' => '1.00000000',
            'price_xlm' => '0.10000000',
            'price_change1h' => '1.1000',
            'price_change24h' => '2.1000',
            'price_change7d' => '3.1000',
            'volume_xlm24h' => '1000.0000000',
            'trades24h' => 100,
            'trustlines_total' => 50,
            'supply' => '1000000',
            'updated_at' => '2026-02-19 22:00:00',
            'asset_key' => $code . '-TEST',
            'code' => $code,
            'issuer' => 'GAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAWHF',
        ];
    }
}
