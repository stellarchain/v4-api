<?php

declare(strict_types=1);

namespace App\Tests\Command;

use PHPUnit\Framework\TestCase;

final class HorizonForwardfillRunnerTest extends TestCase
{
    private string $directory;
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->directory = sys_get_temp_dir() . '/forwardfill-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        copy($this->root . '/tests/Fixtures/horizon-ingest-stub.sh', $this->directory . '/horizon-stub');
        chmod($this->directory . '/horizon-stub', 0700);
        $this->head(2100);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->directory) as $file) {
            if ($file !== '.' && $file !== '..') {
                unlink($this->directory . '/' . $file);
            }
        }
        rmdir($this->directory);
    }

    public function testOnceAdvancesOnlyAfterAllFourExtractorsAndNeverCleansUp(): void
    {
        $result = $this->runScript();
        self::assertSame(0, $result['exit'], $result['output']);
        self::assertSame(2020, $this->state()['next_ledger']);
        self::assertSame(2019, $this->state()['last_completed_ledger']);
        self::assertNull($this->state()['pending_end']);
        self::assertStringContainsString('ingest db reingest range 1990 2029', $this->calls());
        self::assertSame(4, substr_count($this->calls(), 'console app:horizon:sync-'));
        self::assertStringNotContainsString('cleanup', $this->calls());
        self::assertStringNotContainsString('password', file_get_contents($this->directory . '/state.json'));
    }

    public function testNativeUrlsInitializeDoctrineWithoutChangingTheHorizonUrl(): void
    {
        $horizonUrl = 'postgresql://user:p%40ss%23word@127.0.0.1:1/horizon?sslmode=disable';
        $statisticsUrl = 'postgresql://user:p%40ss%23word@127.0.0.1:1/statistics';
        $result = $this->runScript([
            'HORIZON_DATABASE_URL' => $horizonUrl,
            'DATABASE_STATISTICS_URL' => $statisticsUrl,
            'STATISTICS_TEST_DOCTRINE_CONNECTIONS' => '1',
            'STATISTICS_TEST_EXPECT_HORIZON_URL' => $horizonUrl,
            'STATISTICS_TEST_EXPECT_HORIZON_APP_URL' => $horizonUrl . '&charset=utf8',
            'STATISTICS_TEST_EXPECT_STATISTICS_APP_URL' => $statisticsUrl . '?charset=utf8',
        ]);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertSame(4, substr_count($this->calls(), 'doctrine connections initialized'));
        self::assertSame(2020, $this->state()['next_ledger']);
        self::assertNull($this->state()['pending_end']);
        self::assertSame(hash('sha256', '127.0.0.1:1/horizon|127.0.0.1:1/statistics'), $this->state()['identity']);
    }

    public function testTestnetDoctrineUrlsPreserveConfiguredCharsetAndServerVersion(): void
    {
        $this->head(2100, 'Test SDF Network ; September 2015');
        $horizonUrl = 'postgresql://user:password@127.0.0.1:1/horizon?sslmode=require&application_name=forwardfill';
        $statisticsUrl = 'postgresql://user:password@127.0.0.1:1/statistics?sslmode=require&serverVersion=14.24.0&charset=UTF8';
        $result = $this->runScript([
            'NETWORK' => 'testnet',
            'HORIZON_DATABASE_URL' => $horizonUrl,
            'DATABASE_STATISTICS_URL' => $statisticsUrl,
            'STATISTICS_TEST_DOCTRINE_CONNECTIONS' => '1',
            'STATISTICS_TEST_EXPECT_HORIZON_URL' => $horizonUrl,
            'STATISTICS_TEST_EXPECT_HORIZON_APP_URL' => $horizonUrl . '&charset=utf8',
            'STATISTICS_TEST_EXPECT_STATISTICS_APP_URL' => $statisticsUrl,
        ]);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertSame(4, substr_count($this->calls(), 'doctrine connections initialized'));
        self::assertSame(2020, $this->state()['next_ledger']);
    }

    public function testDoctrineCharsetIsAddedBeforeTheUrlFragment(): void
    {
        $horizonUrl = 'postgresql://user:password@127.0.0.1:1/horizon';
        $statisticsUrl = 'postgresql://user:password@127.0.0.1:1/statistics?sslmode=disable&serverVersion=14.24.0';
        $result = $this->runScript([
            'HORIZON_DATABASE_URL' => $horizonUrl,
            'DATABASE_STATISTICS_URL' => $statisticsUrl . '#statistics',
            'STATISTICS_TEST_DOCTRINE_CONNECTIONS' => '1',
            'STATISTICS_TEST_EXPECT_HORIZON_URL' => $horizonUrl,
            'STATISTICS_TEST_EXPECT_HORIZON_APP_URL' => $horizonUrl . '?charset=utf8',
            'STATISTICS_TEST_EXPECT_STATISTICS_APP_URL' => $statisticsUrl . '&charset=utf8#statistics',
        ]);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertSame(4, substr_count($this->calls(), 'doctrine connections initialized'));
    }

    public function testFailedRangeIsRetriedExactlyAfterHeadGrowsAndChunkSizeChanges(): void
    {
        $result = $this->runScript(['STATISTICS_TEST_FAIL_COMMAND' => 'app:horizon:sync-account-activity-summary']);
        self::assertSame(1, $result['exit']);
        self::assertSame(2000, $this->state()['next_ledger']);
        self::assertSame(2019, $this->state()['pending_end']);
        $this->head(3000);
        $result = $this->runScript(['LEDGERS_PER_RANGE' => '100', 'START_LEDGER' => '2800']);
        self::assertSame(0, $result['exit'], $result['output']);
        self::assertSame(2020, $this->state()['next_ledger']);
        self::assertSame(2, substr_count($this->calls(), 'ingest db reingest range 1990 2029'));
    }

    public function testCatchupStopsAtPublishedHeadMinusContext(): void
    {
        $result = $this->runScript(['FORWARDFILL_MODE' => 'catchup']);
        self::assertSame(0, $result['exit'], $result['output']);
        self::assertSame(2091, $this->state()['next_ledger']);
        self::assertSame(5, substr_count($this->calls(), 'ingest db'));
        self::assertStringContainsString('ingest db reingest range 2070 2100', $this->calls());
    }

    public function testUnsafeInheritedOptionsFailBeforeWorkerOrCheckpoint(): void
    {
        foreach (['HORIZON_RETENTION_MODE' => 'truncate-history', 'POST_RANGE_CLEANUP_COMMAND' => 'exit 0',
            'RUN_LOCAL_RESET' => '1', 'HISTORY_RETENTION_COUNT' => '100', 'RUN_PAYMENT_FLOW_EVENTS' => '0'] as $key => $value) {
            $result = $this->runScript([$key => $value]);
            self::assertSame(1, $result['exit']);
            self::assertStringContainsString($key, $result['output']);
            self::assertFileDoesNotExist($this->directory . '/state.json');
            self::assertFileDoesNotExist($this->directory . '/calls.log');
        }
    }

    public function testWrongArchiveNetworkAndInvalidStateFailClosed(): void
    {
        $this->head(2100, 'Test SDF Network ; September 2015');
        self::assertSame(1, $this->runScript()['exit']);
        self::assertFileDoesNotExist($this->directory . '/calls.log');
        file_put_contents($this->directory . '/state.json', "CURRENT_END_LEDGER='2000'\n");
        self::assertSame(1, $this->runScript()['exit']);
        self::assertStringContainsString('CURRENT_END_LEDGER', file_get_contents($this->directory . '/state.json'));
    }

    public function testChangingTargetOrBucketsCannotResumeCheckpoint(): void
    {
        self::assertSame(0, $this->runScript()['exit']);
        $before = $this->state();
        self::assertSame(1, $this->runScript(['DATABASE_STATISTICS_URL' => 'postgresql://user:password@localhost/other'])['exit']);
        self::assertSame(1, $this->runScript(['NETWORK_METRICS_BUCKET_MINUTES' => '10'])['exit']);
        self::assertSame($before, $this->state());
    }

    public function testLockBlocksADifferentCheckpointForSameDatabasePair(): void
    {
        $identity = hash('sha256', 'localhost:5432/horizon|localhost:5432/statistics');
        $path = $this->root . '/.tmp/forwardfill-' . $identity . '.lock';
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        $lock = fopen($path, 'c');
        flock($lock, LOCK_EX);
        try {
            $result = $this->runScript(['STATE_FILE' => $this->directory . '/another-state.json']);
            self::assertSame(1, $result['exit']);
            self::assertStringContainsString('holds the database lock', $result['output']);
            self::assertFileDoesNotExist($this->directory . '/calls.log');
        } finally {
            fclose($lock);
        }
    }

    public function testFollowProcessesNewHeadThenStopsAtExplicitPilotLimit(): void
    {
        $result = $this->runScript(['FORWARDFILL_MODE' => 'follow', 'STOP_LEDGER' => '2005']);
        self::assertSame(0, $result['exit'], $result['output']);
        self::assertSame(2006, $this->state()['next_ledger']);
    }

    private function head(int $ledger, string $network = 'Public Global Stellar Network ; September 2015'): void
    {
        file_put_contents($this->directory . '/head.json', json_encode(['currentLedger' => $ledger, 'networkPassphrase' => $network]));
    }

    private function state(): array
    {
        return json_decode(file_get_contents($this->directory . '/state.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function calls(): string
    {
        return file_get_contents($this->directory . '/calls.log');
    }

    private function runScript(array $overrides = []): array
    {
        $environment = array_replace([
            'PATH' => dirname(PHP_BINARY) . ':/usr/bin:/bin', 'NETWORK' => 'mainnet',
            'START_LEDGER' => '2000', 'LEDGERS_PER_RANGE' => '20', 'MIN_RANGE_LEDGERS' => '10',
            'HORIZON_CONTEXT_LEDGERS' => '10', 'MAX_ATTEMPTS' => '1',
            'HORIZON_DATABASE_URL' => 'postgresql://user:password@localhost/horizon',
            'DATABASE_STATISTICS_URL' => 'postgresql://user:password@localhost/statistics',
            'STATE_FILE' => $this->directory . '/state.json', 'ARCHIVE_HEAD_FILE' => $this->directory . '/head.json',
            'HORIZON_BIN' => $this->directory . '/horizon-stub', 'APP_MODE' => 'host',
            'CONSOLE_BIN' => $this->root . '/tests/Fixtures/statistics-console-stub.php',
            'STATISTICS_TEST_LOG' => $this->directory . '/calls.log',
        ], $overrides);
        $process = proc_open([PHP_BINARY, $this->root . '/bin/horizon-forwardfill.php'],
            [1 => ['pipe', 'w'], 2 => ['file', $this->directory . '/stderr', 'w']], $pipes, $this->root, $environment);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exit = proc_close($process);
        return ['exit' => $exit, 'output' => $output . file_get_contents($this->directory . '/stderr')];
    }
}
