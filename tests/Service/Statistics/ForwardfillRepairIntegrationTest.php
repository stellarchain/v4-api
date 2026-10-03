<?php

declare(strict_types=1);

namespace App\Tests\Service\Statistics;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

final class ForwardfillRepairIntegrationTest extends TestCase
{
    private const CONTAINER = 'stellarchain-forwardfill-regression-20260928';
    private ?Connection $connection = null;

    protected function setUp(): void
    {
        if (getenv('STELLARCHAIN_REPAIR_TEST_CONTAINER') !== self::CONTAINER) {
            self::markTestSkipped('Requires the named disposable PostgreSQL test container. Never loads dotenv.');
        }
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => '127.0.0.1', 'port' => 55439,
            'dbname' => 'postgres', 'user' => 'postgres', 'connect_timeout' => 5,
        ]);
        $this->connection->executeStatement(file_get_contents(dirname(__DIR__, 2) . '/Fixtures/forwardfill-repair.sql'));
    }

    protected function tearDown(): void
    {
        $this->connection?->close();
    }

    public function testPreviewIsReadOnlyAndApplyChangesOnlyProvenAccountEndpoints(): void
    {
        self::assertSame(0, $this->sql('account-timestamps')['exit']);
        self::assertSame('2026-09-01 00:00:00', $this->connection->fetchOne('SELECT first_activity_at FROM account_activity_summary WHERE id=1'));
        self::assertNull($this->connection->fetchOne("SELECT to_regclass('public.statistics_repair_log')"));
        $result = $this->sql('account-timestamps', ['apply' => 'true', 'batch_id' => 'accounts-1']);
        self::assertSame(0, $result['exit'], $result['output']);
        self::assertSame('2020-01-01 12:00:05', $this->connection->fetchOne('SELECT first_activity_at FROM account_activity_summary WHERE id=1'));
        self::assertSame('2026-09-01 00:00:00', $this->connection->fetchOne('SELECT last_activity_at FROM account_activity_summary WHERE id=2'));
        self::assertSame('2026-09-01 00:00:00', $this->connection->fetchOne('SELECT first_activity_at FROM account_activity_summary WHERE id=3'));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM statistics_repair_log'));
        $result = $this->sql('rollback', ['apply' => 'true', 'batch_id' => 'accounts-1']);
        self::assertSame(0, $result['exit'], $result['output']);
        self::assertSame('2026-09-01 00:00:00', $this->connection->fetchOne('SELECT first_activity_at FROM account_activity_summary WHERE id=1'));
    }

    public function testConflictingSourceDatesAreNotUsed(): void
    {
        $this->connection->executeStatement("INSERT INTO payment_flow_transaction (id,network,ledger,closed_at) VALUES (99,1,100,'2020-01-01 12:00:06')");
        $result = $this->sql('account-timestamps', ['apply' => 'true', 'batch_id' => 'conflict']);
        self::assertSame(0, $result['exit'], $result['output']);
        self::assertSame('2026-09-01 00:00:00', $this->connection->fetchOne('SELECT first_activity_at FROM account_activity_summary WHERE id=1'));
        self::assertSame('2020-01-01 12:04:55', $this->connection->fetchOne('SELECT last_activity_at FROM account_activity_summary WHERE id=1'));
    }

    public function testLedgerRepairRequiresCompleteBucketAndDoesNotChangeOtherMetrics(): void
    {
        $result = $this->sql('ledger-metrics', ['ledger_from' => '99', 'ledger_to' => '105', 'apply' => 'true', 'batch_id' => 'ledger-1']);
        self::assertSame(0, $result['exit'], $result['output']);
        self::assertSame(5, (int) $this->connection->fetchOne('SELECT value_decimal FROM network_metric_point WHERE id=1'));
        self::assertSame(60.0, (float) $this->connection->fetchOne('SELECT value_decimal FROM network_metric_point WHERE id=2'));
        self::assertSame(999, (int) $this->connection->fetchOne('SELECT value_decimal FROM network_metric_point WHERE id=3'));
        self::assertSame(0, $this->sql('rollback', ['apply' => 'true', 'batch_id' => 'ledger-1'])['exit']);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT value_decimal FROM network_metric_point WHERE id=1'));
    }

    public function testMissingLedgerWitnessAndForwardBucketCannotBeRepaired(): void
    {
        $this->connection->executeStatement('DELETE FROM payment_flow_transaction WHERE ledger=102');
        $result = $this->sql('ledger-metrics', ['ledger_from' => '99', 'ledger_to' => '105', 'apply' => 'true', 'batch_id' => 'gap']);
        self::assertSame(0, $result['exit'], $result['output']);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM statistics_repair_log'));
        $this->connection->executeStatement("INSERT INTO payment_flow_transaction (id,network,ledger,closed_at) VALUES (4,1,102,'2020-01-01 12:02:05')");
        $result = $this->sql('ledger-metrics', ['ledger_from' => '99', 'ledger_to' => '105', 'repair_before' => '2020-01-01 12:00:00', 'apply' => 'true', 'batch_id' => 'cutoff']);
        self::assertSame(0, $result['exit'], $result['output']);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM statistics_repair_log'));
    }

    public function testRollbackFailsAtomicallyAfterSubsequentModification(): void
    {
        self::assertSame(0, $this->sql('account-timestamps', ['apply' => 'true', 'batch_id' => 'modified'])['exit']);
        $this->connection->executeStatement("UPDATE account_activity_summary SET first_activity_at='2021-01-01' WHERE id=1");
        self::assertSame(3, $this->sql('rollback', ['apply' => 'true', 'batch_id' => 'modified'])['exit']);
        self::assertSame('2021-01-01 00:00:00', $this->connection->fetchOne('SELECT first_activity_at FROM account_activity_summary WHERE id=1'));
        self::assertSame('2020-01-01 12:00:05', $this->connection->fetchOne('SELECT first_activity_at FROM account_activity_summary WHERE id=2'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM statistics_repair_log WHERE rolled_back_at IS NOT NULL'));
    }

    public function testAccountBatchPaginationDoesNotSkipUnchangedRows(): void
    {
        self::assertSame(0, $this->sql('account-timestamps', ['apply' => 'true', 'batch_id' => 'page-1', 'batch_size' => '1'])['exit']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM statistics_repair_log'));
        self::assertSame(0, $this->sql('account-timestamps', ['apply' => 'true', 'batch_id' => 'page-2', 'batch_size' => '1', 'after_account' => 'GA'])['exit']);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM statistics_repair_log'));
        self::assertSame(0, $this->sql('account-timestamps', ['apply' => 'true', 'batch_id' => 'retry'])['exit']);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM statistics_repair_log'));
    }

    public function testWrongDatabaseAndForwardRangeFailBeforeWrites(): void
    {
        self::assertSame(3, $this->sql('account-timestamps', ['expected_database' => 'wrong', 'apply' => 'true', 'batch_id' => 'wrong'])['exit']);
        self::assertSame(3, $this->sql('account-timestamps', ['historical_end' => '103', 'apply' => 'true', 'batch_id' => 'forward'])['exit']);
        self::assertNull($this->connection->fetchOne("SELECT to_regclass('public.statistics_repair_log')"));
    }

    private function sql(string $name, array $overrides = []): array
    {
        $variables = array_replace(['expected_database' => 'postgres', 'historical_end' => '200',
            'network_id' => '1', 'ledger_from' => '100', 'ledger_to' => '104',
            'repair_before' => '2020-01-01 12:05:00'], $overrides);
        $command = ['docker', 'exec', self::CONTAINER, 'psql', '--no-psqlrc', '-U', 'postgres', '-d', 'postgres'];
        foreach ($variables as $key => $value) {
            $command[] = '-v';
            $command[] = $key . '=' . $value;
        }
        $command[] = '-f';
        $command[] = '/tmp/repairs/' . $name . '.sql';
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit' => proc_close($process), 'output' => $output];
    }
}
