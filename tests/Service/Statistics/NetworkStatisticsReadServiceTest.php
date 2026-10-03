<?php

declare(strict_types=1);

namespace App\Tests\Service\Statistics;

use App\Service\Statistics\NetworkStatisticsReadService;
use App\Service\Statistics\NetworkMetricCatalog;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

final class NetworkStatisticsReadServiceTest extends TestCase
{
    public function testHourlyOverviewIncludesAllChartCardsWithoutMisrepresentingDistinctSourcesOrMixedAssets(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))
            ->method('fetchOne')
            ->with(self::callback([$this, 'isBoundedOverviewLookup']))
            ->willReturnOnConsecutiveCalls('1', false);
        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->with(
                self::logicalAnd(
                    self::stringContains('metric_key = :metric_key'),
                    self::stringContains('ORDER BY bucket_start DESC, id DESC'),
                    self::stringContains('LIMIT 1')
                ),
                self::callback([$this, 'hasTransactionAnchor']),
                self::isType('array')
            )
            ->willReturn([
                'latest_bucket' => '2026-05-15 10:00:00',
                'latest_update' => '2026-05-15 10:05:00',
            ]);
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::logicalAnd(
                    self::stringContains('metric_key IN (:metric_keys)'),
                    self::stringContains('WHEN metric_key IN (:max_metric_keys) THEN MAX(value_decimal)')
                ),
                self::callback([$this, 'hasHourlyMetricKeys']),
                self::isType('array')
            )
            ->willReturn([
                [
                    'metric_key' => 'max-fee',
                    'bucket_start' => '2026-05-15 09:00:00',
                    'bucket_end' => '2026-05-15 10:00:00',
                    'value_decimal' => '100',
                    'updated_at' => '2026-05-15 10:05:00',
                ],
                [
                    'metric_key' => 'contracts',
                    'bucket_start' => '2026-05-15 10:00:00',
                    'bucket_end' => '2026-05-15 11:00:00',
                    'value_decimal' => '2',
                    'updated_at' => '2026-05-15 10:05:00',
                ],
                [
                    'metric_key' => 'invocations',
                    'bucket_start' => '2026-05-15 10:00:00',
                    'bucket_end' => '2026-05-15 11:00:00',
                    'value_decimal' => '3',
                    'updated_at' => '2026-05-15 10:05:00',
                ],
                [
                    'metric_key' => 'max-fee',
                    'bucket_start' => '2026-05-15 10:00:00',
                    'bucket_end' => '2026-05-15 11:00:00',
                    'value_decimal' => '300',
                    'updated_at' => '2026-05-15 10:05:00',
                ],
            ]);

        $service = new NetworkStatisticsReadService($connection, new StellarNetworkResolver());
        $result = $service->read('mainnet', '24h', 60);
        $cards = [];
        foreach ($result['sections'] as $section) {
            foreach ($section['cards'] as $card) {
                $cards[$card['metricKey']] = $card;
            }
        }

        self::assertCount(count(NetworkMetricCatalog::AVAILABLE_METRIC_KEYS), $cards);
        self::assertSame([], array_values(array_diff(NetworkMetricCatalog::AVAILABLE_METRIC_KEYS, array_keys($cards))));
        self::assertNull($cards['active-addresses']['value']);
        self::assertSame([], $cards['active-addresses']['sparkline']);
        self::assertSame('Available only in five-minute buckets.', $cards['active-addresses']['unavailableReason']);
        self::assertNull($cards['output-value']['value']);
        self::assertSame('Mixed asset amounts have no comparable total.', $cards['output-value']['unavailableReason']);
        self::assertSame('Contract creation matches', $cards['contracts']['label']);
        self::assertSame('Contract invocation matches', $cards['invocations']['label']);
        self::assertSame(2.0, $cards['contracts']['value']);
        self::assertSame(300.0, $cards['max-fee']['value']);
        self::assertSame('max', $cards['max-fee']['aggregation']);
    }

    public function testFiveMinuteOverviewShowsLatestSourceCountWithoutSummingDistinctBuckets(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))
            ->method('fetchOne')
            ->with(self::callback([$this, 'isBoundedOverviewLookup']))
            ->willReturnOnConsecutiveCalls('1', false);
        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn([
                'latest_bucket' => '2026-05-15 10:05:00',
                'latest_update' => '2026-05-15 10:10:00',
            ]);
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::stringContains('metric_key IN (:metric_keys)'),
                self::callback([$this, 'hasFiveMinuteMetricKeys']),
                self::isType('array')
            )
            ->willReturn([
                [
                    'metric_key' => 'active-addresses',
                    'bucket_start' => '2026-05-15 10:00:00',
                    'bucket_end' => '2026-05-15 10:05:00',
                    'value_decimal' => '7',
                    'updated_at' => '2026-05-15 10:10:00',
                ],
                [
                    'metric_key' => 'active-addresses',
                    'bucket_start' => '2026-05-15 10:05:00',
                    'bucket_end' => '2026-05-15 10:10:00',
                    'value_decimal' => '9',
                    'updated_at' => '2026-05-15 10:10:00',
                ],
            ]);

        $service = new NetworkStatisticsReadService($connection, new StellarNetworkResolver());
        $result = $service->read('mainnet', '24h', 5);
        $cards = [];
        foreach ($result['sections'] as $section) {
            foreach ($section['cards'] as $card) {
                $cards[$card['metricKey']] = $card;
            }
        }

        self::assertSame(9.0, $cards['active-addresses']['value']);
        self::assertSame('latest', $cards['active-addresses']['aggregation']);
        self::assertSame([7.0, 9.0], $cards['active-addresses']['sparkline']);
    }

    public function hasHourlyMetricKeys(array $params): bool
    {
        return !in_array('active-addresses', $params['metric_keys'], true)
            && !in_array('output-value', $params['metric_keys'], true)
            && in_array('ops', $params['metric_keys'], true)
            && in_array('fee-charged', $params['metric_keys'], true)
            && in_array('max-fee', $params['max_metric_keys'], true);
    }

    public function hasFiveMinuteMetricKeys(array $params): bool
    {
        return in_array('active-addresses', $params['metric_keys'], true)
            && !in_array('output-value', $params['metric_keys'], true);
    }

    public function hasTransactionAnchor(array $params): bool
    {
        return ($params['metric_key'] ?? null) === 'transactions'
            && ($params['bucket_minutes'] ?? null) === 5;
    }

    public function isBoundedOverviewLookup(string $sql): bool
    {
        return str_contains($sql, 'information_schema.tables')
            || (str_contains($sql, 'metric_key = :metric_key')
                && str_contains($sql, 'bucket_start < :range_start')
                && str_contains($sql, 'ORDER BY bucket_start DESC'));
    }
}
