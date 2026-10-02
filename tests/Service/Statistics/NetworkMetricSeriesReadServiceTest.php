<?php

declare(strict_types=1);

namespace App\Tests\Service\Statistics;

use App\Exception\StatisticsUnavailableException;
use App\Service\Statistics\NetworkMetricCatalog;
use App\Service\Statistics\NetworkMetricSeriesReadService;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DatabaseRequired;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

final class NetworkMetricSeriesReadServiceTest extends TestCase
{
    public function testItReadsAndMapsAggregatedStatisticsRows(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(3))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls('2026-05-20 12:03:00', false, '3');
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::logicalAnd(
                    self::stringContains('AVG(value_decimal)'),
                    self::stringContains('MAX(value_decimal)'),
                    self::stringContains('LIMIT :limit OFFSET :offset')
                ),
                self::callback([$this, 'hasExpectedMappingQueryParams']),
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
        $result = $service->read('mainnet', 'transactions', 60, 2, 2, 30);

        self::assertSame(3, $result['totalItems']);
        self::assertSame('transactions', $result['items'][0]['metricKey']);
        self::assertSame(60, $result['items'][0]['bucketMinutes']);
        self::assertSame('198617', $result['items'][0]['valueDecimal']);
        self::assertSame('2026-05-15T19:00:00+00:00', $result['items'][0]['bucketStart']);
    }

    public function hasExpectedMappingQueryParams(array $params): bool
    {
        return $params['target_interval'] === '60 minutes'
            && $params['metric_key'] === 'transactions'
            && $params['source_bucket_minutes'] === 5
            && $params['window_start'] === '2026-04-20 13:00:00'
            && $params['window_end'] === '2026-05-20 13:00:00'
            && $params['limit'] === 2
            && $params['offset'] === 2;
    }

    public function testItBoundsGroupingAndCountToAnIndexedUtcWindow(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(3))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls('2026-05-20 12:03:00', '1', '2');
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::logicalAnd(
                    self::stringContains('bucket_start >= :window_start'),
                    self::stringContains('bucket_start < :window_end')
                ),
                self::callback([$this, 'hasExpectedUtcWindowBounds']),
                self::isType('array')
            )
            ->willReturn([]);

        $service = new NetworkMetricSeriesReadService(
            $connection,
            new StellarNetworkResolver(),
            new NetworkMetricCatalog()
        );
        $result = $service->read('mainnet', 'transactions', 60, 1, 100, 30);

        self::assertSame(2, $result['totalItems']);
        self::assertSame('2026-04-20T13:00:00+00:00', $result['window']['start']);
        self::assertSame('2026-05-20T13:00:00+00:00', $result['window']['end']);
        self::assertSame('2026-04-20T13:00:00Z', $result['window']['olderBefore']);
        self::assertTrue($result['window']['isLatest']);
    }

    public function testItRejectsOversizedWindowsBeforeDatabaseAccess(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchOne');
        $connection->expects(self::never())->method('fetchAllAssociative');
        $service = new NetworkMetricSeriesReadService(
            $connection, new StellarNetworkResolver(), new NetworkMetricCatalog()
        );

        $this->expectException(\InvalidArgumentException::class);
        $service->read('mainnet', 'transactions', 60, 1, 100, 31);
    }

    public function hasExpectedUtcWindowBounds(array $params): bool
    {
        return $params['window_start'] === '2026-04-20 13:00:00'
            && $params['window_end'] === '2026-05-20 13:00:00'
            && $params['metric_key'] === 'transactions';
    }

    public function testItReturnsEmptyWindowWithoutGroupingWhenMetricHasNoPoints(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchOne')->willReturn(false);
        $connection->expects(self::never())->method('fetchAllAssociative');

        $service = new NetworkMetricSeriesReadService(
            $connection,
            new StellarNetworkResolver(),
            new NetworkMetricCatalog()
        );
        $result = $service->read('mainnet', 'transactions', 1440, 1, 100, 30);

        self::assertSame(0, $result['totalItems']);
        self::assertNull($result['window']);
        self::assertSame([], $result['items']);
    }

    public function testItBoundsOwnedReadsWithAReadOnlySnapshotAndStatementTimeout(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))
            ->method('isTransactionActive')
            ->willReturnOnConsecutiveCalls(false, true);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::exactly(2))
            ->method('executeStatement')
            ->withConsecutive(
                ['SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY'],
                ["SET LOCAL statement_timeout = '8s'"]
            );
        $connection->expects(self::once())->method('fetchOne')->willReturn(false);
        $connection->expects(self::once())->method('rollBack');

        $service = new NetworkMetricSeriesReadService(
            $connection, new StellarNetworkResolver(), new NetworkMetricCatalog()
        );

        self::assertSame([], $service->read('mainnet', 'transactions', 60, 1, 100, 30)['items']);
    }

    public function testItDoesNotChangeOrEndAnExistingTransaction(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::never())->method('beginTransaction');
        $connection->expects(self::never())->method('executeStatement');
        $connection->expects(self::never())->method('rollBack');
        $connection->expects(self::once())->method('fetchOne')->willReturn(false);

        $service = new NetworkMetricSeriesReadService(
            $connection, new StellarNetworkResolver(), new NetworkMetricCatalog()
        );

        self::assertNull($service->read('mainnet', 'transactions', 60, 1, 100, 30)['window']);
    }

    public function testItRollsBackItsSnapshotWhenTheDatabaseReadFails(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))
            ->method('isTransactionActive')
            ->willReturnOnConsecutiveCalls(false, true);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())
            ->method('fetchOne')
            ->willThrowException(DatabaseRequired::new('fetchOne'));
        $connection->expects(self::once())->method('rollBack');

        $service = new NetworkMetricSeriesReadService(
            $connection, new StellarNetworkResolver(), new NetworkMetricCatalog()
        );

        $this->expectException(StatisticsUnavailableException::class);
        $service->read('mainnet', 'transactions', 60, 1, 100, 30);
    }

    public function testItAdvancesFromAnOlderWindowWithoutExceedingLatestBucket(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(3))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls('2026-05-20 12:00:00', false, '0');
        $connection->expects(self::never())->method('fetchAllAssociative');

        $service = new NetworkMetricSeriesReadService(
            $connection,
            new StellarNetworkResolver(),
            new NetworkMetricCatalog()
        );
        $result = $service->read('mainnet', 'transactions', 60, 1, 100, 7, '2026-05-20T12:00:00Z');

        self::assertSame('2026-05-13T12:00:00+00:00', $result['window']['start']);
        self::assertSame('2026-05-20T12:00:00+00:00', $result['window']['end']);
        self::assertNull($result['window']['olderBefore']);
        self::assertSame('2026-05-20T13:00:00Z', $result['window']['newerBefore']);
        self::assertFalse($result['window']['isLatest']);
    }

    public function testItSkipsTheOffsetQueryForAnOutOfRangePage(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(3))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls('2026-05-20 12:03:00', false, '3');
        $connection->expects(self::never())->method('fetchAllAssociative');

        $service = new NetworkMetricSeriesReadService(
            $connection, new StellarNetworkResolver(), new NetworkMetricCatalog()
        );
        $result = $service->read('mainnet', 'transactions', 60, PHP_INT_MAX, 100, 30);

        self::assertSame(3, $result['totalItems']);
        self::assertSame([], $result['items']);
    }
}
