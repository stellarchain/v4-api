<?php

declare(strict_types=1);

namespace App\Tests\Service\Statistics;

use App\Service\Statistics\NetworkMetricCatalog;
use App\Service\Statistics\NetworkMetricSeriesReadService;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

final class NetworkMetricSeriesReadServiceTest extends TestCase
{
    public function testItReadsAndMapsAggregatedStatisticsRows(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with(
                self::stringContains('date_bin'),
                self::callback(static fn (array $params): bool => $params['target_interval'] === '60 minutes'
                    && $params['metric_key'] === 'transactions'
                    && $params['source_bucket_minutes'] === 5),
                self::isType('array')
            )
            ->willReturn('3');
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::logicalAnd(
                    self::stringContains('AVG(value_decimal)'),
                    self::stringContains('MAX(value_decimal)'),
                    self::stringContains('LIMIT :limit OFFSET :offset')
                ),
                self::callback(static fn (array $params): bool => $params['limit'] === 2
                    && $params['offset'] === 2),
                self::isType('array')
            )
            ->willReturn([[
                'network' => 1,
                'metric_group' => 'blockchain',
                'metric_key' => 'transactions',
                'source' => 'horizon_db',
                'bucket_start' => '2026-05-15 19:00:00',
                'bucket_end' => '2026-05-15 20:00:00',
                'value_decimal' => '198617',
                'created_at' => '2026-05-15 19:00:00',
                'updated_at' => '2026-07-23 07:47:32',
            ]]);

        $service = new NetworkMetricSeriesReadService(
            $connection,
            new StellarNetworkResolver(),
            new NetworkMetricCatalog()
        );
        $result = $service->read('mainnet', 'transactions', 60, 2, 2);

        self::assertSame(3, $result['totalItems']);
        self::assertSame('transactions', $result['items'][0]['metricKey']);
        self::assertSame(60, $result['items'][0]['bucketMinutes']);
        self::assertSame('198617', $result['items'][0]['valueDecimal']);
        self::assertSame('2026-05-15T19:00:00+00:00', $result['items'][0]['bucketStart']);
    }
}
