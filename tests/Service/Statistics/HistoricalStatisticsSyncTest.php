<?php

declare(strict_types=1);

namespace App\Tests\Service\Statistics;

use App\Service\Statistics\AccountActivitySummarySyncService;
use App\Service\Statistics\AssetMarketHistorySyncService;
use App\Service\Statistics\NetworkMetricCatalog;
use App\Service\Statistics\NetworkMetricSyncService;
use App\Service\Statistics\HistoricalBucketWindowResolver;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

final class HistoricalStatisticsSyncTest extends TestCase
{
    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $port = getenv('STELLARCHAIN_TEST_PG_PORT');
        if ($port === false || !ctype_digit($port)) {
            self::markTestSkipped('Set STELLARCHAIN_TEST_PG_PORT to an isolated local PostgreSQL test instance.');
        }

        // No application kernel, dotenv, or production database URL is loaded.
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => '127.0.0.1', 'port' => (int) $port,
            'dbname' => 'postgres', 'user' => 'postgres', 'connect_timeout' => 5,
        ]);
        $this->connection->executeStatement("SET statement_timeout = '5s'");
        $this->connection->executeStatement(file_get_contents(dirname(__DIR__, 2) . '/Fixtures/statistics-history.sql'));
    }

    protected function tearDown(): void
    {
        // All fixture tables are TEMPORARY and disappear with this session.
        $this->connection?->close();
    }

    public function testAdjacentChunksAndRetriesRecomputeTheSameCompleteBucket(): void
    {
        $service = $this->networkService();
        $service->sync('mainnet', 5, 100, 102);
        $service->sync('mainnet', 5, 103, 104);
        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM network_metric_point');
        $service->sync('mainnet', 5, 100, 102);

        self::assertSame($count, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM network_metric_point'));
        self::assertSame(5, (int) $this->metric('ledgers'));
        self::assertSame(2, (int) $this->metric('transactions'));
        self::assertSame(300, (int) $this->metric('fee-charged'));
        self::assertSame(300, (int) $this->metric('max-fee'));
        self::assertSame(200, (int) $this->metric('max-transaction-fee'));
        self::assertSame(1, (int) $this->metric('active-addresses'));
        self::assertSame(5, (int) $this->metric('trades'));
        self::assertSame(4.0, (float) $this->metric('dex-vol-xlm'));
        self::assertEqualsWithDelta(60.0, (float) $this->metric('avg-ledger-sec'), 0.000000001);
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM network_metric_point WHERE bucket_start <> '2020-01-01 12:00:00'"));
    }

    public function testMissingRightContextFailsBeforeAnyWrite(): void
    {
        $this->connection->executeStatement('DELETE FROM history_ledgers WHERE sequence >= 105');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bucket');
        try {
            $this->networkService()->sync('mainnet', 5, 100, 104);
        } finally {
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM network_metric_point'));
        }
    }

    public function testMissingLeftContextFailsBeforeAnyWrite(): void
    {
        $this->connection->executeStatement('DELETE FROM history_ledgers WHERE sequence = 99');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bucket');
        $this->networkService()->sync('mainnet', 5, 100, 104);
    }

    public function testMissingInteriorLedgerFailsBeforeAnyWrite(): void
    {
        $this->connection->executeStatement('DELETE FROM history_ledgers WHERE sequence = 102');
        $this->expectException(\RuntimeException::class);
        $this->networkService()->sync('mainnet', 5, 100, 104);
    }

    public function testAccountActivityUsesLedgerCloseTimesOnEveryRerun(): void
    {
        $service = new AccountActivitySummarySyncService($this->connection, $this->registry(), new StellarNetworkResolver());
        $service->sync('mainnet', 100, 104);
        $this->connection->executeStatement("UPDATE history_transactions SET created_at = '2027-01-01' ");
        $service->sync('mainnet', 100, 104);
        $row = $this->connection->fetchAssociative("SELECT * FROM account_activity_summary WHERE account_address = 'GSYNTHETICSOURCE'");

        self::assertSame('2020-01-01 12:00:05', $row['first_activity_at']);
        self::assertSame('2020-01-01 12:03:05', $row['last_activity_at']);
        self::assertSame(2, (int) $row['total_transactions']);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM account_activity_summary'));
    }

    public function testAccountActivityRejectsTransactionsWithoutLedgerTimestamps(): void
    {
        $this->connection->executeStatement('DELETE FROM history_ledgers WHERE sequence = 100');
        $service = new AccountActivitySummarySyncService($this->connection, $this->registry(), new StellarNetworkResolver());
        $this->expectException(\RuntimeException::class);
        $service->sync('mainnet', 100, 104);
    }

    public function testMarketBucketsIncludeRoundedMinutesAndExcludeTheNextBucket(): void
    {
        $service = $this->marketService();
        $service->sync('mainnet', 100, 102, 5);
        $service->sync('mainnet', 103, 104, 5);
        $service->sync('mainnet', 100, 102, 5);

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM asset_market_metric_point'));
        $row = $this->connection->fetchAssociative('SELECT * FROM asset_market_metric_point');
        self::assertSame('2020-01-01 12:00:00', $row['bucket_start']);
        self::assertSame(5, (int) $row['trades_count']);
        self::assertSame(4.0, (float) $row['volume_xlm']);
        self::assertSame(14.0, (float) $row['volume_asset']);
        self::assertSame(0.5, (float) $row['open_price_xlm']);
        self::assertSame(0.25, (float) $row['close_price_xlm']);
    }

    public function testHistoricalMarketSyncNeverWritesCurrentAssetStateAsHistorical(): void
    {
        $result = $this->marketService()->sync('mainnet', 100, 104, 5);
        self::assertSame(0, $result['asset_state_snapshots']);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM asset_state_snapshot'));
    }

    public function testDryRunDoesNotWriteAnyStatistics(): void
    {
        $result = $this->networkService()->sync('mainnet', 5, 100, 104, true);
        self::assertTrue($result['dry_run']);
        self::assertGreaterThan(0, $result['metrics_written']);
        self::assertSame(0, $result['rows_written']);
        $this->marketService()->sync('mainnet', 100, 104, 5, true);
        (new AccountActivitySummarySyncService($this->connection, $this->registry(), new StellarNetworkResolver()))
            ->sync('mainnet', 100, 104, true);
        foreach (['network_metric_point', 'asset_market_metric_point', 'asset_state_snapshot', 'account_activity_summary'] as $table) {
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . $table));
        }
    }

    public function testMissingTransactionFeeLimitRejectsTheBucketBeforeAnyWrite(): void
    {
        $this->connection->executeStatement('UPDATE history_transactions SET max_fee = NULL WHERE id = 2');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing max_fee values');
        try {
            $this->networkService()->sync('mainnet', 5, 100, 104);
        } finally {
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM network_metric_point'));
        }
    }

    public function testTransactionMaximumMatchesRawTransactionsInEachCompleteBucket(): void
    {
        $this->networkService()->sync('mainnet', 5, 100, 109);

        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT to_char(date_bin(INTERVAL '5 minutes', hl.closed_at, TIMESTAMP '2000-01-01 00:00:00'), 'YYYY-MM-DD HH24:MI:SS') AS bucket_start,
       MAX(ht.max_fee)::text AS raw_max_fee,
       point.value_decimal::text AS indexed_max_fee,
       COUNT(ht.id) AS transaction_count
FROM history_transactions ht
JOIN history_ledgers hl ON hl.sequence = ht.ledger_sequence
JOIN network_metric_point point
  ON point.network = 1
 AND point.metric_key = 'max-transaction-fee'
 AND point.bucket_start = date_bin(INTERVAL '5 minutes', hl.closed_at, TIMESTAMP '2000-01-01 00:00:00')
GROUP BY date_bin(INTERVAL '5 minutes', hl.closed_at, TIMESTAMP '2000-01-01 00:00:00'), point.value_decimal
ORDER BY bucket_start
SQL);

        self::assertCount(2, $rows);
        self::assertSame('2020-01-01 12:00:00', $rows[0]['bucket_start']);
        self::assertSame('200', $rows[0]['raw_max_fee']);
        self::assertSame((float) $rows[0]['raw_max_fee'], (float) $rows[0]['indexed_max_fee']);
        self::assertSame(2, (int) $rows[0]['transaction_count']);
        self::assertSame('2020-01-01 12:05:00', $rows[1]['bucket_start']);
        self::assertSame('900', $rows[1]['raw_max_fee']);
        self::assertSame((float) $rows[1]['raw_max_fee'], (float) $rows[1]['indexed_max_fee']);
        self::assertSame(1, (int) $rows[1]['transaction_count']);
    }

    public function testUnboundedSyncExcludesUnprovenEdgeBuckets(): void
    {
        $this->networkService()->sync('mainnet', 5);
        $rows = $this->connection->fetchAllAssociative("SELECT bucket_start, value_decimal FROM network_metric_point WHERE metric_key = 'ledgers' ORDER BY bucket_start");
        self::assertCount(2, $rows);
        self::assertSame('2020-01-01 12:00:00', $rows[0]['bucket_start']);
        self::assertSame('2020-01-01 12:05:00', $rows[1]['bucket_start']);
        self::assertSame(5, (int) $rows[0]['value_decimal']);
        self::assertSame(5, (int) $rows[1]['value_decimal']);
    }

    public function testGenesisDoesNotRequireAMissingLedgerOne(): void
    {
        $this->connection->executeStatement('DELETE FROM history_ledgers');
        $this->connection->executeStatement("INSERT INTO history_ledgers (sequence, closed_at) VALUES (2, '2015-09-30 16:48:00'), (3, '2015-09-30 17:15:54'), (4, '2015-09-30 17:20:01')");
        $result = $this->networkService()->sync('mainnet', 5, 1, 3);
        self::assertSame(2, $result['start_ledger']);
        self::assertSame(3, $result['end_ledger']);
        self::assertSame(2, (int) $this->connection->fetchOne("SELECT SUM(value_decimal) FROM network_metric_point WHERE metric_key = 'ledgers'"));
    }

    public function testBucketAndTradeTimestampsRemainUtcInANonUtcDatabaseSession(): void
    {
        $this->connection->executeStatement("SET TIME ZONE 'Europe/Bucharest'");
        $this->networkService()->sync('mainnet', 5, 100, 104);
        $this->marketService()->sync('mainnet', 100, 104, 5);
        self::assertSame('2020-01-01 12:00:00', $this->connection->fetchOne('SELECT MIN(bucket_start) FROM network_metric_point'));
        self::assertSame('2020-01-01 12:00:00', $this->connection->fetchOne('SELECT first_trade_at FROM asset_market_metric_point'));
    }

    public function testMissingRequestedEndIsNotSilentlyClamped(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->networkService()->sync('mainnet', 5, 100, 111);
    }

    public function testEmptyExplicitRangeFailsRatherThanReportingSuccess(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->networkService()->sync('mainnet', 5, 200, 210);
    }

    public function testGapInContextOutsideTheRequestedRangeAlsoFails(): void
    {
        $this->connection->executeStatement('DELETE FROM history_ledgers WHERE sequence = 101');
        $this->expectException(\RuntimeException::class);
        $this->marketService()->sync('mainnet', 103, 104, 5);
    }

    public function testAnOpenBucketIsNotPublishedByUnboundedSync(): void
    {
        $this->connection->executeStatement('DELETE FROM history_ledgers WHERE sequence < 100 OR sequence > 104');
        $result = $this->networkService()->sync('mainnet', 5);
        self::assertSame(0, $result['rows_written']);
    }

    public function testIncompleteRerunPreservesPreviouslyCorrectStatistics(): void
    {
        $service = $this->networkService();
        $service->sync('mainnet', 5, 100, 104);
        $this->connection->executeStatement('DELETE FROM history_ledgers WHERE sequence >= 105');
        $this->expectException(\RuntimeException::class);
        try {
            $service->sync('mainnet', 5, 100, 102);
        } finally {
            self::assertSame(5, (int) $this->metric('ledgers'));
        }
    }

    public function testRangeSpanningTwoBucketsIncludesBothBucketsInFull(): void
    {
        $result = $this->networkService()->sync('mainnet', 5, 103, 106);
        self::assertSame(100, $result['start_ledger']);
        self::assertSame(109, $result['end_ledger']);
        self::assertSame(10, (int) $this->connection->fetchOne("SELECT SUM(value_decimal) FROM network_metric_point WHERE metric_key = 'ledgers'"));
        self::assertSame(900, (int) $this->connection->fetchOne("SELECT value_decimal FROM network_metric_point WHERE metric_key = 'max-transaction-fee' AND bucket_start = '2020-01-01 12:05:00'"));
    }

    public function testLegacyLedgerSeqColumnRemainsSupported(): void
    {
        $this->connection->executeStatement('ALTER TABLE history_transactions RENAME COLUMN ledger_sequence TO ledger_seq');
        $this->networkService()->sync('mainnet', 5, 100, 104);
        (new AccountActivitySummarySyncService($this->connection, $this->registry(), new StellarNetworkResolver()))
            ->sync('mainnet', 100, 104);
        self::assertSame(2, (int) $this->metric('transactions'));
        self::assertSame('2020-01-01 12:00:05', $this->connection->fetchOne('SELECT MIN(first_activity_at) FROM account_activity_summary'));
    }

    private function networkService(): NetworkMetricSyncService
    {
        return new NetworkMetricSyncService($this->connection, $this->registry(), new StellarNetworkResolver(), new NetworkMetricCatalog(), new HistoricalBucketWindowResolver());
    }

    private function marketService(): AssetMarketHistorySyncService
    {
        return new AssetMarketHistorySyncService($this->connection, $this->registry(), new StellarNetworkResolver(), new HistoricalBucketWindowResolver());
    }

    private function registry(): ManagerRegistry
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getConnection')->with('horizon_mainnet')->willReturn($this->connection);

        return $registry;
    }

    private function metric(string $key): string
    {
        return (string) $this->connection->fetchOne('SELECT value_decimal FROM network_metric_point WHERE metric_key = ?', [$key]);
    }
}
