<?php

declare(strict_types=1);

namespace App\Tests\Command;

use PHPUnit\Framework\TestCase;

final class HorizonRangeStatsScriptTest extends TestCase
{
    private string $directory;
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->directory = sys_get_temp_dir() . '/statistics-script-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        copy($this->root . '/tests/Fixtures/horizon-ingest-stub.sh', $this->directory . '/horizon-stub');
        chmod($this->directory . '/horizon-stub', 0700);
    }

    protected function tearDown(): void
    {
        foreach (['horizon-stub', 'calls.log'] as $file) {
            if (is_file($this->directory . '/' . $file)) {
                unlink($this->directory . '/' . $file);
            }
        }
        rmdir($this->directory);
    }

    public function testItPadsIngestionButPreservesRequestedSummaryAndPaymentRanges(): void
    {
        $result = $this->runScript();
        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('ingest db reingest range 1990 2030', $result['calls']);
        self::assertStringContainsString('app:horizon:sync-network-metrics --network=mainnet --bucket-minutes=5 --start-ledger=2000 --end-ledger=2020', $result['calls']);
        self::assertStringContainsString('app:horizon:sync-payment-flow-events --network=mainnet --start-ledger=2000 --end-ledger=2020', $result['calls']);
        self::assertStringContainsString('app:horizon:sync-account-activity-summary --network=mainnet --start-ledger=2000 --end-ledger=2020', $result['calls']);
        self::assertStringEndsWith("cleanup\n", $result['calls']);
    }

    public function testItClampsContextAtGenesis(): void
    {
        $result = $this->runScript(['START_LEDGER' => '1', 'END_LEDGER' => '20']);
        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('ingest db reingest range 1 30', $result['calls']);
    }

    public function testItDoesNotPadWhenNoTimeSeriesIsRequested(): void
    {
        $result = $this->runScript(['RUN_NETWORK_METRICS' => '0', 'RUN_ASSET_MARKET_HISTORY' => '0']);
        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('ingest db reingest range 2000 2020', $result['calls']);
    }

    public function testFailedIngestionDoesNotExtractOrCleanUp(): void
    {
        $result = $this->runScript(['STATISTICS_TEST_INGEST_EXIT' => '1']);
        self::assertNotSame(0, $result['exit']);
        self::assertStringNotContainsString('console ', $result['calls']);
        self::assertStringNotContainsString('cleanup', $result['calls']);
    }

    public function testIncompleteBucketFailurePreventsCleanup(): void
    {
        $result = $this->runScript(['STATISTICS_TEST_FAIL_COMMAND' => 'app:horizon:sync-network-metrics']);
        self::assertNotSame(0, $result['exit']);
        self::assertStringNotContainsString('app:horizon:sync-payment-flow-events', $result['calls']);
        self::assertStringNotContainsString('cleanup', $result['calls']);
    }

    public function testMarketFailureAlsoPreventsCleanup(): void
    {
        $result = $this->runScript(['STATISTICS_TEST_FAIL_COMMAND' => 'app:horizon:sync-asset-market-history']);
        self::assertNotSame(0, $result['exit']);
        self::assertStringNotContainsString('cleanup', $result['calls']);
    }

    /** @return array{exit:int,output:string,calls:string} */
    private function runScript(array $overrides = []): array
    {
        $environment = array_replace([
            'PATH' => dirname(PHP_BINARY) . ':/usr/bin:/bin',
            'NETWORK' => 'mainnet', 'START_LEDGER' => '2000', 'END_LEDGER' => '2020',
            'HORIZON_CONTEXT_LEDGERS' => '10', 'HORIZON_WORKERS' => '1',
            'HORIZON_BIN' => $this->directory . '/horizon-stub',
            'APP_MODE' => 'host', 'CONSOLE_BIN' => $this->root . '/tests/Fixtures/statistics-console-stub.php',
            'NETWORK_METRICS_BUCKET_MINUTES' => '5', 'RUN_NETWORK_METRICS' => '1',
            'RUN_PAYMENT_FLOW_EVENTS' => '1', 'RUN_ASSET_MARKET_HISTORY' => '1', 'RUN_ACCOUNT_ACTIVITY_SUMMARY' => '1',
            'HORIZON_RETENTION_MODE' => 'none',
            'POST_RANGE_CLEANUP_COMMAND' => 'printf "cleanup\\n" >> "$STATISTICS_TEST_LOG"',
            'STATISTICS_TEST_LOG' => $this->directory . '/calls.log',
        ], $overrides);
        $process = proc_open(['bash', $this->root . '/bin/horizon-range-stats.sh'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root, $environment);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return ['exit' => $exit, 'output' => $output, 'calls' => file_get_contents($this->directory . '/calls.log')];
    }
}
