<?php

declare(strict_types=1);

namespace App\Tests\Service\Trace;

use App\Command\Statistics\SyncPaymentFlowAssetSidesCommand;
use App\Service\Stellar\StellarNetworkResolver;
use App\Service\Trace\PaymentFlowAssetSideSyncService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

final class PaymentFlowAssetSideSyncServiceIntegrationTest extends TestCase
{
    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $port = getenv('STELLARCHAIN_TEST_PG_PORT');
        if ($port === false || !ctype_digit($port)) {
            self::markTestSkipped('Set STELLARCHAIN_TEST_PG_PORT to an isolated local PostgreSQL test instance.');
        }

        // No application kernel, dotenv or production statistics URL is loaded.
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => '127.0.0.1',
            'port' => (int) $port,
            'dbname' => 'postgres',
            'user' => 'postgres',
            'connect_timeout' => 5,
        ]);
        $this->connection->executeStatement(file_get_contents(dirname(__DIR__, 2) . '/Fixtures/payment-flow-asset-side.sql'));
    }

    protected function tearDown(): void
    {
        $this->connection?->close();
    }

    public function testDryRunReadsEveryLedgerButDoesNotCreateDerivedRows(): void
    {
        $result = $this->service()->sync('mainnet', 100, 102);

        self::assertTrue($result['dry_run']);
        self::assertSame(5, $result['source_events']);
        self::assertSame(3, $result['source_rows']);
        self::assertSame(5, $result['destination_rows']);
        self::assertSame(2, $result['excluded_source']);
        self::assertSame(0, $result['excluded_destination']);
        self::assertSame(0, $result['rows_written']);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM payment_flow_asset_side'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM payment_flow_asset_side_build_ledger'));
    }

    public function testApplyIsIdempotentAndKeepsPathPaymentSidesInTheirOwnUnits(): void
    {
        $first = $this->service()->sync('mainnet', 100, 102, true, 102);
        $second = $this->service()->sync('mainnet', 100, 102, true, 102);

        self::assertSame(8, $first['rows_written']);
        self::assertSame(0, $first['rows_replaced']);
        self::assertSame(8, $second['rows_written']);
        self::assertSame(8, $second['rows_replaced']);
        self::assertSame(8, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM payment_flow_asset_side WHERE network = 1'));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM payment_flow_asset_side WHERE event_id = 6 AND network = 1'));
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM payment_flow_asset_side_build_ledger WHERE network = 1'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT source_events FROM payment_flow_asset_side_build_ledger WHERE network = 1 AND ledger = 102'));

        $pathSides = $this->connection->fetchAllAssociative('SELECT side, asset_id, amount_decimal FROM payment_flow_asset_side WHERE network = 1 AND event_id = 2 ORDER BY side');
        self::assertSame(1, (int) $pathSides[0]['asset_id']);
        self::assertSame(3.0, (float) $pathSides[0]['amount_decimal']);
        self::assertSame(2, (int) $pathSides[1]['asset_id']);
        self::assertSame(4.0, (float) $pathSides[1]['amount_decimal']);
    }

    public function testAssetAndNetworkAggregatesKeepEachSideSeparate(): void
    {
        $this->service()->sync('mainnet', 100, 102, true, 102);

        $totals = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT side, asset_id, SUM(amount_decimal)::text AS total
FROM payment_flow_asset_side
WHERE network = 1
GROUP BY side, asset_id
ORDER BY side, asset_id
SQL);

        self::assertCount(3, $totals);
        self::assertSame(['side' => 1, 'asset_id' => 1, 'total' => '5.75000000000000'], $this->normalizedTotal($totals[0]));
        self::assertSame(['side' => 2, 'asset_id' => 1, 'total' => '4.75000000000000'], $this->normalizedTotal($totals[1]));
        self::assertSame(['side' => 2, 'asset_id' => 2, 'total' => '4.00000000000000'], $this->normalizedTotal($totals[2]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM payment_flow_asset_side WHERE network = 2'));

        $this->service()->sync('testnet', 100, 100, true, 100);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM payment_flow_asset_side WHERE network = 2'));
        self::assertSame(8, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM payment_flow_asset_side WHERE network = 1'));
    }

    public function testRebuildReplacesStaleSidesAfterSourceCorrectionOrRemoval(): void
    {
        $this->service()->sync('mainnet', 100, 102, true, 102);
        $this->connection->executeStatement('DELETE FROM payment_flow_event WHERE network = 1 AND id = 1');
        $this->connection->executeStatement('UPDATE payment_flow_event SET source_amount_decimal = 5, destination_amount_decimal = 6 WHERE network = 1 AND id = 2');

        $result = $this->service()->sync('mainnet', 100, 100, true, 102);

        self::assertSame(4, $result['rows_replaced']);
        self::assertSame(2, $result['rows_written']);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM payment_flow_asset_side WHERE network = 1 AND event_id = 1'));
        self::assertSame(5.0, (float) $this->connection->fetchOne('SELECT amount_decimal FROM payment_flow_asset_side WHERE network = 1 AND event_id = 2 AND side = 1'));
        self::assertSame(6.0, (float) $this->connection->fetchOne('SELECT amount_decimal FROM payment_flow_asset_side WHERE network = 1 AND event_id = 2 AND side = 2'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT source_events FROM payment_flow_asset_side_build_ledger WHERE network = 1 AND ledger = 100'));
    }

    public function testFailedInsertRollsBackDeletionAndCoverageUpdate(): void
    {
        $this->service()->sync('mainnet', 100, 100, true, 102);
        $this->connection->executeStatement('ALTER TABLE payment_flow_asset_side ADD CONSTRAINT fixture_amount_cap CHECK (amount_decimal <= 4)');
        $this->connection->executeStatement('UPDATE payment_flow_event SET destination_amount_decimal = 5 WHERE network = 1 AND id = 2');

        $this->expectException(Exception::class);
        try {
            $this->service()->sync('mainnet', 100, 100, true, 102);
        } finally {
            self::assertSame(4, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM payment_flow_asset_side WHERE network = 1 AND ledger = 100'));
            self::assertSame(4.0, (float) $this->connection->fetchOne('SELECT amount_decimal FROM payment_flow_asset_side WHERE network = 1 AND event_id = 2 AND side = 2'));
            self::assertSame(2, (int) $this->connection->fetchOne('SELECT source_events FROM payment_flow_asset_side_build_ledger WHERE network = 1 AND ledger = 100'));
        }
    }

    public function testApplyRequiresCompletedSourceCheckpoint(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('completed-through');

        $this->service()->sync('mainnet', 100, 102, true);
    }

    public function testRangeIsLimitedToSixtyFourLedgers(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->sync('mainnet', 1, 65);
    }

    public function testInvalidNetworkNeverFallsBackToMainnet(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->sync('unknown', 100, 100, true, 102);
    }

    public function testCommandDefaultsToPreviewAndRequiresAnExplicitApplyCheckpoint(): void
    {
        $tester = new CommandTester(new SyncPaymentFlowAssetSidesCommand($this->service()));
        $arguments = ['--network' => 'mainnet', '--start-ledger' => '100', '--end-ledger' => '102'];

        self::assertSame(0, $tester->execute($arguments));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM payment_flow_asset_side'));
        self::assertSame(1, $tester->execute($arguments + ['--apply' => true]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM payment_flow_asset_side'));
        self::assertSame(0, $tester->execute($arguments + ['--apply' => true, '--completed-through-ledger' => '102']));
        self::assertSame(8, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM payment_flow_asset_side'));
    }

    private function service(): PaymentFlowAssetSideSyncService
    {
        return new PaymentFlowAssetSideSyncService($this->connection, new StellarNetworkResolver());
    }

    /** @param array<string,mixed> $row
     * @return array{side:int,asset_id:int,total:string}
     */
    private function normalizedTotal(array $row): array
    {
        return [
            'side' => (int) $row['side'],
            'asset_id' => (int) $row['asset_id'],
            'total' => (string) $row['total'],
        ];
    }
}
