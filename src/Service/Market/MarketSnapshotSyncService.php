<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class MarketSnapshotSyncService
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private readonly Connection $localConnection,
        private readonly ManagerRegistry $doctrine,
        private readonly StellarNetworkResolver $networkResolver,
    ) {
    }

    /**
     * @return array{
     *   network:string,
     *   local_assets:int,
     *   horizon_assets:int,
     *   mapped_assets:int,
     *   persisted_assets:int,
     *   rows_written:int,
     *   dry_run:bool
     * }
     */
    public function sync(string $network, bool $dryRun = false, ?int $top = 1000): array
    {
        $normalizedNetwork = $this->networkResolver->normalizeNetwork($network, 'testnet');
        $networkCode = $this->networkResolver->resolveNetworkCode($normalizedNetwork) ?? 1;
        $horizonConnection = $this->resolveHorizonConnection($normalizedNetwork);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $topLimit = ($top !== null && $top > 0) ? $top : null;

        $localAssetsBefore = $this->countLocalAssets($networkCode);
        $horizonNativeAssetId = $this->loadHorizonNativeAssetId($horizonConnection);
        if ($horizonNativeAssetId === null) {
            throw new \RuntimeException('Cannot find native asset in horizon.history_assets.');
        }

        $candidateAssetIds = null;
        if ($topLimit !== null) {
            $candidateLimit = max(2000, $topLimit * 10);
            $candidateAssetIds = $this->loadCandidateAssetIds($horizonConnection, $horizonNativeAssetId, $now, $candidateLimit);
        }

        $horizonAssets = $this->loadHorizonAssets($horizonConnection, $candidateAssetIds);
        if ($horizonAssets === []) {
            return [
                'network' => $normalizedNetwork,
                'local_assets' => $localAssetsBefore,
                'horizon_assets' => 0,
                'mapped_assets' => 0,
                'persisted_assets' => 0,
                'rows_written' => 0,
                'dry_run' => $dryRun,
            ];
        }

        $assetMap = $this->loadHorizonAssetMap($horizonConnection, $candidateAssetIds);
        $expStatsMap = $this->buildExpStatsMap($horizonAssets);
        $tradeMetricsByHorizonAssetId = $this->buildTradeMetrics(
            $horizonConnection,
            array_values($assetMap),
            $horizonNativeAssetId,
            $now
        );

        $records = [];
        foreach ($horizonAssets as $asset) {
            $code = (string) $asset['asset_code'];
            $issuer = (string) $asset['asset_issuer'];
            $mapKey = $this->buildAssetMapKey((string) $asset['asset_type_name'], $code, $issuer);
            $statsKey = $this->buildStatsMapKey((int) $asset['asset_type'], $code, $issuer);
            $horizonAssetId = $assetMap[$mapKey] ?? null;
            $tradeMetrics = is_int($horizonAssetId) ? ($tradeMetricsByHorizonAssetId[$horizonAssetId] ?? []) : [];
            $stats = $expStatsMap[$statsKey] ?? [];

            $trustlinesTotal = $this->readTrustlinesTotal($stats['accounts'] ?? null);
            $supply = $this->readSupply($stats['balances'] ?? null);
            $volume24h = $this->toFloat($tradeMetrics['volume_xlm_24h'] ?? null);
            $trades24h = $this->toInt($tradeMetrics['trades_24h'] ?? null);
            $priceChange24h = $this->toFloat($tradeMetrics['price_change_24h'] ?? null);

            $score = $this->computeScore(
                $volume24h ?? 0.0,
                $trades24h ?? 0,
                $trustlinesTotal ?? 0,
                $priceChange24h ?? 0.0
            );

            $records[] = [
                'code' => $code,
                'issuer' => $issuer,
                'network' => $networkCode,
                'score' => $score,
                'price_xlm' => $this->normalizeDecimal($this->toFloat($tradeMetrics['latest_price_xlm'] ?? null), 8),
                'price_change1h' => $this->normalizeDecimal($this->toFloat($tradeMetrics['price_change_1h'] ?? null), 4),
                'price_change24h' => $this->normalizeDecimal($priceChange24h, 4),
                // Keep schema stable: reuse "7d" field to carry 1d (24h) change for now.
                'price_change7d' => $this->normalizeDecimal($priceChange24h, 4),
                'volume_xlm24h' => $this->normalizeDecimal($volume24h, 7),
                'trades24h' => $trades24h,
                'trustlines_total' => $trustlinesTotal,
                'supply' => $supply,
                'sparkline1h' => $tradeMetrics['sparkline_1h'] ?? null,
            ];
        }

        $nativeStats = $this->loadNativeExpStats($horizonConnection);
        $nativeTrustlines = $this->readTrustlinesTotal($nativeStats['accounts'] ?? null);
        $nativeSupply = $this->readSupply($nativeStats['balances'] ?? null);
        $nativeScore = $this->computeScore(
            0.0,
            0,
            $nativeTrustlines ?? 0,
            0.0
        );
        $records[] = [
            'code' => 'XLM',
            'issuer' => null,
            'network' => $networkCode,
            'score' => $nativeScore,
            'price_xlm' => '1.00000000',
            'price_change1h' => '0.0000',
            'price_change24h' => '0.0000',
            'price_change7d' => '0.0000',
            'volume_xlm24h' => '0.0000000',
            'trades24h' => 0,
            'trustlines_total' => $nativeTrustlines,
            'supply' => $nativeSupply,
            'sparkline1h' => array_fill(0, 10, '1.00000000'),
        ];

        usort($records, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        foreach ($records as $index => $record) {
            $records[$index]['rank_position'] = $index + 1;
            $records[$index]['score'] = number_format((float) $record['score'], 8, '.', '');
        }

        $persistRows = $topLimit !== null ? array_slice($records, 0, $topLimit) : $records;
        if ($topLimit !== null) {
            $nativeRow = null;
            foreach ($records as $record) {
                if (($record['code'] ?? null) === 'XLM' && ($record['issuer'] ?? null) === null) {
                    $nativeRow = $record;
                    break;
                }
            }
            if (is_array($nativeRow)) {
                $hasNativeInPersistRows = false;
                foreach ($persistRows as $row) {
                    if (($row['code'] ?? null) === 'XLM' && ($row['issuer'] ?? null) === null) {
                        $hasNativeInPersistRows = true;
                        break;
                    }
                }
                if (!$hasNativeInPersistRows) {
                    if (count($persistRows) >= $topLimit && $persistRows !== []) {
                        array_pop($persistRows);
                    }
                    $persistRows[] = $nativeRow;
                }
            }
        }
        $rowsWritten = 0;
        if (!$dryRun) {
            $this->localConnection->beginTransaction();
            try {
                foreach ($persistRows as $row) {
                    $assetId = $this->ensureLocalAssetId(
                        (string) $row['code'],
                        is_string($row['issuer'] ?? null) ? (string) $row['issuer'] : null,
                        $networkCode,
                        $now
                    );

                    $this->localConnection->executeStatement(
                        <<<SQL
INSERT INTO market_asset_snapshot
    (asset_id, network, rank_position, score, price_xlm, price_change1h, price_change24h, price_change7d, volume_xlm24h, trades24h, trustlines_total, supply, sparkline1h, updated_at)
VALUES
    (:asset_id, :network, :rank_position, :score, :price_xlm, :price_change1h, :price_change24h, :price_change7d, :volume_xlm24h, :trades24h, :trustlines_total, :supply, :sparkline1h, :updated_at)
ON DUPLICATE KEY UPDATE
    network = VALUES(network),
    rank_position = VALUES(rank_position),
    score = VALUES(score),
    price_xlm = VALUES(price_xlm),
    price_change1h = VALUES(price_change1h),
    price_change24h = VALUES(price_change24h),
    price_change7d = VALUES(price_change7d),
    volume_xlm24h = VALUES(volume_xlm24h),
    trades24h = VALUES(trades24h),
    trustlines_total = VALUES(trustlines_total),
    supply = VALUES(supply),
    sparkline1h = VALUES(sparkline1h),
    updated_at = VALUES(updated_at)
SQL,
                        [
                            'asset_id' => $assetId,
                            'network' => $row['network'],
                            'rank_position' => $row['rank_position'],
                            'score' => $row['score'],
                            'price_xlm' => $row['price_xlm'],
                            'price_change1h' => $row['price_change1h'],
                            'price_change24h' => $row['price_change24h'],
                            'price_change7d' => $row['price_change7d'],
                            'volume_xlm24h' => $row['volume_xlm24h'],
                            'trades24h' => $row['trades24h'],
                            'trustlines_total' => $row['trustlines_total'],
                            'supply' => $row['supply'],
                            'sparkline1h' => $this->encodeJson($row['sparkline1h'] ?? null),
                            'updated_at' => $now->format('Y-m-d H:i:s'),
                        ],
                        [
                            'asset_id' => ParameterType::INTEGER,
                            'network' => ParameterType::INTEGER,
                            'rank_position' => ParameterType::INTEGER,
                            'trades24h' => ParameterType::INTEGER,
                            'trustlines_total' => ParameterType::INTEGER,
                        ]
                    );
                    $rowsWritten++;
                }

                $this->localConnection->executeStatement(
                    'DELETE FROM market_asset_snapshot WHERE network = :network AND updated_at < :updated_at',
                    [
                        'network' => $networkCode,
                        'updated_at' => $now->format('Y-m-d H:i:s'),
                    ],
                    ['network' => ParameterType::INTEGER]
                );

                $this->localConnection->commit();
            } catch (\Throwable $exception) {
                $this->localConnection->rollBack();
                throw $exception;
            }
        }

        return [
            'network' => $normalizedNetwork,
            'local_assets' => $this->countLocalAssets($networkCode),
            'horizon_assets' => count($horizonAssets),
            'mapped_assets' => count($records),
            'persisted_assets' => count($persistRows),
            'rows_written' => $dryRun ? 0 : $rowsWritten,
            'dry_run' => $dryRun,
        ];
    }

    private function countLocalAssets(int $networkCode): int
    {
        return (int) $this->localConnection->fetchOne(
            'SELECT COUNT(*) FROM asset WHERE network = :network AND is_native = 0 AND issuer IS NOT NULL',
            ['network' => $networkCode],
            ['network' => ParameterType::INTEGER]
        );
    }

    /**
     * @return list<array{id:int,code:string,issuer:string}>
     */
    private function loadLocalAssets(int $networkCode): array
    {
        return $this->localConnection->fetchAllAssociative(
            'SELECT id, code, issuer FROM asset WHERE network = :network AND is_native = 0 AND issuer IS NOT NULL ORDER BY id ASC',
            ['network' => $networkCode],
            ['network' => ParameterType::INTEGER]
        );
    }

    /**
     * @return list<array{asset_type:int,asset_type_name:string,asset_code:string,asset_issuer:string,accounts:mixed,balances:mixed}>
     */
    private function loadHorizonAssets(Connection $horizonConnection, ?array $assetIds = null): array
    {
        $sql = <<<SQL
SELECT
    eas.asset_type,
    CASE eas.asset_type
        WHEN 1 THEN 'credit_alphanum4'
        WHEN 2 THEN 'credit_alphanum12'
        ELSE ''
    END AS asset_type_name,
    eas.asset_code,
    eas.asset_issuer,
    eas.accounts,
    eas.balances
FROM exp_asset_stats eas
JOIN history_assets ha
  ON ha.asset_code = eas.asset_code
 AND ha.asset_issuer = eas.asset_issuer
 AND (
    (eas.asset_type = 1 AND ha.asset_type = 'credit_alphanum4')
 OR (eas.asset_type = 2 AND ha.asset_type = 'credit_alphanum12')
 )
WHERE eas.asset_type IN (1, 2)
  AND eas.asset_code <> ''
  AND eas.asset_issuer <> ''
SQL;

        $params = [];
        $types = [];
        if (is_array($assetIds) && $assetIds !== []) {
            $sql .= ' AND ha.id IN (:asset_ids)';
            $params['asset_ids'] = $assetIds;
            $types['asset_ids'] = ArrayParameterType::INTEGER;
        }

        return $horizonConnection->fetchAllAssociative($sql, $params, $types);
    }

    private function loadHorizonNativeAssetId(Connection $horizonConnection): ?int
    {
        $result = $horizonConnection->fetchOne(
            "SELECT id FROM history_assets WHERE asset_type = 'native' ORDER BY id ASC LIMIT 1"
        );

        return $this->toInt($result);
    }

    /**
     * @return array{accounts:mixed,balances:mixed}|null
     */
    private function loadNativeExpStats(Connection $horizonConnection): ?array
    {
        $row = $horizonConnection->fetchAssociative(
            'SELECT accounts, balances FROM exp_asset_stats WHERE asset_type = 0 LIMIT 1'
        );
        if (!is_array($row)) {
            return null;
        }

        return [
            'accounts' => $row['accounts'] ?? null,
            'balances' => $row['balances'] ?? null,
        ];
    }

    /**
     * @return array<string,int>
     */
    private function loadHorizonAssetMap(Connection $horizonConnection, ?array $assetIds = null): array
    {
        $sql = <<<SQL
SELECT id, asset_type, asset_code, asset_issuer
FROM history_assets
WHERE asset_type IN ('credit_alphanum4', 'credit_alphanum12')
SQL;
        $params = [];
        $types = [];
        if (is_array($assetIds) && $assetIds !== []) {
            $sql .= ' AND id IN (:asset_ids)';
            $params['asset_ids'] = $assetIds;
            $types['asset_ids'] = ArrayParameterType::INTEGER;
        }

        $rows = $horizonConnection->fetchAllAssociative($sql, $params, $types);

        $map = [];
        foreach ($rows as $row) {
            $map[$this->buildAssetMapKey((string) $row['asset_type'], (string) $row['asset_code'], (string) $row['asset_issuer'])] = (int) $row['id'];
        }

        return $map;
    }

    /**
     * @return list<int>
     */
    private function loadCandidateAssetIds(Connection $horizonConnection, int $horizonNativeAssetId, \DateTimeImmutable $now, int $limit): array
    {
        $from24h = ($now->getTimestamp() - (24 * 60 * 60)) * 1000;
        $rows = $horizonConnection->fetchAllAssociative(
            <<<SQL
SELECT
    CASE WHEN base_asset_id = :native_asset_id THEN counter_asset_id ELSE base_asset_id END AS asset_id
FROM history_trades_60000
WHERE timestamp >= :from24h
  AND (base_asset_id = :native_asset_id OR counter_asset_id = :native_asset_id)
GROUP BY asset_id
ORDER BY SUM(
    CASE WHEN base_asset_id = :native_asset_id THEN base_volume ELSE counter_volume END
) DESC, SUM(count) DESC
LIMIT :limit
SQL,
            [
                'native_asset_id' => $horizonNativeAssetId,
                'from24h' => $from24h,
                'limit' => $limit,
            ],
            [
                'native_asset_id' => ParameterType::INTEGER,
                'from24h' => ParameterType::STRING,
                'limit' => ParameterType::INTEGER,
            ]
        );

        return array_map(static fn (array $row): int => (int) $row['asset_id'], $rows);
    }

    /**
     * @param list<array{asset_type:int,asset_type_name:string,asset_code:string,asset_issuer:string,accounts:mixed,balances:mixed}> $horizonAssets
     * @return array<string,array{accounts:mixed,balances:mixed}>
     */
    private function buildExpStatsMap(array $horizonAssets): array
    {
        $map = [];
        foreach ($horizonAssets as $row) {
            $map[$this->buildStatsMapKey((int) $row['asset_type'], (string) $row['asset_code'], (string) $row['asset_issuer'])] = [
                'accounts' => $row['accounts'],
                'balances' => $row['balances'],
            ];
        }

        return $map;
    }

    /**
     * @param list<int> $horizonAssetIds
     * @return array<int,array<string,mixed>>
     */
    private function buildTradeMetrics(
        Connection $horizonConnection,
        array $horizonAssetIds,
        int $nativeAssetId,
        \DateTimeImmutable $now
    ): array
    {
        if ($horizonAssetIds === []) {
            return [];
        }

        $nowTsMs = $now->getTimestamp() * 1000;
        $cut1h = ($now->getTimestamp() - 3600) * 1000;
        $cut24h = ($now->getTimestamp() - (24 * 3600)) * 1000;
        // Pull extra history so we can always pick a reference candle at/before the 24h cut.
        $fromReference = ($now->getTimestamp() - (25 * 60 * 60)) * 1000;

        $result = $horizonConnection->executeQuery(
            <<<SQL
SELECT base_asset_id, counter_asset_id, timestamp, count, base_volume, counter_volume, close_n, close_d
FROM history_trades_60000
WHERE timestamp >= :from_reference
  AND (
      (base_asset_id = :native_asset_id AND counter_asset_id IN (:asset_ids))
   OR (counter_asset_id = :native_asset_id AND base_asset_id IN (:asset_ids))
  )
ORDER BY timestamp ASC
SQL,
            [
                'from_reference' => $fromReference,
                'native_asset_id' => $nativeAssetId,
                'asset_ids' => $horizonAssetIds,
            ],
            [
                'from_reference' => ParameterType::STRING,
                'native_asset_id' => ParameterType::INTEGER,
                'asset_ids' => ArrayParameterType::INTEGER,
            ]
        );

        $data = [];
        while (($row = $result->fetchAssociative()) !== false) {
            $baseAssetId = (int) $row['base_asset_id'];
            $counterAssetId = (int) $row['counter_asset_id'];
            $assetId = $baseAssetId === $nativeAssetId ? $counterAssetId : $baseAssetId;
            $isAssetAsBase = $baseAssetId !== $nativeAssetId;

            $closeN = $this->toFloat($row['close_n'] ?? null);
            $closeD = $this->toFloat($row['close_d'] ?? null);
            if ($closeN === null || $closeD === null || $closeN <= 0.0 || $closeD <= 0.0) {
                continue;
            }

            $priceXlm = $isAssetAsBase ? ($closeN / $closeD) : ($closeD / $closeN);
            $volumeXlm = $isAssetAsBase
                ? ($this->toFloat($row['counter_volume'] ?? null) ?? 0.0)
                : ($this->toFloat($row['base_volume'] ?? null) ?? 0.0);
            $ts = (int) $row['timestamp'];
            $count = (int) $row['count'];

            if (!isset($data[$assetId])) {
                $data[$assetId] = [
                    'latest_price_xlm' => null,
                    'latest_ts' => 0,
                    'price_1h' => null,
                    'price_24h' => null,
                    'trades_24h' => 0,
                    'volume_xlm_24h' => 0.0,
                    'sparkline_rows_1h' => [],
                    'price_before_1h' => null,
                ];
            }

            if ($ts >= $cut24h) {
                $data[$assetId]['trades_24h'] += $count;
                $data[$assetId]['volume_xlm_24h'] += $volumeXlm;
            }
            if ($ts >= $cut1h) {
                $data[$assetId]['sparkline_rows_1h'][] = ['ts' => $ts, 'price' => $priceXlm];
            } else {
                $data[$assetId]['price_before_1h'] = $priceXlm;
            }

            if ($ts > (int) $data[$assetId]['latest_ts']) {
                $data[$assetId]['latest_ts'] = $ts;
                $data[$assetId]['latest_price_xlm'] = $priceXlm;
            }

            if ($ts <= $cut1h) {
                $data[$assetId]['price_1h'] = $priceXlm;
            }
            if ($ts <= $cut24h) {
                $data[$assetId]['price_24h'] = $priceXlm;
            }
        }
        $result->free();

        foreach ($data as $assetId => $metrics) {
            $latest = $this->toFloat($metrics['latest_price_xlm']);
            $data[$assetId]['price_change_1h'] = $this->percentChange($latest, $this->toFloat($metrics['price_1h']));
            $data[$assetId]['price_change_24h'] = $this->percentChange($latest, $this->toFloat($metrics['price_24h']));
            $data[$assetId]['sparkline_1h'] = $this->buildSparkline1h(
                is_array($metrics['sparkline_rows_1h'] ?? null) ? $metrics['sparkline_rows_1h'] : [],
                $this->toFloat($metrics['price_before_1h'] ?? null),
                $cut1h,
                $nowTsMs,
                10
            );
            unset($data[$assetId]['sparkline_rows_1h'], $data[$assetId]['price_before_1h']);
        }

        return $data;
    }

    private function computeScore(float $volumeXlm24h, int $trades24h, int $trustlinesTotal, float $priceChange24h): float
    {
        $volumeScore = log10(1.0 + max(0.0, $volumeXlm24h));
        $tradesScore = log10(1.0 + max(0, $trades24h));
        $trustlinesScore = log10(1.0 + max(0, $trustlinesTotal));
        $momentumScore = max(0.0, min(100.0, $priceChange24h)) / 100.0;

        return
            ($volumeScore * 0.50) +
            ($tradesScore * 0.25) +
            ($trustlinesScore * 0.20) +
            ($momentumScore * 0.05);
    }

    private function readTrustlinesTotal(mixed $accountsJson): ?int
    {
        $decoded = $this->decodeJsonObject($accountsJson);
        if ($decoded === null) {
            return null;
        }

        $authorized = (int) ($decoded['authorized'] ?? 0);
        $maintain = (int) ($decoded['authorized_to_maintain_liabilities'] ?? 0);
        $unauthorized = (int) ($decoded['unauthorized'] ?? 0);

        return $authorized + $maintain + $unauthorized;
    }

    private function readSupply(mixed $balancesJson): ?string
    {
        $decoded = $this->decodeJsonObject($balancesJson);
        if ($decoded === null) {
            return null;
        }

        $authorized = (string) ($decoded['authorized'] ?? '0');
        $maintain = (string) ($decoded['authorized_to_maintain_liabilities'] ?? '0');
        $unauthorized = (string) ($decoded['unauthorized'] ?? '0');

        return bcadd(bcadd($authorized, $maintain, 0), $unauthorized, 0);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decodeJsonObject(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function percentChange(?float $latest, ?float $reference): ?float
    {
        if ($latest === null || $reference === null || $reference <= 0.0) {
            return null;
        }

        return (($latest - $reference) / $reference) * 100.0;
    }

    private function toHorizonAssetTypeString(string $code): string
    {
        return mb_strlen($code) <= 4 ? 'credit_alphanum4' : 'credit_alphanum12';
    }

    private function toHorizonAssetTypeInt(string $code): int
    {
        return mb_strlen($code) <= 4 ? 1 : 2;
    }

    private function buildAssetMapKey(string $type, string $code, string $issuer): string
    {
        return sprintf('%s|%s|%s', $type, $code, $issuer);
    }

    private function buildStatsMapKey(int $type, string $code, string $issuer): string
    {
        return sprintf('%d|%s|%s', $type, $code, $issuer);
    }

    private function buildLocalAssetKey(string $code, ?string $issuer): string
    {
        if ($issuer === null || trim($issuer) === '') {
            if (strtoupper($code) === 'XLM') {
                return 'XLM-native';
            }

            return sprintf('%s-native', $code);
        }

        return sprintf('%s-%s', $code, $issuer);
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

    private function ensureLocalAssetId(string $code, ?string $issuer, int $networkCode, \DateTimeImmutable $now): int
    {
        if ($issuer === null || trim($issuer) === '') {
            $id = $this->localConnection->fetchOne(
                'SELECT id FROM asset WHERE network = :network AND code = :code AND is_native = 1 ORDER BY id ASC LIMIT 1',
                [
                    'network' => $networkCode,
                    'code' => $code,
                ],
                ['network' => ParameterType::INTEGER]
            );
        } else {
            $id = $this->localConnection->fetchOne(
                'SELECT id FROM asset WHERE network = :network AND code = :code AND issuer = :issuer ORDER BY id ASC LIMIT 1',
                [
                    'network' => $networkCode,
                    'code' => $code,
                    'issuer' => $issuer,
                ],
                ['network' => ParameterType::INTEGER]
            );
        }
        $resolved = $this->toInt($id);
        if (is_int($resolved)) {
            return $resolved;
        }

        $assetKey = $this->buildLocalAssetKey($code, $issuer);
        $isNative = $issuer === null || trim($issuer) === '';
        $this->localConnection->executeStatement(
            <<<SQL
INSERT INTO asset (asset_key, network, code, issuer, is_native, created_at, updated_at, rating_average, toml_info)
VALUES (:asset_key, :network, :code, :issuer, :is_native, :created_at, :updated_at, NULL, NULL)
SQL,
            [
                'asset_key' => $assetKey,
                'network' => $networkCode,
                'code' => $code,
                'issuer' => $issuer,
                'is_native' => $isNative ? 1 : 0,
                'created_at' => $now->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ],
            [
                'network' => ParameterType::INTEGER,
                'is_native' => ParameterType::INTEGER,
            ]
        );

        return (int) $this->localConnection->lastInsertId();
    }

    private function normalizeDecimal(?float $value, int $scale): ?string
    {
        if ($value === null || !is_finite($value)) {
            return null;
        }

        return number_format($value, $scale, '.', '');
    }

    /**
     * @param list<array{ts:int,price:float}> $rows
     * @return list<string>|null
     */
    private function buildSparkline1h(array $rows, ?float $priceBeforeWindow, int $fromTsMs, int $toTsMs, int $points): ?array
    {
        if ($points < 2 || $toTsMs <= $fromTsMs) {
            return null;
        }

        $result = [];
        $step = ($toTsMs - $fromTsMs) / ($points - 1);
        $cursor = 0;
        $current = $priceBeforeWindow;
        $rowCount = count($rows);

        for ($i = 0; $i < $points; $i++) {
            $targetTs = (int) round($fromTsMs + ($step * $i));
            while ($cursor < $rowCount && (int) $rows[$cursor]['ts'] <= $targetTs) {
                $current = (float) $rows[$cursor]['price'];
                $cursor++;
            }
            if ($current === null && $cursor < $rowCount) {
                $current = (float) $rows[$cursor]['price'];
            }

            $result[] = $this->normalizeDecimal($current, 8) ?? '0.00000000';
        }

        return $result;
    }

    private function encodeJson(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }
        if (!is_string($value)) {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function toInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }
}
