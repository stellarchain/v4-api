<?php

declare(strict_types=1);

namespace App\Service\Statistics;

use App\Service\Stellar\HorizonAssetSupplyCalculator;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class AssetMarketHistorySyncService
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.statistics_connection')]
        private readonly Connection $statisticsConnection,
        private readonly ManagerRegistry $doctrine,
        private readonly StellarNetworkResolver $networkResolver,
        private readonly HorizonAssetSupplyCalculator $assetSupplyCalculator,
    ) {
    }

    /**
     * @return array{
     *   network:string,
     *   start_ledger:int,
     *   end_ledger:int,
     *   bucket_minutes:int,
     *   market_points:int,
     *   asset_state_snapshots:int,
     *   rows_written:int,
     *   dry_run:bool
     * }
     */
    public function sync(
        string $network,
        int $startLedger,
        int $endLedger,
        int $bucketMinutes = 5,
        bool $dryRun = false
    ): array {
        if ($startLedger < 1 || $endLedger < 1) {
            throw new \InvalidArgumentException('Start and end ledger must be positive integers.');
        }
        if ($bucketMinutes < 1) {
            throw new \InvalidArgumentException('Bucket minutes must be >= 1.');
        }
        if ($startLedger > $endLedger) {
            [$startLedger, $endLedger] = [$endLedger, $startLedger];
        }

        $normalizedNetwork = $this->networkResolver->normalizeNetwork($network, 'testnet');
        $networkCode = $this->networkResolver->resolveNetworkCode($normalizedNetwork) ?? 1;
        $horizonConnection = $this->resolveHorizonConnection($normalizedNetwork);
        $ledgerColumn = $this->resolveTransactionLedgerColumn($horizonConnection);

        $window = $this->loadLedgerWindow($horizonConnection, $startLedger, $endLedger);
        if ($window === null) {
            return [
                'network' => $normalizedNetwork,
                'start_ledger' => $startLedger,
                'end_ledger' => $endLedger,
                'bucket_minutes' => $bucketMinutes,
                'market_points' => 0,
                'asset_state_snapshots' => 0,
                'rows_written' => 0,
                'dry_run' => $dryRun,
            ];
        }

        $nativeAssetId = $this->loadNativeAssetId($horizonConnection);
        if ($nativeAssetId === null) {
            return [
                'network' => $normalizedNetwork,
                'start_ledger' => $window['start_ledger'],
                'end_ledger' => $window['end_ledger'],
                'bucket_minutes' => $bucketMinutes,
                'market_points' => 0,
                'asset_state_snapshots' => 0,
                'rows_written' => 0,
                'dry_run' => $dryRun,
            ];
        }

        $marketRows = $this->loadMarketRows(
            $horizonConnection,
            $networkCode,
            $nativeAssetId,
            $bucketMinutes,
            $window['min_closed_at'],
            $window['max_closed_at']
        );
        $stateRows = $this->loadAssetStateRows(
            $horizonConnection,
            $networkCode,
            $nativeAssetId,
            $window['start_ledger'],
            $window['end_ledger'],
            $ledgerColumn,
            $window['min_closed_at'],
            $window['max_closed_at']
        );

        $rowsWritten = 0;
        if (!$dryRun) {
            if ($marketRows !== []) {
                $rowsWritten += $this->upsertMarketRows($marketRows);
            }
            if ($stateRows !== []) {
                $rowsWritten += $this->upsertStateRows($stateRows);
            }
        }

        return [
            'network' => $normalizedNetwork,
            'start_ledger' => $window['start_ledger'],
            'end_ledger' => $window['end_ledger'],
            'bucket_minutes' => $bucketMinutes,
            'market_points' => count($marketRows),
            'asset_state_snapshots' => count($stateRows),
            'rows_written' => $dryRun ? 0 : $rowsWritten,
            'dry_run' => $dryRun,
        ];
    }

    /**
     * @return array{start_ledger:int,end_ledger:int,min_closed_at:string,max_closed_at:string}|null
     */
    private function loadLedgerWindow(Connection $connection, int $startLedger, int $endLedger): ?array
    {
        $row = $connection->fetchAssociative(
            <<<SQL
SELECT
    MIN(sequence) AS start_ledger,
    MAX(sequence) AS end_ledger,
    MIN(closed_at) AS min_closed_at,
    MAX(closed_at) AS max_closed_at
FROM history_ledgers
WHERE sequence BETWEEN :start_ledger AND :end_ledger
SQL,
            [
                'start_ledger' => $startLedger,
                'end_ledger' => $endLedger,
            ],
            [
                'start_ledger' => ParameterType::INTEGER,
                'end_ledger' => ParameterType::INTEGER,
            ]
        );

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

    private function loadNativeAssetId(Connection $connection): ?int
    {
        if (!$this->tableExists($connection, 'history_assets')) {
            return null;
        }

        return $this->toInt($connection->fetchOne(
            "SELECT id FROM history_assets WHERE asset_type = 'native' ORDER BY id ASC LIMIT 1"
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadMarketRows(
        Connection $connection,
        int $networkCode,
        int $nativeAssetId,
        int $bucketMinutes,
        string $minClosedAt,
        string $maxClosedAt
    ): array {
        if (!$this->tableExists($connection, 'history_trades_60000')) {
            return [];
        }

        $bucketSeconds = $bucketMinutes * 60;
        $fromMs = (string) ((new \DateTimeImmutable($minClosedAt, new \DateTimeZone('UTC')))->getTimestamp() * 1000);
        $toMs = (string) ((new \DateTimeImmutable($maxClosedAt, new \DateTimeZone('UTC')))->getTimestamp() * 1000);

        $rows = $connection->fetchAllAssociative(
            <<<SQL
WITH trade_rows AS (
    SELECT
        to_timestamp(floor((t.timestamp / 1000) / :bucket_seconds) * :bucket_seconds) AS bucket_start,
        CASE WHEN t.base_asset_id = :native_asset_id THEN counter_asset.asset_type ELSE base_asset.asset_type END AS asset_type,
        CASE WHEN t.base_asset_id = :native_asset_id THEN counter_asset.asset_code ELSE base_asset.asset_code END AS asset_code,
        CASE WHEN t.base_asset_id = :native_asset_id THEN counter_asset.asset_issuer ELSE base_asset.asset_issuer END AS asset_issuer,
        t.timestamp,
        COALESCE(t.count, 0) AS trades_count,
        CASE WHEN t.base_asset_id = :native_asset_id THEN COALESCE(t.base_volume, 0) ELSE COALESCE(t.counter_volume, 0) END AS volume_xlm_raw,
        CASE WHEN t.base_asset_id = :native_asset_id THEN COALESCE(t.counter_volume, 0) ELSE COALESCE(t.base_volume, 0) END AS volume_asset_raw,
        CASE
            WHEN t.close_n IS NULL OR t.close_d IS NULL OR t.close_n = 0 OR t.close_d = 0 THEN NULL
            WHEN t.base_asset_id = :native_asset_id THEN CAST(t.close_d AS NUMERIC) / NULLIF(CAST(t.close_n AS NUMERIC), 0)
            ELSE CAST(t.close_n AS NUMERIC) / NULLIF(CAST(t.close_d AS NUMERIC), 0)
        END AS price_xlm
    FROM history_trades_60000 t
    INNER JOIN history_assets base_asset ON base_asset.id = t.base_asset_id
    INNER JOIN history_assets counter_asset ON counter_asset.id = t.counter_asset_id
    WHERE t.timestamp BETWEEN :from_ms AND :to_ms
      AND (t.base_asset_id = :native_asset_id OR t.counter_asset_id = :native_asset_id)
      AND t.base_asset_id <> t.counter_asset_id
)
SELECT
    bucket_start,
    asset_type,
    asset_code,
    asset_issuer,
    COALESCE(SUM(trades_count), 0) AS trades_count,
    COALESCE(SUM(volume_xlm_raw), 0) AS volume_xlm_raw,
    COALESCE(SUM(volume_asset_raw), 0) AS volume_asset_raw,
    (ARRAY_AGG(price_xlm ORDER BY timestamp ASC) FILTER (WHERE price_xlm IS NOT NULL))[1] AS open_price_xlm,
    MAX(price_xlm) AS high_price_xlm,
    MIN(price_xlm) AS low_price_xlm,
    (ARRAY_AGG(price_xlm ORDER BY timestamp DESC) FILTER (WHERE price_xlm IS NOT NULL))[1] AS close_price_xlm,
    to_timestamp(MIN(timestamp) / 1000) AS first_trade_at,
    to_timestamp(MAX(timestamp) / 1000) AS last_trade_at
FROM trade_rows
WHERE asset_type <> 'native'
  AND asset_code IS NOT NULL
  AND asset_code <> ''
  AND asset_issuer IS NOT NULL
  AND asset_issuer <> ''
GROUP BY bucket_start, asset_type, asset_code, asset_issuer
ORDER BY bucket_start ASC, trades_count DESC
SQL,
            [
                'bucket_seconds' => $bucketSeconds,
                'native_asset_id' => $nativeAssetId,
                'from_ms' => $fromMs,
                'to_ms' => $toMs,
            ],
            [
                'bucket_seconds' => ParameterType::INTEGER,
                'native_asset_id' => ParameterType::INTEGER,
                'from_ms' => ParameterType::STRING,
                'to_ms' => ParameterType::STRING,
            ]
        );

        $result = [];
        foreach ($rows as $row) {
            $bucketStart = $this->formatUtcDateTime($row['bucket_start'] ?? null);
            if ($bucketStart === null) {
                continue;
            }

            $result[] = [
                'network' => $networkCode,
                'bucket_minutes' => $bucketMinutes,
                'bucket_start' => $bucketStart,
                'bucket_end' => (new \DateTimeImmutable($bucketStart, new \DateTimeZone('UTC')))
                    ->modify(sprintf('+%d minutes', $bucketMinutes))
                    ->format('Y-m-d H:i:s'),
                'asset_type' => $this->normalizeString($row['asset_type'] ?? null, 32),
                'asset_code' => $this->normalizeString($row['asset_code'] ?? null, 32),
                'asset_issuer' => $this->normalizeString($row['asset_issuer'] ?? null, 64),
                'trades_count' => $this->toInt($row['trades_count'] ?? null) ?? 0,
                'volume_xlm' => $this->normalizeStroopsToDecimal($row['volume_xlm_raw'] ?? '0'),
                'volume_asset' => $this->normalizeStroopsToDecimal($row['volume_asset_raw'] ?? '0'),
                'open_price_xlm' => $this->normalizeNumericString($row['open_price_xlm'] ?? null),
                'high_price_xlm' => $this->normalizeNumericString($row['high_price_xlm'] ?? null),
                'low_price_xlm' => $this->normalizeNumericString($row['low_price_xlm'] ?? null),
                'close_price_xlm' => $this->normalizeNumericString($row['close_price_xlm'] ?? null),
                'first_trade_at' => $this->formatUtcDateTime($row['first_trade_at'] ?? null),
                'last_trade_at' => $this->formatUtcDateTime($row['last_trade_at'] ?? null),
            ];
        }

        return $result;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadAssetStateRows(
        Connection $connection,
        int $networkCode,
        int $nativeAssetId,
        int $startLedger,
        int $endLedger,
        string $ledgerColumn,
        string $minClosedAt,
        string $snapshotAt
    ): array {
        if (!$this->tableExists($connection, 'exp_asset_stats')) {
            return [];
        }

        $fromMs = (string) ((new \DateTimeImmutable($minClosedAt, new \DateTimeZone('UTC')))->getTimestamp() * 1000);
        $toMs = (string) ((new \DateTimeImmutable($snapshotAt, new \DateTimeZone('UTC')))->getTimestamp() * 1000);
        $snapshotAtSql = $this->formatUtcDateTime($snapshotAt);
        if ($snapshotAtSql === null) {
            return [];
        }

        $rows = $connection->fetchAllAssociative(
            <<<SQL
WITH active_assets AS (
    SELECT DISTINCT
        CASE WHEN t.base_asset_id = :native_asset_id THEN counter_asset.asset_type ELSE base_asset.asset_type END AS asset_type,
        CASE WHEN t.base_asset_id = :native_asset_id THEN counter_asset.asset_code ELSE base_asset.asset_code END AS asset_code,
        CASE WHEN t.base_asset_id = :native_asset_id THEN counter_asset.asset_issuer ELSE base_asset.asset_issuer END AS asset_issuer
    FROM history_trades_60000 t
    INNER JOIN history_assets base_asset ON base_asset.id = t.base_asset_id
    INNER JOIN history_assets counter_asset ON counter_asset.id = t.counter_asset_id
    WHERE (t.base_asset_id = :native_asset_id OR t.counter_asset_id = :native_asset_id)
      AND t.timestamp BETWEEN :from_ms AND :to_ms

    UNION

    SELECT DISTINCT
        ho.details->>'asset_type' AS asset_type,
        ho.details->>'asset_code' AS asset_code,
        ho.details->>'asset_issuer' AS asset_issuer
    FROM history_operations ho
    INNER JOIN history_transactions ht ON ht.id = ho.transaction_id
    WHERE ht.{$ledgerColumn} BETWEEN :start_ledger AND :end_ledger
      AND ho.type IN (1, 2, 13)

    UNION

    SELECT DISTINCT
        ho.details->>'source_asset_type' AS asset_type,
        ho.details->>'source_asset_code' AS asset_code,
        ho.details->>'source_asset_issuer' AS asset_issuer
    FROM history_operations ho
    INNER JOIN history_transactions ht ON ht.id = ho.transaction_id
    WHERE ht.{$ledgerColumn} BETWEEN :start_ledger AND :end_ledger
      AND ho.type IN (2, 13)
)
SELECT
    aa.asset_type,
    aa.asset_code,
    aa.asset_issuer,
    eas.accounts,
    eas.balances,
    cas.stat AS contracts
FROM active_assets aa
LEFT JOIN exp_asset_stats eas
  ON eas.asset_code = aa.asset_code
 AND eas.asset_issuer = aa.asset_issuer
 AND (
    (aa.asset_type = 'credit_alphanum4' AND eas.asset_type = 1)
 OR (aa.asset_type = 'credit_alphanum12' AND eas.asset_type = 2)
 )
LEFT JOIN asset_contracts ac
  ON ac.asset_type = eas.asset_type
 AND ac.asset_code = eas.asset_code
 AND ac.asset_issuer = eas.asset_issuer
LEFT JOIN contract_asset_stats cas ON cas.contract_id = ac.contract_id
WHERE aa.asset_type IN ('credit_alphanum4', 'credit_alphanum12')
  AND aa.asset_code IS NOT NULL
  AND aa.asset_code <> ''
  AND aa.asset_issuer IS NOT NULL
  AND aa.asset_issuer <> ''
ORDER BY aa.asset_code ASC, aa.asset_issuer ASC
SQL,
            [
                'native_asset_id' => $nativeAssetId,
                'from_ms' => $fromMs,
                'to_ms' => $toMs,
                'start_ledger' => $startLedger,
                'end_ledger' => $endLedger,
            ],
            [
                'native_asset_id' => ParameterType::INTEGER,
                'from_ms' => ParameterType::STRING,
                'to_ms' => ParameterType::STRING,
                'start_ledger' => ParameterType::INTEGER,
                'end_ledger' => ParameterType::INTEGER,
            ]
        );

        $result = [];
        foreach ($rows as $row) {
            $accounts = $this->decodeJsonObject($row['accounts'] ?? null);
            $balances = $this->decodeJsonObject($row['balances'] ?? null);
            $contracts = $this->decodeJsonObject($row['contracts'] ?? null);
            $authorized = $this->toInt($accounts['authorized'] ?? null, 0) ?? 0;
            $maintain = $this->toInt($accounts['authorized_to_maintain_liabilities'] ?? null, 0) ?? 0;
            $unauthorized = $this->toInt($accounts['unauthorized'] ?? null, 0) ?? 0;

            $result[] = [
                'network' => $networkCode,
                'range_start_ledger' => $startLedger,
                'range_end_ledger' => $endLedger,
                'snapshot_at' => $snapshotAtSql,
                'asset_type' => $this->normalizeString($row['asset_type'] ?? null, 32),
                'asset_code' => $this->normalizeString($row['asset_code'] ?? null, 32),
                'asset_issuer' => $this->normalizeString($row['asset_issuer'] ?? null, 64),
                'trustlines_authorized' => $authorized,
                'trustlines_authorized_to_maintain_liabilities' => $maintain,
                'trustlines_unauthorized' => $unauthorized,
                'trustlines_total' => $authorized + $maintain + $unauthorized,
                'supply' => $this->assetSupplyCalculator->calculateStroops(
                    $balances,
                    $contracts['balance'] ?? null
                ),
            ];
        }

        return $result;
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function upsertMarketRows(array $rows): int
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $sql = $this->buildMarketUpsertSql();
        $written = 0;

        $this->statisticsConnection->beginTransaction();
        try {
            foreach ($rows as $row) {
                $params = $row;
                $params['created_at'] = $now;
                $params['updated_at'] = $now;
                $this->statisticsConnection->executeStatement(
                    $sql,
                    $params,
                    [
                        'network' => ParameterType::INTEGER,
                        'bucket_minutes' => ParameterType::INTEGER,
                        'trades_count' => ParameterType::INTEGER,
                    ]
                );
                $written++;
            }

            $this->statisticsConnection->commit();
        } catch (\Throwable $exception) {
            $this->statisticsConnection->rollBack();
            throw $exception;
        }

        return $written;
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function upsertStateRows(array $rows): int
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $sql = $this->buildStateUpsertSql();
        $written = 0;

        $this->statisticsConnection->beginTransaction();
        try {
            foreach ($rows as $row) {
                $params = $row;
                $params['created_at'] = $now;
                $params['updated_at'] = $now;
                $this->statisticsConnection->executeStatement(
                    $sql,
                    $params,
                    [
                        'network' => ParameterType::INTEGER,
                        'range_start_ledger' => ParameterType::INTEGER,
                        'range_end_ledger' => ParameterType::INTEGER,
                        'trustlines_authorized' => ParameterType::INTEGER,
                        'trustlines_authorized_to_maintain_liabilities' => ParameterType::INTEGER,
                        'trustlines_unauthorized' => ParameterType::INTEGER,
                        'trustlines_total' => ParameterType::INTEGER,
                    ]
                );
                $written++;
            }

            $this->statisticsConnection->commit();
        } catch (\Throwable $exception) {
            $this->statisticsConnection->rollBack();
            throw $exception;
        }

        return $written;
    }

    private function buildMarketUpsertSql(): string
    {
        $platform = $this->statisticsConnection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            return <<<SQL
INSERT INTO asset_market_metric_point
    (network, bucket_minutes, bucket_start, bucket_end, asset_type, asset_code, asset_issuer, trades_count, volume_xlm, volume_asset, open_price_xlm, high_price_xlm, low_price_xlm, close_price_xlm, first_trade_at, last_trade_at, created_at, updated_at)
VALUES
    (:network, :bucket_minutes, :bucket_start, :bucket_end, :asset_type, :asset_code, :asset_issuer, :trades_count, :volume_xlm, :volume_asset, :open_price_xlm, :high_price_xlm, :low_price_xlm, :close_price_xlm, :first_trade_at, :last_trade_at, :created_at, :updated_at)
ON DUPLICATE KEY UPDATE
    bucket_end = VALUES(bucket_end),
    trades_count = VALUES(trades_count),
    volume_xlm = VALUES(volume_xlm),
    volume_asset = VALUES(volume_asset),
    open_price_xlm = VALUES(open_price_xlm),
    high_price_xlm = VALUES(high_price_xlm),
    low_price_xlm = VALUES(low_price_xlm),
    close_price_xlm = VALUES(close_price_xlm),
    first_trade_at = VALUES(first_trade_at),
    last_trade_at = VALUES(last_trade_at),
    updated_at = VALUES(updated_at)
SQL;
        }

        if ($platform instanceof PostgreSQLPlatform) {
            return <<<SQL
INSERT INTO asset_market_metric_point
    (network, bucket_minutes, bucket_start, bucket_end, asset_type, asset_code, asset_issuer, trades_count, volume_xlm, volume_asset, open_price_xlm, high_price_xlm, low_price_xlm, close_price_xlm, first_trade_at, last_trade_at, created_at, updated_at)
VALUES
    (:network, :bucket_minutes, :bucket_start, :bucket_end, :asset_type, :asset_code, :asset_issuer, :trades_count, :volume_xlm, :volume_asset, :open_price_xlm, :high_price_xlm, :low_price_xlm, :close_price_xlm, :first_trade_at, :last_trade_at, :created_at, :updated_at)
ON CONFLICT (network, bucket_minutes, bucket_start, asset_type, asset_code, asset_issuer) DO UPDATE SET
    bucket_end = EXCLUDED.bucket_end,
    trades_count = EXCLUDED.trades_count,
    volume_xlm = EXCLUDED.volume_xlm,
    volume_asset = EXCLUDED.volume_asset,
    open_price_xlm = EXCLUDED.open_price_xlm,
    high_price_xlm = EXCLUDED.high_price_xlm,
    low_price_xlm = EXCLUDED.low_price_xlm,
    close_price_xlm = EXCLUDED.close_price_xlm,
    first_trade_at = EXCLUDED.first_trade_at,
    last_trade_at = EXCLUDED.last_trade_at,
    updated_at = EXCLUDED.updated_at
SQL;
        }

        throw new \RuntimeException(sprintf('Unsupported statistics database platform: %s', $platform::class));
    }

    private function buildStateUpsertSql(): string
    {
        $platform = $this->statisticsConnection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            return <<<SQL
INSERT INTO asset_state_snapshot
    (network, range_start_ledger, range_end_ledger, snapshot_at, asset_type, asset_code, asset_issuer, trustlines_authorized, trustlines_authorized_to_maintain_liabilities, trustlines_unauthorized, trustlines_total, supply, created_at, updated_at)
VALUES
    (:network, :range_start_ledger, :range_end_ledger, :snapshot_at, :asset_type, :asset_code, :asset_issuer, :trustlines_authorized, :trustlines_authorized_to_maintain_liabilities, :trustlines_unauthorized, :trustlines_total, :supply, :created_at, :updated_at)
ON DUPLICATE KEY UPDATE
    snapshot_at = VALUES(snapshot_at),
    trustlines_authorized = VALUES(trustlines_authorized),
    trustlines_authorized_to_maintain_liabilities = VALUES(trustlines_authorized_to_maintain_liabilities),
    trustlines_unauthorized = VALUES(trustlines_unauthorized),
    trustlines_total = VALUES(trustlines_total),
    supply = VALUES(supply),
    updated_at = VALUES(updated_at)
SQL;
        }

        if ($platform instanceof PostgreSQLPlatform) {
            return <<<SQL
INSERT INTO asset_state_snapshot
    (network, range_start_ledger, range_end_ledger, snapshot_at, asset_type, asset_code, asset_issuer, trustlines_authorized, trustlines_authorized_to_maintain_liabilities, trustlines_unauthorized, trustlines_total, supply, created_at, updated_at)
VALUES
    (:network, :range_start_ledger, :range_end_ledger, :snapshot_at, :asset_type, :asset_code, :asset_issuer, :trustlines_authorized, :trustlines_authorized_to_maintain_liabilities, :trustlines_unauthorized, :trustlines_total, :supply, :created_at, :updated_at)
ON CONFLICT (network, range_start_ledger, range_end_ledger, asset_type, asset_code, asset_issuer) DO UPDATE SET
    snapshot_at = EXCLUDED.snapshot_at,
    trustlines_authorized = EXCLUDED.trustlines_authorized,
    trustlines_authorized_to_maintain_liabilities = EXCLUDED.trustlines_authorized_to_maintain_liabilities,
    trustlines_unauthorized = EXCLUDED.trustlines_unauthorized,
    trustlines_total = EXCLUDED.trustlines_total,
    supply = EXCLUDED.supply,
    updated_at = EXCLUDED.updated_at
SQL;
        }

        throw new \RuntimeException(sprintf('Unsupported statistics database platform: %s', $platform::class));
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

    private function resolveTransactionLedgerColumn(Connection $connection): string
    {
        $rows = $connection->fetchFirstColumn(
            "SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'history_transactions'"
        );
        $columns = array_flip(array_map(static fn (mixed $value): string => strtolower((string) $value), $rows));

        if (isset($columns['ledger_sequence'])) {
            return 'ledger_sequence';
        }
        if (isset($columns['ledger_seq'])) {
            return 'ledger_seq';
        }

        throw new \RuntimeException('history_transactions does not expose ledger_sequence or ledger_seq.');
    }

    private function tableExists(Connection $connection, string $table): bool
    {
        return (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = :table',
            ['table' => $table]
        ) > 0;
    }

    private function formatUtcDateTime(mixed $value): ?string
    {
        try {
            if ($value instanceof \DateTimeImmutable) {
                return $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
            if ($value instanceof \DateTimeInterface) {
                return \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
            if (!is_string($value) || trim($value) === '') {
                return null;
            }

            return (new \DateTimeImmutable(trim($value), new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeString(mixed $value, int $maxLength): string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? '' : substr($normalized, 0, $maxLength);
    }

    private function normalizeNumericString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $normalized = trim((string) $value);
        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        return $normalized;
    }

    private function normalizeStroopsToDecimal(mixed $raw): string
    {
        $normalized = $this->normalizeNumericString($raw) ?? '0';
        if (function_exists('bcdiv')) {
            return rtrim(rtrim(bcdiv($normalized, '10000000', 14), '0'), '.') ?: '0';
        }

        return rtrim(rtrim(number_format(((float) $normalized) / 10000000, 14, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
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
}
