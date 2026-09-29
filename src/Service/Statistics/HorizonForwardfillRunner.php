<?php

declare(strict_types=1);

namespace App\Service\Statistics;

use RuntimeException;

/** Single-host, single-worker orchestration. Does not load dotenv or run cleanup. */
final class HorizonForwardfillRunner
{
    private const PASSPHRASES = [
        'mainnet' => 'Public Global Stellar Network ; September 2015',
        'testnet' => 'Test SDF Network ; September 2015',
    ];

    private array $environment;
    private string $root;
    private string $stateFile;
    private string $identity;
    private int $context;
    private int $chunkSize;
    private bool $stopping = false;

    public function run(array $environment, string $root): int
    {
        $this->configure($environment, $root);
        $directory = dirname($this->stateFile);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create the forwardfill state directory.');
        }

        // Independent of STATE_FILE: a second checkpoint cannot bypass the DB-pair lock.
        $lockDirectory = $this->root . '/.tmp';
        if (!is_dir($lockDirectory) && !mkdir($lockDirectory, 0700, true) && !is_dir($lockDirectory)) {
            throw new RuntimeException('Cannot create the forwardfill lock directory.');
        }
        $lock = fopen($lockDirectory . '/forwardfill-' . $this->identity . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another forwardfill worker holds the database lock.');
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, [$this, 'stop']);
        pcntl_signal(SIGINT, [$this, 'stop']);
        try {
            return $this->process($lock);
        } finally {
            fclose($lock);
        }
    }

    public function stop(int $signal): void
    {
        $this->stopping = true;
        $this->log('Stop requested; finishing the active range before exiting.');
    }

    private function configure(array $environment, string $root): void
    {
        $this->root = $root;
        $this->environment = array_replace([
            'NETWORK' => 'mainnet', 'FORWARDFILL_MODE' => 'once',
            'HORIZON_CONTEXT_LEDGERS' => '128', 'LEDGERS_PER_RANGE' => '10000',
            'MIN_RANGE_LEDGERS' => '64', 'POLL_SECONDS' => '30', 'RETRY_SECONDS' => '30',
            'MAX_ATTEMPTS' => '3', 'NETWORK_METRICS_BUCKET_MINUTES' => '5',
            'ASSET_MARKET_BUCKET_MINUTES' => '5', 'HORIZON_MODE' => 'reingest-range',
        ], $environment);
        $this->rejectUnsafeSettings();
        $network = $this->environment['NETWORK'];
        if (!isset(self::PASSPHRASES[$network])) {
            throw new RuntimeException('NETWORK must be mainnet or testnet.');
        }
        if (!in_array($this->environment['FORWARDFILL_MODE'], ['once', 'catchup', 'follow'], true)) {
            throw new RuntimeException('FORWARDFILL_MODE must be once, catchup or follow.');
        }
        if ($this->environment['HORIZON_MODE'] !== 'reingest-range') {
            throw new RuntimeException('Forwardfill requires HORIZON_MODE=reingest-range.');
        }
        $targets = [];
        foreach (['HORIZON_DATABASE_URL', 'DATABASE_STATISTICS_URL'] as $key) {
            $parts = parse_url($this->environment[$key] ?? '');
            if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['postgres', 'postgresql'], true)
                || empty($parts['host']) || empty($parts['path'])) {
                throw new RuntimeException($key . ' must explicitly identify a PostgreSQL database.');
            }
            parse_str($parts['query'] ?? '', $query);
            foreach (['host', 'hostaddr', 'port', 'dbname', 'database', 'service'] as $routingKey) {
                if (array_key_exists($routingKey, $query)) {
                    throw new RuntimeException($key . ' must not override database routing in query parameters.');
                }
            }
            $targets[] = strtolower($parts['host']) . ':' . ($parts['port'] ?? 5432) . rawurldecode($parts['path']);
        }
        if ($targets[0] === $targets[1]) {
            throw new RuntimeException('Horizon and statistics must use separate databases.');
        }
        // Password rotations do not invalidate checkpoints; target changes do.
        $this->identity = hash('sha256', implode('|', $targets));
        $this->stateFile = $this->environment['STATE_FILE'] ?? $root . '/.tmp/horizon-forwardfill-' . $network . '.json';
        $this->context = $this->positiveInt('HORIZON_CONTEXT_LEDGERS');
        $this->chunkSize = $this->positiveInt('LEDGERS_PER_RANGE');
        foreach (['MIN_RANGE_LEDGERS', 'POLL_SECONDS', 'RETRY_SECONDS', 'MAX_ATTEMPTS',
            'NETWORK_METRICS_BUCKET_MINUTES', 'ASSET_MARKET_BUCKET_MINUTES'] as $key) {
            $this->positiveInt($key);
        }
        if (!empty($this->environment['STOP_LEDGER'])) {
            $this->positiveInt('STOP_LEDGER');
        }
        if ($this->positiveInt('MIN_RANGE_LEDGERS') > $this->chunkSize) {
            throw new RuntimeException('MIN_RANGE_LEDGERS must not exceed LEDGERS_PER_RANGE.');
        }
        if (!function_exists('pcntl_async_signals')) {
            throw new RuntimeException('The PHP pcntl extension is required.');
        }
    }

    private function rejectUnsafeSettings(): void
    {
        $required = [
            'HORIZON_RETENTION_MODE' => 'none', 'POST_RANGE_CLEANUP_COMMAND' => '',
            'HISTORY_RETENTION_COUNT' => '0', 'RUN_LOCAL_RESET' => '0', 'ALLOW_MAINNET_RESET' => '0',
            'RUN_CONTRACT_SCAN' => '0', 'RUN_COINGECKO_WARM' => '0',
            'RUN_MARKET_SNAPSHOTS' => '0', 'RUN_MARKET_OVERVIEW' => '0',
            'RUN_NETWORK_METRICS' => '1', 'RUN_PAYMENT_FLOW_EVENTS' => '1',
            'RUN_ASSET_MARKET_HISTORY' => '1', 'RUN_ACCOUNT_ACTIVITY_SUMMARY' => '1',
        ];
        foreach ($required as $key => $value) {
            if (isset($this->environment[$key]) && $this->environment[$key] !== $value) {
                throw new RuntimeException('Unsafe or incomplete forwardfill configuration: ' . $key);
            }
            $this->environment[$key] = $value;
        }
        if (!empty($this->environment['HORIZON_NETWORK'])
            || !empty($this->environment['NETWORK_PASSPHRASE'])
            || !empty($this->environment['HORIZON_APP_DATABASE_URL'])) {
            throw new RuntimeException('Remove Horizon network/database overrides; use NETWORK and HORIZON_DATABASE_URL.');
        }
    }

    private function positiveInt(string $key): int
    {
        $value = $this->environment[$key] ?? '';
        if (!is_string($value) || !ctype_digit($value) || strlen($value) > 10
            || (int) $value < 1 || (int) $value > 2147483647) {
            throw new RuntimeException($key . ' must be a positive 32-bit integer.');
        }
        return (int) $value;
    }

    private function loadState(): array
    {
        if (!is_file($this->stateFile)) {
            $state = [
                'version' => 1, 'network' => $this->environment['NETWORK'], 'identity' => $this->identity,
                'bucket_minutes' => $this->positiveInt('NETWORK_METRICS_BUCKET_MINUTES'),
                'market_bucket_minutes' => $this->positiveInt('ASSET_MARKET_BUCKET_MINUTES'),
                'next_ledger' => $this->positiveInt('START_LEDGER'), 'pending_end' => null,
                'last_completed_ledger' => null,
            ];
            $this->saveState($state);
            return $state;
        }
        $state = json_decode((string) file_get_contents($this->stateFile), true);
        if (!is_array($state) || ($state['version'] ?? null) !== 1
            || ($state['network'] ?? null) !== $this->environment['NETWORK']
            || ($state['identity'] ?? null) !== $this->identity
            || ($state['bucket_minutes'] ?? null) !== $this->positiveInt('NETWORK_METRICS_BUCKET_MINUTES')
            || ($state['market_bucket_minutes'] ?? null) !== $this->positiveInt('ASSET_MARKET_BUCKET_MINUTES')
            || !is_int($state['next_ledger'] ?? null) || $state['next_ledger'] < 1
            || $state['next_ledger'] > 2147483647
            || !array_key_exists('pending_end', $state)
            || !array_key_exists('last_completed_ledger', $state)
            || ($state['last_completed_ledger'] !== null && (!is_int($state['last_completed_ledger'])
                || $state['last_completed_ledger'] !== $state['next_ledger'] - 1))
            || ($state['pending_end'] !== null && (!is_int($state['pending_end'])
                || $state['pending_end'] < $state['next_ledger'] || $state['pending_end'] > 2147483647))) {
            throw new RuntimeException('Invalid or incompatible forwardfill checkpoint; it was not changed.');
        }
        return $state;
    }

    private function saveState(array $state): void
    {
        $state['updated_at'] = gmdate('c');
        $temporary = tempnam(dirname($this->stateFile), '.forwardfill-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot create checkpoint temporary file.');
        }
        try {
            $stream = fopen($temporary, 'wb');
            if ($stream === false) {
                throw new RuntimeException('Cannot open checkpoint temporary file.');
            }
            try {
                $json = json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
                if (fwrite($stream, $json) !== strlen($json) || !fflush($stream) || !fsync($stream)) {
                    throw new RuntimeException('Cannot persist checkpoint.');
                }
            } finally {
                fclose($stream);
            }
            if (!rename($temporary, $this->stateFile)) {
                throw new RuntimeException('Cannot atomically replace checkpoint.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** @param resource $lock */
    private function process($lock): int
    {
        $state = $this->loadState();
        $mode = $this->environment['FORWARDFILL_MODE'];
        $target = null;
        while (!$this->stopping) {
            if ($state['pending_end'] === null) {
                $head = $this->readHeadWithRetries();
                if ($this->stopping) {
                    return 0;
                }
                $safeHead = $head - $this->context;
                if ($mode !== 'follow' && $target === null) {
                    $target = $safeHead;
                }
                $end = min($state['next_ledger'] + $this->chunkSize - 1, $safeHead, $target ?? $safeHead);
                if (!empty($this->environment['STOP_LEDGER'])) {
                    $end = min($end, $this->positiveInt('STOP_LEDGER'));
                    if ($state['next_ledger'] > $this->positiveInt('STOP_LEDGER')) {
                        return 0;
                    }
                }
                if ($end < $state['next_ledger'] || ($mode === 'follow'
                    && $end - $state['next_ledger'] + 1 < $this->positiveInt('MIN_RANGE_LEDGERS')
                    && $end !== (int) ($this->environment['STOP_LEDGER'] ?? 0))) {
                    $this->log(sprintf('Waiting: next=%d archive=%d safe=%d', $state['next_ledger'], $head, $safeHead));
                    if ($mode !== 'follow') {
                        return 0;
                    }
                    $this->pause($this->positiveInt('POLL_SECONDS'));
                    continue;
                }
                $state['pending_end'] = $end;
                $this->saveState($state);
            }

            $this->runRangeWithRetries($state['next_ledger'], $state['pending_end'], $lock);
            $state['last_completed_ledger'] = $state['pending_end'];
            $state['next_ledger'] = $state['pending_end'] + 1;
            $state['pending_end'] = null;
            $this->saveState($state);
            $this->log('Committed checkpoint through ledger ' . $state['last_completed_ledger']);
            if ($mode === 'once') {
                return 0;
            }
        }
        return 0;
    }

    private function readHeadWithRetries(): int
    {
        for ($attempt = 1; $attempt <= $this->positiveInt('MAX_ATTEMPTS'); ++$attempt) {
            try {
                return $this->readHead();
            } catch (RuntimeException $exception) {
                if ($this->stopping || $attempt === $this->positiveInt('MAX_ATTEMPTS')) {
                    throw $exception;
                }
                $this->log('Archive unavailable or invalid; retry ' . $attempt);
                $this->pause($this->positiveInt('RETRY_SECONDS'));
            }
        }
        throw new RuntimeException('Unable to read archive head.');
    }

    private function readHead(): int
    {
        // An explicit local archive snapshot is useful for an offline pilot and regression tests.
        $source = $this->environment['ARCHIVE_HEAD_FILE'] ?? null;
        if ($source === null) {
            $url = $this->environment['ARCHIVE_STATE_URL'] ?? '';
            if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
                throw new RuntimeException('ARCHIVE_STATE_URL must be an explicit HTTPS history archive state URL.');
            }
            $source = $url;
        } elseif (!is_file($source) || str_contains($source, '://')) {
            throw new RuntimeException('ARCHIVE_HEAD_FILE must be a local file.');
        }
        $context = stream_context_create(['http' => ['timeout' => 15, 'follow_location' => 0],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $json = @file_get_contents($source, false, $context, 0, 1048576);
        $state = $json === false ? null : json_decode($json, true);
        if (!is_array($state) || ($state['networkPassphrase'] ?? null) !== self::PASSPHRASES[$this->environment['NETWORK']]
            || !is_int($state['currentLedger'] ?? null) || $state['currentLedger'] <= $this->context
            || $state['currentLedger'] > 2147483647) {
            throw new RuntimeException('Cannot validate the published archive head and network.');
        }
        return $state['currentLedger'];
    }

    /** @param resource $lock */
    private function runRangeWithRetries(int $start, int $end, $lock): void
    {
        $statisticsUrl = $this->withDoctrineCharset($this->environment['DATABASE_STATISTICS_URL']);
        $horizonUrl = $this->withDoctrineCharset($this->environment['HORIZON_DATABASE_URL']);
        $environment = array_replace($this->environment, [
            'START_LEDGER' => (string) $start, 'END_LEDGER' => (string) $end,
            'DATABASE_URL' => $statisticsUrl,
            'DATABASE_STATISTICS_URL' => $statisticsUrl,
            'DATABASE_CONTRACTS_URL' => $statisticsUrl,
            'DATABASE_HORIZON_URL' => $horizonUrl,
            'HORIZON_APP_DATABASE_URL' => $horizonUrl,
        ]);
        for ($attempt = 1; $attempt <= $this->positiveInt('MAX_ATTEMPTS'); ++$attempt) {
            if ($this->stopping) {
                throw new RuntimeException('Stopped before retry; the pending range is preserved.');
            }
            $this->log(sprintf('Forward range %d..%d attempt=%d', $start, $end, $attempt));
            // Inherited fd 9 keeps the lock held even if the supervisor is killed mid-worker.
            $process = proc_open(['bash', $this->root . '/bin/horizon-range-stats.sh'],
                [0 => STDIN, 1 => STDOUT, 2 => STDERR, 9 => $lock], $pipes, $this->root, $environment);
            if ($process === false) {
                throw new RuntimeException('Cannot start the range worker.');
            }
            if (proc_close($process) === 0) {
                return;
            }
            if ($this->stopping || $attempt === $this->positiveInt('MAX_ATTEMPTS')) {
                throw new RuntimeException('Range failed; checkpoint retains the exact pending range for resume.');
            }
            $this->pause(min(300, $this->positiveInt('RETRY_SECONDS') * $attempt));
        }
    }

    private function withDoctrineCharset(string $url): string
    {
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        if (array_key_exists('charset', $query)) {
            return $url;
        }

        // DoctrineBundle 3.2.2 requires charset or serverVersion before it can connect.
        // Add metadata only to Symfony URLs; keep the native Horizon URL unchanged.
        $fragmentPosition = strpos($url, '#');
        $baseUrl = $fragmentPosition === false ? $url : substr($url, 0, $fragmentPosition);
        $fragment = $fragmentPosition === false ? '' : substr($url, $fragmentPosition);
        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        return $baseUrl . $separator . 'charset=utf8' . $fragment;
    }

    private function pause(int $seconds): void
    {
        for ($elapsed = 0; $elapsed < $seconds && !$this->stopping; ++$elapsed) {
            sleep(1);
        }
    }

    private function log(string $message): void
    {
        fwrite(STDOUT, '[' . gmdate('c') . '] ' . $message . PHP_EOL);
    }
}
