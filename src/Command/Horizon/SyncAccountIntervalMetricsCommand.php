<?php

declare(strict_types=1);

namespace App\Command\Horizon;

use App\Command\Support\NetworkOptionTrait;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:account-metrics:sync-intervals',
    description: 'Aggregate account intervals directly from Horizon DB and sync local account metrics.',
)]
final class SyncAccountIntervalMetricsCommand extends Command
{
    use NetworkOptionTrait;

    private const ASSET_OPERATION_TYPES = [1, 2, 3, 4, 6, 7, 12, 13, 14, 15, 19, 20, 21, 22, 23];
    private const CONTRACT_OPERATION_TYPES = [24, 25, 26];
    private const TRADE_OPERATION_TYPES = [3, 4, 12, 22, 23];
    private const PAYMENT_RELATED_OPERATION_TYPES = [1, 2, 13];

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private readonly Connection $connection,
        private readonly ManagerRegistry $doctrine,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addNetworkOption('Horizon network (mainnet|testnet|futurenet)', 'testnet')
            ->addOption('lookback-hours', null, InputOption::VALUE_REQUIRED, 'How many hours to import back from now (overrides --lookback-days when set)')
            ->addOption('lookback-days', null, InputOption::VALUE_REQUIRED, 'How many days to import back from now', 30)
            ->addOption('interval-minutes', null, InputOption::VALUE_REQUIRED, 'Interval size in minutes', 5)
            ->addOption('page-limit', null, InputOption::VALUE_REQUIRED, 'Deprecated (kept for BC, ignored)', 25)
            ->addOption('account', null, InputOption::VALUE_REQUIRED, 'Single account address to process')
            ->addOption('top', null, InputOption::VALUE_REQUIRED, 'How many top ranked accounts to process', 1000)
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Accounts loaded per DB batch', 250)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compute only, without writing DB rows');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $network = $this->resolveNetworkOption($input, $this->stellarNetworkResolver);
        $lookbackHoursRaw = $input->getOption('lookback-hours');
        $lookbackHours = ($lookbackHoursRaw === null || $lookbackHoursRaw === '') ? null : (int) $lookbackHoursRaw;
        $lookbackDays = (int) $input->getOption('lookback-days');
        $intervalMinutes = (int) $input->getOption('interval-minutes');
        $accountFilter = $this->nullableTrimmedString($input->getOption('account'));
        $top = max(1, (int) $input->getOption('top'));
        $batchSize = (int) $input->getOption('batch-size');
        $dryRun = (bool) $input->getOption('dry-run');
        $networkCode = $this->resolveNetworkCodeOption($input, $this->stellarNetworkResolver);

        if ($lookbackHours !== null && $lookbackHours < 1) {
            $io->error('--lookback-hours must be >= 1.');

            return Command::FAILURE;
        }
        if ($lookbackDays < 1) {
            $io->error('--lookback-days must be >= 1.');

            return Command::FAILURE;
        }
        if ($intervalMinutes < 1) {
            $io->error('--interval-minutes must be >= 1.');

            return Command::FAILURE;
        }
        if ($batchSize < 1) {
            $io->error('--batch-size must be >= 1.');

            return Command::FAILURE;
        }

        $horizonConnection = $this->resolveHorizonConnection($network);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($lookbackHours !== null) {
            $since = $now->sub(new \DateInterval(sprintf('PT%dH', $lookbackHours)));
            $lookbackLabel = sprintf('%dh', $lookbackHours);
        } else {
            $since = $now->sub(new \DateInterval(sprintf('P%dD', $lookbackDays)));
            $lookbackLabel = sprintf('%dd', $lookbackDays);
        }
        $intervalSeconds = $intervalMinutes * 60;

        $totalAccounts = $this->countAccounts($networkCode, $accountFilter, $top);
        if ($totalAccounts === 0) {
            $io->warning('No accounts found to process.');

            return Command::SUCCESS;
        }

        $io->writeln(sprintf(
            'Network=%s, accounts=%d, lookback=%s, since=%s, interval=%dm',
            $network,
            $totalAccounts,
            $lookbackLabel,
            $since->format(\DateTimeInterface::ATOM),
            $intervalMinutes
        ));

        $processed = 0;
        $failed = 0;
        $totalTxConsidered = 0;
        $totalBuckets = 0;
        $totalRowsWritten = 0;
        $totalSnapshotsWritten = 0;

        $io->progressStart($totalAccounts);

        $this->iterateAccounts($networkCode, $batchSize, $accountFilter, $top, function (array $accountRows) use (
            $horizonConnection,
            $since,
            $intervalSeconds,
            $now,
            $dryRun,
            &$processed,
            &$failed,
            &$totalTxConsidered,
            &$totalBuckets,
            &$totalRowsWritten,
            &$totalSnapshotsWritten,
            $io
        ): void {
            try {
                $addresses = array_map(static fn (array $row): string => (string) $row['address'], $accountRows);
                $intervalsByAddress = $this->loadIntervalsByAddress($horizonConnection, $addresses, $since, $intervalSeconds);
                $firstLastByAddress = $this->loadFirstLastTransactionByAddress($horizonConnection, $addresses);

                foreach ($accountRows as $accountRow) {
                    $accountId = (int) $accountRow['id'];
                    $address = (string) $accountRow['address'];
                    $buckets = $intervalsByAddress[$address] ?? [];

                    $txForAccount = 0;
                    foreach ($buckets as $bucket) {
                        $txForAccount += (int) ($bucket['total_transactions'] ?? 0);
                    }
                    $totalTxConsidered += $txForAccount;
                    $totalBuckets += count($buckets);

                    if (!$dryRun) {
                        $rows = $this->replaceAccountIntervals($accountId, $buckets, $since, $intervalSeconds, $now);
                        $totalRowsWritten += $rows;
                        $intervalTotals = $this->loadIntervalTotals($accountId);
                        $transactionsPerHour = $this->loadTransactionsPerHourFromIntervals($accountId, $now);
                        $nativeBalance = $this->loadCurrentNativeBalance($accountId);
                        $firstLast = $firstLastByAddress[$address] ?? ['first_transaction_at' => null, 'last_transaction_at' => null];
                        $this->upsertAccountMetricSnapshot(
                            $accountId,
                            $transactionsPerHour,
                            $intervalTotals['payments_count'],
                            $intervalTotals['trades_count'],
                            $nativeBalance,
                            $firstLast['first_transaction_at'],
                            $firstLast['last_transaction_at']
                        );
                        if ($this->storeAccountBalanceSnapshot($accountId, $nativeBalance, $now)) {
                            $totalSnapshotsWritten++;
                        }
                    }

                    $processed++;
                    $io->progressAdvance();
                }
            } catch (\Throwable $exception) {
                $failed += count($accountRows);
                foreach ($accountRows as $_) {
                    $io->progressAdvance();
                }
                $io->writeln(sprintf('<error>Batch failed: %s</error>', $exception->getMessage()));
            }
        });

        $io->progressFinish();
        if (!$dryRun) {
            $this->refreshAccountRanks($now);
        }
        $io->newLine();
        $io->table(
            ['Metric', 'Value'],
            [
                ['accounts_processed', (string) $processed],
                ['accounts_failed', (string) $failed],
                ['transactions_considered', (string) $totalTxConsidered],
                ['interval_buckets', (string) $totalBuckets],
                ['rows_written', $dryRun ? '0 (dry-run)' : (string) $totalRowsWritten],
                ['balance_snapshots_written', $dryRun ? '0 (dry-run)' : (string) $totalSnapshotsWritten],
            ]
        );
        $io->success($dryRun ? 'Dry-run completed.' : 'Account interval metrics sync completed.');

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $addresses
     * @return array<string,list<array{
     *   interval_start:string,
     *   interval_end:string,
     *   total_transactions:int,
     *   payment_operations:int,
     *   trade_operations:int,
     *   asset_transactions:int,
     *   contract_transactions:int,
     *   successful_transactions:int,
     *   failed_transactions:int,
     *   operation_count:int,
     *   fee_charged_sum:string,
     *   max_fee_sum:string,
     *   first_tx_at:?string,
     *   last_tx_at:?string
     * }>>
     */
    private function loadIntervalsByAddress(
        Connection $horizonConnection,
        array $addresses,
        \DateTimeImmutable $since,
        int $intervalSeconds
    ): array {
        $addresses = array_values(array_filter(array_map('trim', $addresses), static fn (string $value): bool => $value !== ''));
        if ($addresses === []) {
            return [];
        }

        $sql = <<<SQL
WITH source_tx AS (
    SELECT
        ht.id AS tx_id,
        ht.account AS address,
        ht.created_at,
        ht.successful,
        COALESCE(ht.operation_count, 0) AS operation_count,
        COALESCE(ht.fee_charged, 0) AS fee_charged,
        COALESCE(ht.max_fee, 0) AS max_fee,
        to_timestamp(floor(extract(epoch FROM ht.created_at) / :interval_seconds) * :interval_seconds) AS bucket_start
    FROM history_transactions ht
    WHERE ht.account IN (:addresses)
      AND ht.created_at >= :since_dt
),
source_operation_tx AS (
    SELECT
        ht.id AS tx_id,
        ho.source_account AS address,
        ht.created_at,
        ht.successful,
        COALESCE(ht.operation_count, 0) AS operation_count,
        COALESCE(ht.fee_charged, 0) AS fee_charged,
        COALESCE(ht.max_fee, 0) AS max_fee,
        to_timestamp(floor(extract(epoch FROM ht.created_at) / :interval_seconds) * :interval_seconds) AS bucket_start
    FROM history_operations ho
    INNER JOIN history_transactions ht ON ht.id = ho.transaction_id
    WHERE ho.source_account IN (:addresses)
      AND ht.created_at >= :since_dt
),
incoming_payment_tx AS (
    SELECT
        ht.id AS tx_id,
        (ho.details->>'to') AS address,
        ht.created_at,
        ht.successful,
        COALESCE(ht.operation_count, 0) AS operation_count,
        COALESCE(ht.fee_charged, 0) AS fee_charged,
        COALESCE(ht.max_fee, 0) AS max_fee,
        to_timestamp(floor(extract(epoch FROM ht.created_at) / :interval_seconds) * :interval_seconds) AS bucket_start
    FROM history_operations ho
    INNER JOIN history_transactions ht ON ht.id = ho.transaction_id
    WHERE ho.type IN (:payment_types)
      AND (ho.details->>'to') IN (:addresses)
      AND ht.created_at >= :since_dt
),
filtered_tx_raw AS (
    SELECT * FROM source_tx
    UNION ALL
    SELECT * FROM source_operation_tx
    UNION ALL
    SELECT * FROM incoming_payment_tx
),
filtered_tx AS (
    SELECT DISTINCT ON (tx_id, address)
        tx_id, address, created_at, successful, operation_count, fee_charged, max_fee, bucket_start
    FROM filtered_tx_raw
    WHERE address IS NOT NULL AND address <> ''
    ORDER BY tx_id, address
),
ops_per_tx AS (
    SELECT
        ho.transaction_id AS tx_id,
        SUM(CASE WHEN ho.is_payment THEN 1 ELSE 0 END) AS payment_ops,
        SUM(CASE WHEN ho.type IN (:trade_types) THEN 1 ELSE 0 END) AS trade_ops,
        MAX(CASE WHEN ho.type IN (:asset_types) THEN 1 ELSE 0 END) AS has_asset,
        MAX(CASE WHEN ho.type IN (:contract_types) THEN 1 ELSE 0 END) AS has_contract
    FROM history_operations ho
    INNER JOIN filtered_tx ft ON ft.tx_id = ho.transaction_id
    GROUP BY ho.transaction_id
)
SELECT
    ft.address,
    ft.bucket_start AS interval_start,
    (ft.bucket_start + (:interval_seconds || ' seconds')::interval) AS interval_end,
    COUNT(*) AS total_transactions,
    COALESCE(SUM(optx.payment_ops), 0) AS payment_operations,
    COALESCE(SUM(optx.trade_ops), 0) AS trade_operations,
    COALESCE(SUM(optx.has_asset), 0) AS asset_transactions,
    COALESCE(SUM(optx.has_contract), 0) AS contract_transactions,
    COALESCE(SUM(CASE WHEN ft.successful THEN 1 ELSE 0 END), 0) AS successful_transactions,
    COALESCE(SUM(CASE WHEN ft.successful THEN 0 ELSE 1 END), 0) AS failed_transactions,
    COALESCE(SUM(ft.operation_count), 0) AS operation_count,
    COALESCE(SUM(ft.fee_charged), 0) AS fee_charged_sum,
    COALESCE(SUM(ft.max_fee), 0) AS max_fee_sum,
    MIN(ft.created_at) AS first_tx_at,
    MAX(ft.created_at) AS last_tx_at
FROM filtered_tx ft
LEFT JOIN ops_per_tx optx ON optx.tx_id = ft.tx_id
GROUP BY ft.address, ft.bucket_start
ORDER BY ft.address ASC, ft.bucket_start ASC
SQL;

        $rows = $horizonConnection->fetchAllAssociative(
            $sql,
            [
                'addresses' => $addresses,
                'since_dt' => $since->format('Y-m-d H:i:s'),
                'interval_seconds' => $intervalSeconds,
                'trade_types' => self::TRADE_OPERATION_TYPES,
                'asset_types' => self::ASSET_OPERATION_TYPES,
                'contract_types' => self::CONTRACT_OPERATION_TYPES,
                'payment_types' => self::PAYMENT_RELATED_OPERATION_TYPES,
            ],
            [
                'addresses' => ArrayParameterType::STRING,
                'since_dt' => ParameterType::STRING,
                'interval_seconds' => ParameterType::INTEGER,
                'trade_types' => ArrayParameterType::INTEGER,
                'asset_types' => ArrayParameterType::INTEGER,
                'contract_types' => ArrayParameterType::INTEGER,
                'payment_types' => ArrayParameterType::INTEGER,
            ]
        );

        $result = [];
        foreach ($rows as $row) {
            $address = (string) ($row['address'] ?? '');
            if ($address === '') {
                continue;
            }

            $intervalStart = $this->toSqlDateTime($row['interval_start'] ?? null);
            if ($intervalStart === null) {
                continue;
            }

            $intervalEnd = $this->toSqlDateTime($row['interval_end'] ?? null) ?? $intervalStart;
            $result[$address][] = [
                'interval_start' => $intervalStart,
                'interval_end' => $intervalEnd,
                'total_transactions' => (int) ($row['total_transactions'] ?? 0),
                'payment_operations' => (int) ($row['payment_operations'] ?? 0),
                'trade_operations' => (int) ($row['trade_operations'] ?? 0),
                'asset_transactions' => (int) ($row['asset_transactions'] ?? 0),
                'contract_transactions' => (int) ($row['contract_transactions'] ?? 0),
                'successful_transactions' => (int) ($row['successful_transactions'] ?? 0),
                'failed_transactions' => (int) ($row['failed_transactions'] ?? 0),
                'operation_count' => (int) ($row['operation_count'] ?? 0),
                'fee_charged_sum' => (string) ($row['fee_charged_sum'] ?? '0'),
                'max_fee_sum' => (string) ($row['max_fee_sum'] ?? '0'),
                'first_tx_at' => $this->toSqlDateTime($row['first_tx_at'] ?? null),
                'last_tx_at' => $this->toSqlDateTime($row['last_tx_at'] ?? null),
            ];
        }

        return $result;
    }

    /**
     * @param list<string> $addresses
     * @return array<string,array{first_transaction_at:?string,last_transaction_at:?string}>
     */
    private function loadFirstLastTransactionByAddress(Connection $horizonConnection, array $addresses): array
    {
        $addresses = array_values(array_filter(array_map('trim', $addresses), static fn (string $value): bool => $value !== ''));
        if ($addresses === []) {
            return [];
        }

        $rows = $horizonConnection->fetchAllAssociative(
            <<<SQL
WITH involved_source AS (
    SELECT ht.account AS address, ht.created_at
    FROM history_transactions ht
    WHERE ht.account IN (:addresses)
),
involved_op_source AS (
    SELECT ho.source_account AS address, ht.created_at
    FROM history_operations ho
    INNER JOIN history_transactions ht ON ht.id = ho.transaction_id
    WHERE ho.source_account IN (:addresses)
),
involved_op_dest AS (
    SELECT (ho.details->>'to') AS address, ht.created_at
    FROM history_operations ho
    INNER JOIN history_transactions ht ON ht.id = ho.transaction_id
    WHERE ho.type IN (:payment_types)
      AND (ho.details->>'to') IN (:addresses)
),
involved_all AS (
    SELECT * FROM involved_source
    UNION ALL
    SELECT * FROM involved_op_source
    UNION ALL
    SELECT * FROM involved_op_dest
)
SELECT
    ia.address,
    MIN(ia.created_at) AS first_transaction_at,
    MAX(ia.created_at) AS last_transaction_at
FROM involved_all ia
WHERE ia.address IS NOT NULL AND ia.address <> ''
GROUP BY ia.address
SQL,
            [
                'addresses' => $addresses,
                'payment_types' => self::PAYMENT_RELATED_OPERATION_TYPES,
            ],
            [
                'addresses' => ArrayParameterType::STRING,
                'payment_types' => ArrayParameterType::INTEGER,
            ]
        );

        $result = [];
        foreach ($rows as $row) {
            $address = (string) ($row['address'] ?? '');
            if ($address === '') {
                continue;
            }
            $result[$address] = [
                'first_transaction_at' => $this->toSqlDateTime($row['first_transaction_at'] ?? null),
                'last_transaction_at' => $this->toSqlDateTime($row['last_transaction_at'] ?? null),
            ];
        }

        return $result;
    }

    /**
     * @param list<array{
     *   interval_start:string,
     *   interval_end:string,
     *   total_transactions:int,
     *   payment_operations:int,
     *   trade_operations:int,
     *   asset_transactions:int,
     *   contract_transactions:int,
     *   successful_transactions:int,
     *   failed_transactions:int,
     *   operation_count:int,
     *   fee_charged_sum:string,
     *   max_fee_sum:string,
     *   first_tx_at:?string,
     *   last_tx_at:?string
     * }> $buckets
     */
    private function replaceAccountIntervals(
        int $accountId,
        array $buckets,
        \DateTimeImmutable $since,
        int $intervalSeconds,
        \DateTimeImmutable $now
    ): int {
        $this->connection->beginTransaction();

        try {
            $sinceBucket = intdiv($since->getTimestamp(), $intervalSeconds) * $intervalSeconds;
            $sinceStart = (new \DateTimeImmutable('@' . $sinceBucket))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');

            $this->connection->executeStatement(
                'DELETE FROM account_metric_interval WHERE account_id = :account_id AND interval_start >= :since_start',
                [
                    'account_id' => $accountId,
                    'since_start' => $sinceStart,
                ],
                [
                    'account_id' => ParameterType::INTEGER,
                ]
            );

            $rowsWritten = 0;
            $timestamp = $now->format('Y-m-d H:i:s');

            foreach ($buckets as $bucket) {
                $this->connection->executeStatement(
                    <<<SQL
INSERT INTO account_metric_interval (
    account_id,
    interval_start,
    interval_end,
    total_transactions,
    payment_operations,
    trade_operations,
    asset_transactions,
    contract_transactions,
    successful_transactions,
    failed_transactions,
    operation_count,
    fee_charged_sum,
    max_fee_sum,
    first_tx_at,
    last_tx_at,
    created_at,
    updated_at
) VALUES (
    :account_id,
    :interval_start,
    :interval_end,
    :total_transactions,
    :payment_operations,
    :trade_operations,
    :asset_transactions,
    :contract_transactions,
    :successful_transactions,
    :failed_transactions,
    :operation_count,
    :fee_charged_sum,
    :max_fee_sum,
    :first_tx_at,
    :last_tx_at,
    :created_at,
    :updated_at
)
ON DUPLICATE KEY UPDATE
    interval_end = VALUES(interval_end),
    total_transactions = VALUES(total_transactions),
    payment_operations = VALUES(payment_operations),
    trade_operations = VALUES(trade_operations),
    asset_transactions = VALUES(asset_transactions),
    contract_transactions = VALUES(contract_transactions),
    successful_transactions = VALUES(successful_transactions),
    failed_transactions = VALUES(failed_transactions),
    operation_count = VALUES(operation_count),
    fee_charged_sum = VALUES(fee_charged_sum),
    max_fee_sum = VALUES(max_fee_sum),
    first_tx_at = VALUES(first_tx_at),
    last_tx_at = VALUES(last_tx_at),
    updated_at = VALUES(updated_at)
SQL,
                    [
                        'account_id' => $accountId,
                        'interval_start' => $bucket['interval_start'],
                        'interval_end' => $bucket['interval_end'],
                        'total_transactions' => $bucket['total_transactions'],
                        'payment_operations' => $bucket['payment_operations'],
                        'trade_operations' => $bucket['trade_operations'],
                        'asset_transactions' => $bucket['asset_transactions'],
                        'contract_transactions' => $bucket['contract_transactions'],
                        'successful_transactions' => $bucket['successful_transactions'],
                        'failed_transactions' => $bucket['failed_transactions'],
                        'operation_count' => $bucket['operation_count'],
                        'fee_charged_sum' => $bucket['fee_charged_sum'],
                        'max_fee_sum' => $bucket['max_fee_sum'],
                        'first_tx_at' => $bucket['first_tx_at'],
                        'last_tx_at' => $bucket['last_tx_at'],
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ],
                    [
                        'account_id' => ParameterType::INTEGER,
                        'total_transactions' => ParameterType::INTEGER,
                        'payment_operations' => ParameterType::INTEGER,
                        'trade_operations' => ParameterType::INTEGER,
                        'asset_transactions' => ParameterType::INTEGER,
                        'contract_transactions' => ParameterType::INTEGER,
                        'successful_transactions' => ParameterType::INTEGER,
                        'failed_transactions' => ParameterType::INTEGER,
                        'operation_count' => ParameterType::INTEGER,
                    ]
                );
                $rowsWritten++;
            }

            $this->connection->commit();

            return $rowsWritten;
        } catch (\Throwable $exception) {
            $this->connection->rollBack();
            throw $exception;
        }
    }

    private function countAccounts(int $networkCode, ?string $accountAddress, int $top): int
    {
        if ($accountAddress !== null) {
            return (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM account WHERE address = :address AND network = :network',
                [
                    'address' => $accountAddress,
                    'network' => $networkCode,
                ],
                [
                    'network' => ParameterType::INTEGER,
                ]
            );
        }

        return (int) $this->connection->fetchOne(
            <<<SQL
SELECT COUNT(*)
FROM (
    SELECT a.id
    FROM account a
    LEFT JOIN account_metric am ON am.account_id = a.id
    WHERE a.network = :network
    ORDER BY
        CASE WHEN am.rank_position IS NULL THEN 1 ELSE 0 END ASC,
        am.rank_position ASC,
        a.id ASC
    LIMIT :top
) ranked_accounts
SQL,
            [
                'network' => $networkCode,
                'top' => $top,
            ],
            [
                'network' => ParameterType::INTEGER,
                'top' => ParameterType::INTEGER,
            ]
        );
    }

    /**
     * @param callable(list<array{id:int,address:string}>):void $consumer
     */
    private function iterateAccounts(int $networkCode, int $batchSize, ?string $accountAddress, int $top, callable $consumer): void
    {
        if ($accountAddress !== null) {
            $row = $this->connection->fetchAssociative(
                'SELECT id, address FROM account WHERE address = :address AND network = :network LIMIT 1',
                [
                    'address' => $accountAddress,
                    'network' => $networkCode,
                ],
                [
                    'network' => ParameterType::INTEGER,
                ]
            );
            if (is_array($row) && isset($row['id'], $row['address'])) {
                $consumer([['id' => (int) $row['id'], 'address' => (string) $row['address']]]);
            }

            return;
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
SELECT a.id, a.address
FROM account a
LEFT JOIN account_metric am ON am.account_id = a.id
WHERE a.network = :network
ORDER BY
    CASE WHEN am.rank_position IS NULL THEN 1 ELSE 0 END ASC,
    am.rank_position ASC,
    a.id ASC
LIMIT :top
SQL,
            [
                'network' => $networkCode,
                'top' => $top,
            ],
            [
                'network' => ParameterType::INTEGER,
                'top' => ParameterType::INTEGER,
            ]
        );
        if ($rows === []) {
            return;
        }

        $batch = [];
        foreach ($rows as $row) {
            $batch[] = ['id' => (int) $row['id'], 'address' => (string) $row['address']];
            if (count($batch) >= $batchSize) {
                $consumer($batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $consumer($batch);
        }
    }

    private function nullableTrimmedString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function loadCurrentNativeBalance(int $accountId): string
    {
        $value = $this->connection->fetchOne(
            'SELECT native_balance FROM account_metric WHERE account_id = :account_id',
            ['account_id' => $accountId],
            ['account_id' => ParameterType::INTEGER]
        );

        if ($value === false || $value === null || $value === '') {
            return '0.0000000';
        }

        return (string) $value;
    }

    /**
     * @return array{payments_count:string,trades_count:string,first_transaction_at:?string,last_transaction_at:?string}
     */
    private function loadIntervalTotals(int $accountId): array
    {
        $row = $this->connection->fetchAssociative(
            <<<SQL
SELECT
    COALESCE(SUM(payment_operations), 0) AS payments_count,
    COALESCE(SUM(trade_operations), 0) AS trades_count,
    MIN(first_tx_at) AS first_transaction_at,
    MAX(last_tx_at) AS last_transaction_at
FROM account_metric_interval
WHERE account_id = :account_id
SQL,
            ['account_id' => $accountId],
            ['account_id' => ParameterType::INTEGER]
        );

        return [
            'payments_count' => (string) ($row['payments_count'] ?? '0'),
            'trades_count' => (string) ($row['trades_count'] ?? '0'),
            'first_transaction_at' => $this->toSqlDateTime($row['first_transaction_at'] ?? null),
            'last_transaction_at' => $this->toSqlDateTime($row['last_transaction_at'] ?? null),
        ];
    }

    private function loadTransactionsPerHourFromIntervals(int $accountId, \DateTimeImmutable $now): string
    {
        $from = $now->sub(new \DateInterval('PT1H'))->format('Y-m-d H:i:s');
        $value = $this->connection->fetchOne(
            <<<SQL
SELECT COALESCE(SUM(total_transactions), 0)
FROM account_metric_interval
WHERE account_id = :account_id
  AND interval_end > :from_dt
SQL,
            [
                'account_id' => $accountId,
                'from_dt' => $from,
            ],
            [
                'account_id' => ParameterType::INTEGER,
                'from_dt' => ParameterType::STRING,
            ]
        );

        return (string) ($value ?? '0');
    }

    private function upsertAccountMetricSnapshot(
        int $accountId,
        string $transactionsPerHour,
        string $paymentsCount,
        string $tradesCount,
        string $nativeBalance,
        ?string $firstTransactionAt,
        ?string $lastTransactionAt
    ): void {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $this->connection->executeStatement(
            <<<SQL
INSERT INTO account_metric (
    account_id,
    total_transactions,
    native_balance,
    payments_count,
    trades_count,
    first_transaction_at,
    last_transaction_at,
    metric_updated_at
)
VALUES (
    :account_id,
    :total_transactions,
    :native_balance,
    :payments_count,
    :trades_count,
    :first_transaction_at,
    :last_transaction_at,
    :metric_updated_at
)
ON DUPLICATE KEY UPDATE
    total_transactions = VALUES(total_transactions),
    native_balance = VALUES(native_balance),
    payments_count = VALUES(payments_count),
    trades_count = VALUES(trades_count),
    first_transaction_at = VALUES(first_transaction_at),
    last_transaction_at = VALUES(last_transaction_at),
    metric_updated_at = VALUES(metric_updated_at)
SQL,
            [
                'account_id' => $accountId,
                'total_transactions' => $transactionsPerHour,
                'native_balance' => $nativeBalance,
                'payments_count' => $paymentsCount,
                'trades_count' => $tradesCount,
                'first_transaction_at' => $firstTransactionAt,
                'last_transaction_at' => $lastTransactionAt,
                'metric_updated_at' => $now,
            ],
            [
                'account_id' => ParameterType::INTEGER,
            ]
        );
    }

    private function storeAccountBalanceSnapshot(int $accountId, string $nativeBalance, \DateTimeImmutable $now): bool
    {
        $recordedHour = $now
            ->setTimezone(new \DateTimeZone('UTC'))
            ->setTime((int) $now->format('H'), 0, 0)
            ->format('Y-m-d H:i:s');
        $timestamp = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $this->connection->executeStatement(
            <<<SQL
INSERT INTO account_balance_snapshot (
    account_id,
    recorded_hour,
    native_balance,
    created_at,
    updated_at
)
VALUES (
    :account_id,
    :recorded_hour,
    :native_balance,
    :created_at,
    :updated_at
)
ON DUPLICATE KEY UPDATE
    native_balance = VALUES(native_balance),
    updated_at = VALUES(updated_at)
SQL,
            [
                'account_id' => $accountId,
                'recorded_hour' => $recordedHour,
                'native_balance' => $nativeBalance,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            [
                'account_id' => ParameterType::INTEGER,
            ]
        );

        return true;
    }

    private function refreshAccountRanks(\DateTimeImmutable $now): void
    {
        $timestamp = $now->format('Y-m-d H:i:s');
        $this->connection->executeStatement(
            <<<SQL
UPDATE account_metric am
INNER JOIN (
    SELECT
        account_id,
        CAST(
            (
                LOG10(1 + CAST(native_balance AS DECIMAL(36, 7))) * 0.50 +
                LOG10(1 + total_transactions) * 0.20 +
                LOG10(1 + payments_count) * 0.15 +
                LOG10(1 + trades_count) * 0.15
            ) AS DECIMAL(20, 8)
        ) AS rank_score,
        DENSE_RANK() OVER (
            ORDER BY
                (
                    LOG10(1 + CAST(native_balance AS DECIMAL(36, 7))) * 0.50 +
                    LOG10(1 + total_transactions) * 0.20 +
                    LOG10(1 + payments_count) * 0.15 +
                    LOG10(1 + trades_count) * 0.15
                ) DESC,
                account_id ASC
        ) AS rank_position
    FROM account_metric
) ranked ON ranked.account_id = am.account_id
SET
    am.rank_score = ranked.rank_score,
    am.rank_position = ranked.rank_position,
    am.metric_updated_at = :updated_at
SQL,
            ['updated_at' => $timestamp]
        );
    }

    private function resolveHorizonConnection(string $network): Connection
    {
        $connectionName = match ($network) {
            'mainnet' => 'horizon_mainnet',
            'testnet' => 'horizon_testnet',
            default => 'horizon_testnet',
        };

        return $this->doctrine->getConnection($connectionName);
    }

    private function toSqlDateTime(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
