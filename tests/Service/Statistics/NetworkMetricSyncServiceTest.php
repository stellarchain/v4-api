<?php

declare(strict_types=1);

namespace App\Tests\Service\Statistics;

use App\Service\Statistics\HistoricalBucketWindowResolver;
use App\Service\Statistics\NetworkMetricCatalog;
use App\Service\Statistics\NetworkMetricSyncService;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

final class NetworkMetricSyncServiceTest extends TestCase
{
    /** @var array<string,string> */
    private array $writtenPoints = [];

    private bool $missingFee = false;

    public function testItPreservesTheLegacySumAndWritesASeparateTransactionMaximum(): void
    {
        $connection = $this->metricConnection();
        $connection->expects(self::once())->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::exactly(4))
            ->method('executeStatement')
            ->willReturnCallback([$this, 'recordPoint']);
        $connection->expects(self::once())->method('commit');

        $result = $this->service($connection)->sync('mainnet', 5, 100, 104);

        self::assertSame(4, $result['metrics_written']);
        self::assertSame(4, $result['rows_written']);
        self::assertSame('300', $this->writtenPoints['max-fee']);
        self::assertSame('200', $this->writtenPoints['max-transaction-fee']);
        self::assertSame('300', $this->writtenPoints['fee-charged']);
    }

    public function testItRefusesAnIncompleteMaximumBeforeWritingAnyPoint(): void
    {
        $this->missingFee = true;
        $connection = $this->metricConnection();
        $connection->expects(self::never())->method('beginTransaction');
        $connection->expects(self::never())->method('executeStatement');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing max_fee values');
        $this->service($connection)->sync('mainnet', 5, 100, 104);
    }

    private function metricConnection(): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'start_ledger' => 100,
            'end_ledger' => 104,
            'min_closed_at' => '2020-01-01 12:00:05',
            'max_closed_at' => '2020-01-01 12:04:55',
            'ledger_count' => 5,
        ]);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls(99, 105, 7, 0);
        $connection->method('fetchAllAssociative')->willReturnCallback([$this, 'rowsForSql']);

        return $connection;
    }

    private function service(Connection $connection): NetworkMetricSyncService
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getConnection')->with('horizon_mainnet')->willReturn($connection);

        return new NetworkMetricSyncService(
            $connection,
            $registry,
            new StellarNetworkResolver(),
            new NetworkMetricCatalog(),
            new HistoricalBucketWindowResolver()
        );
    }

    /** @return list<array<string,mixed>> */
    public function rowsForSql(string $sql, array $params = []): array
    {
        if (str_contains($sql, 'information_schema.columns')) {
            return match ($params['table'] ?? null) {
                'history_ledgers' => $this->columnRows(['sequence', 'closed_at', 'transaction_count', 'failed_transaction_count', 'operation_count']),
                'history_transactions' => $this->columnRows(['ledger_sequence', 'account', 'fee_charged', 'max_fee']),
                default => [],
            };
        }
        if (str_contains($sql, 'FROM history_transactions ht')) {
            self::assertStringContainsString('MAX(ht.max_fee) AS max_transaction_fee', $sql);
            self::assertStringContainsString('SUM(ht.max_fee)', $sql);

            return [[
                'bucket_start' => '2020-01-01 12:00:00',
                'fee_charged' => '300',
                'max_fee' => '300',
                'max_transaction_fee' => '200',
                'transaction_count' => '2',
                'max_fee_count' => $this->missingFee ? '1' : '2',
                'active_addresses' => '1',
            ]];
        }

        return [];
    }

    /** @param list<string> $columns
     *  @return list<array{column_name:string}>
     */
    private function columnRows(array $columns): array
    {
        $rows = [];
        foreach ($columns as $column) {
            $rows[] = ['column_name' => $column];
        }

        return $rows;
    }

    /** @param array<string,mixed> $params
     *  @param array<string,mixed> $types
     */
    public function recordPoint(string $sql, array $params, array $types): int
    {
        self::assertStringContainsString('ON CONFLICT', $sql);
        $this->writtenPoints[(string) $params['metric_key']] = (string) $params['value_decimal'];

        return 1;
    }
}
