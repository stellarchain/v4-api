<?php

declare(strict_types=1);

namespace App\Service\Statistics;

use App\Exception\StatisticsUnavailableException;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class NetworkMetricSeriesReadService implements NetworkMetricSeriesReadServiceInterface
{
    private const SOURCE_BUCKET_MINUTES = 5;

    private const AVG_METRICS = [
        'tps',
        'ops',
        'tx-ledger',
        'ops-ledger',
        'avg-ledger-sec',
        'active-addresses',
    ];

    private const MAX_METRICS = [
        'max-fee',
    ];

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.statistics_connection')]
        private readonly Connection $statisticsConnection,
        private readonly StellarNetworkResolver $networkResolver,
        private readonly NetworkMetricCatalog $metricCatalog,
    ) {
    }

    public function read(
        string $network,
        ?string $metricKey,
        int $bucketMinutes,
        int $page,
        int $itemsPerPage
    ): array {
        $normalizedNetwork = $this->networkResolver->normalizeNetwork($network, 'mainnet');
        $networkCode = $this->networkResolver->resolveNetworkCode($normalizedNetwork) ?? 1;
        $metricKey = $metricKey === null ? null : strtolower(trim($metricKey));
        $targetInterval = sprintf('%d minutes', $bucketMinutes);
        $offset = ($page - 1) * $itemsPerPage;

        $params = [
            'network' => $networkCode,
            'source_bucket_minutes' => self::SOURCE_BUCKET_MINUTES,
            'target_interval' => $targetInterval,
        ];
        $types = [
            'network' => ParameterType::INTEGER,
            'source_bucket_minutes' => ParameterType::INTEGER,
            'target_interval' => ParameterType::STRING,
        ];
        $metricFilter = '';
        if ($metricKey !== null) {
            $metricFilter = ' AND metric_key = :metric_key';
            $params['metric_key'] = $metricKey;
            $types['metric_key'] = ParameterType::STRING;
        }

        $groupedSql = $this->groupedSeriesSql($metricFilter);

        try {
            $totalItems = (int) $this->statisticsConnection->fetchOne(
                $groupedSql . ' SELECT COUNT(*) FROM grouped_rows',
                $params,
                $types
            );

            $pageParams = $params + [
                'limit' => $itemsPerPage,
                'offset' => $offset,
            ];
            $pageTypes = $types + [
                'limit' => ParameterType::INTEGER,
                'offset' => ParameterType::INTEGER,
            ];
            $rows = $this->statisticsConnection->fetchAllAssociative(
                $groupedSql . <<<'SQL'
 SELECT
    network,
    metric_group,
    metric_key,
    source,
    bucket_start,
    bucket_start + CAST(:target_interval AS interval) AS bucket_end,
    value_decimal,
    created_at,
    updated_at
FROM grouped_rows
ORDER BY bucket_start DESC, metric_key ASC, source ASC
LIMIT :limit OFFSET :offset
SQL,
                $pageParams,
                $pageTypes
            );
        } catch (Exception $exception) {
            throw new StatisticsUnavailableException('Network metric series are unavailable.', 0, $exception);
        }

        $items = [];
        foreach ($rows as $row) {
            $item = $this->mapRow($row, $networkCode, $bucketMinutes);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return [
            'network' => $normalizedNetwork,
            'networkCode' => $networkCode,
            'metricKey' => $metricKey,
            'bucketMinutes' => $bucketMinutes,
            'page' => $page,
            'itemsPerPage' => $itemsPerPage,
            'totalItems' => $totalItems,
            'items' => $items,
        ];
    }

    private function groupedSeriesSql(string $metricFilter): string
    {
        $averageMetrics = $this->quotedMetricList(self::AVG_METRICS);
        $maximumMetrics = $this->quotedMetricList(self::MAX_METRICS);

        return <<<SQL
WITH source_rows AS (
    SELECT
        network,
        metric_group,
        metric_key,
        source,
        date_bin(
            CAST(:target_interval AS interval),
            bucket_start,
            TIMESTAMP '1970-01-01 00:00:00'
        ) AS grouped_bucket_start,
        value_decimal,
        created_at,
        updated_at
    FROM network_metric_point
    WHERE network = :network
      AND bucket_minutes = :source_bucket_minutes{$metricFilter}
),
grouped_rows AS (
    SELECT
        network,
        metric_group,
        metric_key,
        source,
        grouped_bucket_start AS bucket_start,
        CASE
            WHEN metric_key IN ({$averageMetrics}) THEN AVG(value_decimal)
            WHEN metric_key IN ({$maximumMetrics}) THEN MAX(value_decimal)
            ELSE SUM(value_decimal)
        END AS value_decimal,
        MIN(created_at) AS created_at,
        MAX(updated_at) AS updated_at
    FROM source_rows
    GROUP BY network, metric_group, metric_key, source, grouped_bucket_start
)
SQL;
    }

    /**
     * @param list<string> $metrics
     */
    private function quotedMetricList(array $metrics): string
    {
        return implode(', ', array_map(
            static fn (string $metric): string => "'" . str_replace("'", "''", $metric) . "'",
            $metrics
        ));
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    private function mapRow(array $row, int $networkCode, int $bucketMinutes): ?array
    {
        $metricKey = trim((string) ($row['metric_key'] ?? ''));
        $source = trim((string) ($row['source'] ?? ''));
        $bucketStart = $this->parseUtcDateTime($row['bucket_start'] ?? null);
        $bucketEnd = $this->parseUtcDateTime($row['bucket_end'] ?? null);
        if ($metricKey === '' || $bucketStart === null || $bucketEnd === null) {
            return null;
        }

        $identity = implode(':', [
            (string) $networkCode,
            $metricKey,
            $source,
            (string) $bucketMinutes,
            $bucketStart->format('YmdHis'),
        ]);
        $id = (int) hexdec(substr(hash('sha256', $identity), 0, 15));

        return [
            '@id' => sprintf('/v1/network_metric_points/%d', $id),
            '@type' => 'NetworkMetricPoint',
            'id' => $id,
            'network' => $networkCode,
            'metricGroup' => trim((string) ($row['metric_group'] ?? ''))
                ?: $this->metricCatalog->groupForMetric($metricKey),
            'metricKey' => $metricKey,
            'source' => $source,
            'bucketMinutes' => $bucketMinutes,
            'bucketStart' => $bucketStart->format(\DateTimeInterface::ATOM),
            'bucketEnd' => $bucketEnd->format(\DateTimeInterface::ATOM),
            'valueDecimal' => (string) ($row['value_decimal'] ?? '0'),
            'createdAt' => $this->parseUtcDateTime($row['created_at'] ?? null)?->format(\DateTimeInterface::ATOM),
            'updatedAt' => $this->parseUtcDateTime($row['updated_at'] ?? null)?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function parseUtcDateTime(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'));
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable(trim($value), new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
