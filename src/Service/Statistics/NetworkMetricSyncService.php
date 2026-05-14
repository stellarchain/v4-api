<?php

declare(strict_types=1);

namespace App\Service\Statistics;

use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class NetworkMetricSyncService implements NetworkMetricSyncServiceInterface
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.statistics_connection')]
        private readonly Connection $statisticsConnection,
        private readonly ManagerRegistry $doctrine,
        private readonly StellarNetworkResolver $networkResolver,
        private readonly NetworkMetricCatalog $metricCatalog,
    ) {
    }

    /**
     * @return array{
     *   network:string,
     *   start_ledger:int,
     *   end_ledger:int,
     *   bucket_minutes:int,
     *   buckets:int,
     *   metrics_written:int,
     *   rows_written:int,
     *   dry_run:bool
     * }
     */
    public function sync(
        string $network,
        int $bucketMinutes = 10,
        ?int $startLedger = null,
        ?int $endLedger = null,
        bool $dryRun = false
    ): array {
        $normalizedNetwork = $this->networkResolver->normalizeNetwork($network, 'testnet');
        $networkCode = $this->networkResolver->resolveNetworkCode($normalizedNetwork) ?? 1;
        $horizonConnection = $this->resolveHorizonConnection($normalizedNetwork);

        if ($bucketMinutes < 1) {
            throw new \InvalidArgumentException('Bucket minutes must be >= 1.');
        }

        $ledgerColumns = $this->loadTableColumns($horizonConnection, 'history_ledgers');
        $transactionColumns = $this->loadTableColumns($horizonConnection, 'history_transactions');
        $operationColumns = $this->loadTableColumns($horizonConnection, 'history_operations');

        $this->assertRequiredLedgerColumns($ledgerColumns);
        $transactionLedgerColumn = $this->resolveTransactionLedgerColumn($transactionColumns);

        $window = $this->loadLedgerWindow($horizonConnection, $startLedger, $endLedger);
        if ($window === null) {
            return [
                'network' => $normalizedNetwork,
                'start_ledger' => $startLedger ?? 0,
                'end_ledger' => $endLedger ?? 0,
                'bucket_minutes' => $bucketMinutes,
                'buckets' => 0,
                'metrics_written' => 0,
                'rows_written' => 0,
                'dry_run' => $dryRun,
            ];
        }

        $bucketSeconds = $bucketMinutes * 60;
        $points = [];

        $ledgerRows = $this->loadLedgerMetricRows(
            $horizonConnection,
            $window['start_ledger'],
            $window['end_ledger'],
            $bucketSeconds,
            $ledgerColumns
        );
        foreach ($ledgerRows as $row) {
            $bucketStart = $this->parseUtcDateTime($row['bucket_start'] ?? null);
            if ($bucketStart === null) {
                continue;
            }

            $ledgers = $this->toInt($row['ledgers'] ?? null, 0);
            $transactions = $this->toInt($row['transactions'] ?? null, 0);
            $operations = $this->toInt($row['operations'] ?? null, 0);
            $avgLedgerSeconds = $this->toFloat($row['avg_ledger_sec'] ?? null) ?? 0.0;

            $this->addPoint($points, $networkCode, $bucketMinutes, $bucketStart, 'ledgers', (string) $ledgers);
            $this->addPoint($points, $networkCode, $bucketMinutes, $bucketStart, 'transactions', (string) $transactions);
            $this->addPoint($points, $networkCode, $bucketMinutes, $bucketStart, 'operations', (string) $operations);
            $this->addPoint($points, $networkCode, $bucketMinutes, $bucketStart, 'tx-success', (string) $this->toInt($row['tx_success'] ?? null, 0));
            $this->addPoint($points, $networkCode, $bucketMinutes, $bucketStart, 'tx-failed', (string) $this->toInt($row['tx_failed'] ?? null, 0));
            $this->addPoint($points, $networkCode, $bucketMinutes, $bucketStart, 'avg-ledger-sec', $this->normalizeDecimal($avgLedgerSeconds));
            $this->addPoint($points, $networkCode, $bucketMinutes, $bucketStart, 'tps', $this->safeDivide($transactions, $avgLedgerSeconds));
            $this->addPoint($points, $networkCode, $bucketMinutes, $bucketStart, 'ops', $this->safeDivide($operations, $avgLedgerSeconds));
            $this->addPoint($points, $networkCode, $bucketMinutes, $bucketStart, 'tx-ledger', $this->safeDivide($transactions, $ledgers));
            $this->addPoint($points, $networkCode, $bucketMinutes, $bucketStart, 'ops-ledger', $this->safeDivide($operations, $ledgers));
        }

        $transactionRows = $this->loadTransactionMetricRows(
            $horizonConnection,
            $window['start_ledger'],
            $window['end_ledger'],
            $bucketSeconds,
            $transactionLedgerColumn,
            $transactionColumns
        );
        foreach ($transactionRows as $row) {
            $bucketStart = $this->parseUtcDateTime($row['bucket_start'] ?? null);
            if ($bucketStart === null) {
                continue;
            }

            $this->addPoint($points, $networkCode, $bucketMinutes, $bucketStart, 'fee-charged', $this->normalizeNumericString($row['fee_charged'] ?? '0'));
            $this->addPoint($points, $networkCode, $bucketMinutes, $bucketStart, 'max-fee', $this->normalizeNumericString($row['max_fee'] ?? '0'));
            if (isset($transactionColumns['account'])) {
                $this->addPoint($points, $networkCode, $bucketMinutes, $bucketStart, 'active-addresses', (string) $this->toInt($row['active_addresses'] ?? null, 0));
            }
        }

        $operationRows = $this->loadOperationMetricRows(
            $horizonConnection,
            $window['start_ledger'],
            $window['end_ledger'],
            $bucketSeconds,
            $transactionLedgerColumn,
            $operationColumns
        );
        foreach ($operationRows as $row) {
            $bucketStart = $this->parseUtcDateTime($row['bucket_start'] ?? null);
            if ($bucketStart === null) {
                continue;
            }

            $this->addPoint($points, $networkCode, $bucketMinutes, $bucketStart, 'output-value', $this->normalizeNumericString($row['output_value'] ?? '0'));
            $this->addPoint($points, $networkCode, $bucketMinutes, $bucketStart, 'xlm-total-pay', $this->normalizeNumericString($row['xlm_total_pay'] ?? '0'));
            $this->addPoint($points, $networkCode, $bucketMinutes, $bucketStart, 'invocations', (string) $this->toInt($row['invocations'] ?? null, 0));
            $this->addPoint($points, $networkCode, $bucketMinutes, $bucketStart, 'contracts', (string) $this->toInt($row['contracts'] ?? null, 0));
            $this->addPoint($points, $networkCode, $bucketMinutes, $bucketStart, 'accounts-created', (string) $this->toInt($row['accounts_created'] ?? null, 0));
            $this->addPoint($points, $networkCode, $bucketMinutes, $bucketStart, 'accounts-merged', (string) $this->toInt($row['accounts_merged'] ?? null, 0));
        }

        $tradeRows = $this->loadTradeMetricRows(
            $horizonConnection,
            $bucketSeconds,
            $window['min_closed_at'],
            $window['max_closed_at']
        );
        foreach ($tradeRows as $row) {
            $bucketStart = $this->parseUtcDateTime($row['bucket_start'] ?? null);
            if ($bucketStart === null) {
                continue;
            }

            $this->addPoint($points, $networkCode, $bucketMinutes, $bucketStart, 'trades', (string) $this->toInt($row['trades'] ?? null, 0));
            $this->addPoint(
                $points,
                $networkCode,
                $bucketMinutes,
                $bucketStart,
                'dex-vol-xlm',
                $this->normalizeStroopsToXlm($row['dex_volume_xlm_raw'] ?? '0')
            );
        }

        $bucketKeys = [];
        foreach ($points as $point) {
            $bucketKeys[$point['bucket_start']] = true;
        }

        $rowsWritten = 0;
        if (!$dryRun && $points !== []) {
            $rowsWritten = $this->upsertPoints($points);
        }

        return [
            'network' => $normalizedNetwork,
            'start_ledger' => $window['start_ledger'],
            'end_ledger' => $window['end_ledger'],
            'bucket_minutes' => $bucketMinutes,
            'buckets' => count($bucketKeys),
            'metrics_written' => count($points),
            'rows_written' => $dryRun ? 0 : $rowsWritten,
            'dry_run' => $dryRun,
        ];
    }

    /**
     * @return array<string,bool>
     */
    private function loadTableColumns(Connection $connection, string $table): array
    {
        $rows = $connection->fetchAllAssociative(
            'SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = :table',
            ['table' => $table]
        );

        $columns = [];
        foreach ($rows as $row) {
            $column = strtolower(trim((string) ($row['column_name'] ?? '')));
            if ($column !== '') {
                $columns[$column] = true;
            }
        }

        return $columns;
    }

    /**
     * @param array<string,bool> $ledgerColumns
     */
    private function assertRequiredLedgerColumns(array $ledgerColumns): void
    {
        foreach (['sequence', 'closed_at', 'transaction_count', 'failed_transaction_count', 'operation_count'] as $requiredColumn) {
            if (!isset($ledgerColumns[$requiredColumn])) {
                throw new \RuntimeException(sprintf('history_ledgers is missing required column: %s', $requiredColumn));
            }
        }
    }

    /**
     * @param array<string,bool> $transactionColumns
     */
    private function resolveTransactionLedgerColumn(array $transactionColumns): string
    {
        if (isset($transactionColumns['ledger_sequence'])) {
            return 'ledger_sequence';
        }
        if (isset($transactionColumns['ledger_seq'])) {
            return 'ledger_seq';
        }

        throw new \RuntimeException('history_transactions does not expose ledger_sequence or ledger_seq.');
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

    /**
     * @return array{
     *   start_ledger:int,
     *   end_ledger:int,
     *   min_closed_at:string,
     *   max_closed_at:string
     * }|null
     */
    private function loadLedgerWindow(Connection $connection, ?int $startLedger, ?int $endLedger): ?array
    {
        $sql = <<<SQL
SELECT
    MIN(sequence) AS start_ledger,
    MAX(sequence) AS end_ledger,
    MIN(closed_at) AS min_closed_at,
    MAX(closed_at) AS max_closed_at
FROM history_ledgers
SQL;

        $params = [];
        $types = [];

        if ($startLedger !== null && $endLedger !== null) {
            $sql .= ' WHERE sequence BETWEEN :start_ledger AND :end_ledger';
            $params['start_ledger'] = min($startLedger, $endLedger);
            $params['end_ledger'] = max($startLedger, $endLedger);
            $types['start_ledger'] = ParameterType::INTEGER;
            $types['end_ledger'] = ParameterType::INTEGER;
        }

        $row = $connection->fetchAssociative($sql, $params, $types);
        if (!is_array($row)) {
            return null;
        }

        $resolvedStart = $this->toInt($row['start_ledger'] ?? null);
        $resolvedEnd = $this->toInt($row['end_ledger'] ?? null);
        $minClosedAt = trim((string) ($row['min_closed_at'] ?? ''));
        $maxClosedAt = trim((string) ($row['max_closed_at'] ?? ''));

        if ($resolvedStart === null || $resolvedEnd === null || $minClosedAt === '' || $maxClosedAt === '') {
            return null;
        }

        return [
            'start_ledger' => $resolvedStart,
            'end_ledger' => $resolvedEnd,
            'min_closed_at' => $minClosedAt,
            'max_closed_at' => $maxClosedAt,
        ];
    }

    /**
     * @param array<string,bool> $ledgerColumns
     * @return list<array<string,mixed>>
     */
    private function loadLedgerMetricRows(
        Connection $connection,
        int $startLedger,
        int $endLedger,
        int $bucketSeconds,
        array $ledgerColumns
    ): array {
        $successExpr = isset($ledgerColumns['successful_transaction_count'])
            ? 'COALESCE(hl.successful_transaction_count, 0)'
            : 'COALESCE(hl.transaction_count, 0)';

        $sql = <<<SQL
WITH ledger_rows AS (
    SELECT
        to_timestamp(floor(extract(epoch FROM hl.closed_at) / :bucket_seconds) * :bucket_seconds) AS bucket_start,
        (COALESCE(hl.transaction_count, 0) + COALESCE(hl.failed_transaction_count, 0)) AS total_transactions,
        {$successExpr} AS successful_transactions,
        COALESCE(hl.failed_transaction_count, 0) AS failed_transactions,
        COALESCE(hl.operation_count, 0) AS operation_count,
        EXTRACT(EPOCH FROM (hl.closed_at - LAG(hl.closed_at) OVER (ORDER BY hl.sequence))) AS ledger_gap_seconds
    FROM history_ledgers hl
    WHERE hl.sequence BETWEEN :start_ledger AND :end_ledger
)
SELECT
    bucket_start,
    COUNT(*) AS ledgers,
    COALESCE(SUM(total_transactions), 0) AS transactions,
    COALESCE(SUM(successful_transactions), 0) AS tx_success,
    COALESCE(SUM(failed_transactions), 0) AS tx_failed,
    COALESCE(SUM(operation_count), 0) AS operations,
    AVG(CASE WHEN ledger_gap_seconds IS NOT NULL AND ledger_gap_seconds >= 0 THEN ledger_gap_seconds END) AS avg_ledger_sec
FROM ledger_rows
GROUP BY bucket_start
ORDER BY bucket_start ASC
SQL;

        return $connection->fetchAllAssociative(
            $sql,
            [
                'bucket_seconds' => $bucketSeconds,
                'start_ledger' => $startLedger,
                'end_ledger' => $endLedger,
            ],
            [
                'bucket_seconds' => ParameterType::INTEGER,
                'start_ledger' => ParameterType::INTEGER,
                'end_ledger' => ParameterType::INTEGER,
            ]
        );
    }

    /**
     * @param array<string,bool> $transactionColumns
     * @return list<array<string,mixed>>
     */
    private function loadTransactionMetricRows(
        Connection $connection,
        int $startLedger,
        int $endLedger,
        int $bucketSeconds,
        string $transactionLedgerColumn,
        array $transactionColumns
    ): array {
        $activeAddressesExpr = isset($transactionColumns['account'])
            ? 'COUNT(DISTINCT ht.account)'
            : '0';

        $sql = <<<SQL
SELECT
    to_timestamp(floor(extract(epoch FROM hl.closed_at) / :bucket_seconds) * :bucket_seconds) AS bucket_start,
    COALESCE(SUM(ht.fee_charged), 0) AS fee_charged,
    COALESCE(SUM(ht.max_fee), 0) AS max_fee,
    {$activeAddressesExpr} AS active_addresses
FROM history_transactions ht
INNER JOIN history_ledgers hl ON hl.sequence = ht.{$transactionLedgerColumn}
WHERE hl.sequence BETWEEN :start_ledger AND :end_ledger
GROUP BY bucket_start
ORDER BY bucket_start ASC
SQL;

        return $connection->fetchAllAssociative(
            $sql,
            [
                'bucket_seconds' => $bucketSeconds,
                'start_ledger' => $startLedger,
                'end_ledger' => $endLedger,
            ],
            [
                'bucket_seconds' => ParameterType::INTEGER,
                'start_ledger' => ParameterType::INTEGER,
                'end_ledger' => ParameterType::INTEGER,
            ]
        );
    }

    /**
     * @param array<string,bool> $operationColumns
     * @return list<array<string,mixed>>
     */
    private function loadOperationMetricRows(
        Connection $connection,
        int $startLedger,
        int $endLedger,
        int $bucketSeconds,
        string $transactionLedgerColumn,
        array $operationColumns
    ): array {
        if (!isset($operationColumns['details'], $operationColumns['type'], $operationColumns['transaction_id'])) {
            return [];
        }

        $amountExpr = <<<SQL
CASE
    WHEN COALESCE(ho.details->>'amount', '') ~ '^-?[0-9]+(\.[0-9]+)?$'
        THEN CAST(ho.details->>'amount' AS NUMERIC(36, 7))
    ELSE 0
END
SQL;

        $sql = <<<SQL
SELECT
    to_timestamp(floor(extract(epoch FROM hl.closed_at) / :bucket_seconds) * :bucket_seconds) AS bucket_start,
    COALESCE(SUM(CASE WHEN ho.type IN (1, 2, 13) THEN {$amountExpr} ELSE 0 END), 0) AS output_value,
    COALESCE(SUM(CASE WHEN ho.type IN (1, 2, 13) AND COALESCE(ho.details->>'asset_type', '') = 'native' THEN {$amountExpr} ELSE 0 END), 0) AS xlm_total_pay,
    COALESCE(SUM(CASE WHEN ho.type = 24 AND CAST(ho.details AS TEXT) ILIKE '%CreateContract%' THEN 1 ELSE 0 END), 0) AS contracts,
    COALESCE(SUM(CASE WHEN ho.type = 24 AND CAST(ho.details AS TEXT) ILIKE '%InvokeContract%' THEN 1 ELSE 0 END), 0) AS invocations,
    COALESCE(SUM(CASE WHEN ho.type = 0 THEN 1 ELSE 0 END), 0) AS accounts_created,
    COALESCE(SUM(CASE WHEN ho.type = 8 THEN 1 ELSE 0 END), 0) AS accounts_merged
FROM history_operations ho
INNER JOIN history_transactions ht ON ht.id = ho.transaction_id
INNER JOIN history_ledgers hl ON hl.sequence = ht.{$transactionLedgerColumn}
WHERE hl.sequence BETWEEN :start_ledger AND :end_ledger
GROUP BY bucket_start
ORDER BY bucket_start ASC
SQL;

        return $connection->fetchAllAssociative(
            $sql,
            [
                'bucket_seconds' => $bucketSeconds,
                'start_ledger' => $startLedger,
                'end_ledger' => $endLedger,
            ],
            [
                'bucket_seconds' => ParameterType::INTEGER,
                'start_ledger' => ParameterType::INTEGER,
                'end_ledger' => ParameterType::INTEGER,
            ]
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadTradeMetricRows(
        Connection $connection,
        int $bucketSeconds,
        string $minClosedAt,
        string $maxClosedAt
    ): array {
        if (!$this->tableExists($connection, 'history_trades_60000') || !$this->tableExists($connection, 'history_assets')) {
            return [];
        }

        $nativeAssetId = $this->toInt($connection->fetchOne(
            "SELECT id FROM history_assets WHERE asset_type = 'native' ORDER BY id ASC LIMIT 1"
        ));
        if ($nativeAssetId === null) {
            return [];
        }

        $from = new \DateTimeImmutable($minClosedAt, new \DateTimeZone('UTC'));
        $to = new \DateTimeImmutable($maxClosedAt, new \DateTimeZone('UTC'));

        $sql = <<<SQL
SELECT
    to_timestamp(floor((timestamp / 1000) / :bucket_seconds) * :bucket_seconds) AS bucket_start,
    COALESCE(SUM(count), 0) AS trades,
    COALESCE(SUM(
        CASE
            WHEN base_asset_id = :native_asset_id THEN base_volume
            WHEN counter_asset_id = :native_asset_id THEN counter_volume
            ELSE 0
        END
    ), 0) AS dex_volume_xlm_raw
FROM history_trades_60000
WHERE timestamp >= :from_ts_ms
  AND timestamp <= :to_ts_ms
  AND (base_asset_id = :native_asset_id OR counter_asset_id = :native_asset_id)
GROUP BY bucket_start
ORDER BY bucket_start ASC
SQL;

        return $connection->fetchAllAssociative(
            $sql,
            [
                'bucket_seconds' => $bucketSeconds,
                'native_asset_id' => $nativeAssetId,
                'from_ts_ms' => (string) ($from->getTimestamp() * 1000),
                'to_ts_ms' => (string) ($to->getTimestamp() * 1000),
            ],
            [
                'bucket_seconds' => ParameterType::INTEGER,
                'native_asset_id' => ParameterType::INTEGER,
                'from_ts_ms' => ParameterType::STRING,
                'to_ts_ms' => ParameterType::STRING,
            ]
        );
    }

    private function tableExists(Connection $connection, string $table): bool
    {
        return (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = :table',
            ['table' => $table]
        ) > 0;
    }

    /**
     * @param array<int,array<string,mixed>> $points
     */
    private function addPoint(
        array &$points,
        int $networkCode,
        int $bucketMinutes,
        \DateTimeImmutable $bucketStart,
        string $metricKey,
        string $valueDecimal,
        string $source = 'horizon_db'
    ): void {
        $points[] = [
            'network' => $networkCode,
            'metric_group' => $this->metricCatalog->groupForMetric($metricKey),
            'metric_key' => $metricKey,
            'source' => $source,
            'bucket_minutes' => $bucketMinutes,
            'bucket_start' => $bucketStart->format('Y-m-d H:i:s'),
            'bucket_end' => $bucketStart->modify(sprintf('+%d minutes', $bucketMinutes))->format('Y-m-d H:i:s'),
            'value_decimal' => $valueDecimal,
        ];
    }

    /**
     * @param list<array<string,mixed>> $points
     */
    private function upsertPoints(array $points): int
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $rowsWritten = 0;
        $sql = $this->buildUpsertSql();

        $this->statisticsConnection->beginTransaction();
        try {
            foreach ($points as $point) {
                $this->statisticsConnection->executeStatement(
                    $sql,
                    [
                        'network' => $point['network'],
                        'metric_group' => $point['metric_group'],
                        'metric_key' => $point['metric_key'],
                        'source' => $point['source'],
                        'bucket_minutes' => $point['bucket_minutes'],
                        'bucket_start' => $point['bucket_start'],
                        'bucket_end' => $point['bucket_end'],
                        'value_decimal' => $point['value_decimal'],
                        'created_at' => $now->format('Y-m-d H:i:s'),
                        'updated_at' => $now->format('Y-m-d H:i:s'),
                    ],
                    [
                        'network' => ParameterType::INTEGER,
                        'bucket_minutes' => ParameterType::INTEGER,
                    ]
                );
                $rowsWritten++;
            }

            $this->statisticsConnection->commit();
        } catch (\Throwable $exception) {
            $this->statisticsConnection->rollBack();
            throw $exception;
        }

        return $rowsWritten;
    }

    private function buildUpsertSql(): string
    {
        $platform = $this->statisticsConnection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            return <<<SQL
INSERT INTO network_metric_point
    (network, metric_group, metric_key, source, bucket_minutes, bucket_start, bucket_end, value_decimal, created_at, updated_at)
VALUES
    (:network, :metric_group, :metric_key, :source, :bucket_minutes, :bucket_start, :bucket_end, :value_decimal, :created_at, :updated_at)
ON DUPLICATE KEY UPDATE
    metric_group = VALUES(metric_group),
    bucket_end = VALUES(bucket_end),
    value_decimal = VALUES(value_decimal),
    updated_at = VALUES(updated_at)
SQL;
        }

        if ($platform instanceof PostgreSQLPlatform) {
            return <<<SQL
INSERT INTO network_metric_point
    (network, metric_group, metric_key, source, bucket_minutes, bucket_start, bucket_end, value_decimal, created_at, updated_at)
VALUES
    (:network, :metric_group, :metric_key, :source, :bucket_minutes, :bucket_start, :bucket_end, :value_decimal, :created_at, :updated_at)
ON CONFLICT (network, source, metric_key, bucket_minutes, bucket_start) DO UPDATE SET
    metric_group = EXCLUDED.metric_group,
    bucket_end = EXCLUDED.bucket_end,
    value_decimal = EXCLUDED.value_decimal,
    updated_at = EXCLUDED.updated_at
SQL;
        }

        throw new \RuntimeException(sprintf(
            'Unsupported statistics database platform: %s',
            $platform::class
        ));
    }

    private function parseUtcDateTime(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value->setTimezone(new \DateTimeZone('UTC'));
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'));
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return new \DateTimeImmutable(trim($value), new \DateTimeZone('UTC'));
    }

    private function toInt(mixed $value, ?int $default = null): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) round($value);
        }
        if (is_string($value) && preg_match('/^-?[0-9]+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return $default;
    }

    private function toFloat(mixed $value): ?float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return is_numeric(trim($value)) ? (float) trim($value) : null;
    }

    private function normalizeDecimal(float $value): string
    {
        $normalized = number_format($value, 14, '.', '');

        return rtrim(rtrim($normalized, '0'), '.') ?: '0';
    }

    private function normalizeNumericString(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return $this->normalizeDecimal((float) $value);
        }
        if (!is_string($value) || trim($value) === '' || !is_numeric(trim($value))) {
            return '0';
        }

        $trimmed = trim($value);
        if (preg_match('/^-?[0-9]+$/', $trimmed) === 1) {
            return $trimmed;
        }

        return $this->normalizeDecimal((float) $trimmed);
    }

    private function normalizeStroopsToXlm(mixed $rawValue): string
    {
        $normalized = $this->normalizeNumericString($rawValue);
        if (function_exists('bcdiv')) {
            $value = bcdiv($normalized, '10000000', 7);

            return rtrim(rtrim($value, '0'), '.') ?: '0';
        }

        return $this->normalizeDecimal(((float) $normalized) / 10000000);
    }

    private function safeDivide(int|float $numerator, int|float $denominator): string
    {
        if ($denominator <= 0) {
            return '0';
        }

        return $this->normalizeDecimal(((float) $numerator) / ((float) $denominator));
    }
}
