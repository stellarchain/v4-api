<?php

declare(strict_types=1);

namespace App\Service\Statistics;

use App\Exception\StatisticsUnavailableException;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class NetworkStatisticsReadService implements NetworkStatisticsReadServiceInterface
{
    private const SOURCE_BUCKET_MINUTES = 5;

    private const RANGE_MODIFIERS = [
        '24h' => '-24 hours',
        '7d' => '-7 days',
        '30d' => '-30 days',
    ];

    /**
     * @var array<string,array<string,mixed>>
     */
    private const METRICS = [
        'ledgers' => ['label' => 'Ledgers', 'section' => 'blockchain', 'aggregation' => 'sum', 'format' => 'integer'],
        'transactions' => ['label' => 'Transactions', 'section' => 'blockchain', 'aggregation' => 'sum', 'format' => 'integer'],
        'operations' => ['label' => 'Operations', 'section' => 'blockchain', 'aggregation' => 'sum', 'format' => 'integer'],
        'tps' => ['label' => 'TPS', 'section' => 'blockchain', 'aggregation' => 'avg', 'format' => 'decimal', 'suffix' => 'tx/s'],
        'avg-ledger-sec' => ['label' => 'Ledger close', 'section' => 'blockchain', 'aggregation' => 'avg', 'format' => 'seconds', 'suffix' => 'sec'],
        'trades' => ['label' => 'DEX trades', 'section' => 'dex-payments', 'aggregation' => 'sum', 'format' => 'integer'],
        'dex-vol-xlm' => ['label' => 'DEX volume', 'section' => 'dex-payments', 'aggregation' => 'sum', 'format' => 'xlm', 'suffix' => 'XLM'],
        'xlm-total-pay' => ['label' => 'XLM payments', 'section' => 'dex-payments', 'aggregation' => 'sum', 'format' => 'xlm', 'suffix' => 'XLM'],
        'tx-success' => ['label' => 'Successful tx', 'section' => 'dex-payments', 'aggregation' => 'sum', 'format' => 'integer'],
        'tx-failed' => ['label' => 'Failed tx', 'section' => 'dex-payments', 'aggregation' => 'sum', 'format' => 'integer'],
        'active-addresses' => ['label' => 'Active addresses', 'section' => 'accounts-contracts', 'aggregation' => 'avg', 'format' => 'integer'],
        'accounts-created' => ['label' => 'Accounts created', 'section' => 'accounts-contracts', 'aggregation' => 'sum', 'format' => 'integer'],
        'accounts-merged' => ['label' => 'Accounts merged', 'section' => 'accounts-contracts', 'aggregation' => 'sum', 'format' => 'integer'],
        'contracts' => ['label' => 'Contracts created', 'section' => 'accounts-contracts', 'aggregation' => 'sum', 'format' => 'integer'],
        'invocations' => ['label' => 'Contract invokes', 'section' => 'accounts-contracts', 'aggregation' => 'sum', 'format' => 'integer'],
    ];

    /**
     * @var array<string,array{label:string,description:string}>
     */
    private const SECTIONS = [
        'blockchain' => [
            'label' => 'Blockchain',
            'description' => 'Ledger throughput, transaction volume, and close-time health.',
        ],
        'dex-payments' => [
            'label' => 'DEX & Payments',
            'description' => 'Trading and payment activity observed in the selected historical window.',
        ],
        'accounts-contracts' => [
            'label' => 'Accounts & Contracts',
            'description' => 'Address activity, account lifecycle events, and Soroban contract usage.',
        ],
    ];

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.statistics_connection')]
        private readonly Connection $statisticsConnection,
        private readonly StellarNetworkResolver $networkResolver,
    ) {
    }

    public function read(string $network, string $range, int $bucketMinutes): array
    {
        $normalizedNetwork = $this->networkResolver->normalizeNetwork($network, 'mainnet');
        $networkCode = $this->networkResolver->resolveNetworkCode($normalizedNetwork) ?? 1;
        $sourceBucketMinutes = min($bucketMinutes, self::SOURCE_BUCKET_MINUTES);

        try {
            if (!$this->tableExists('network_metric_point')) {
                throw new StatisticsUnavailableException('Statistics table network_metric_point is not available.');
            }

            $latest = $this->loadLatestBucket($networkCode, $sourceBucketMinutes);
            if ($latest === null) {
                return $this->emptyPayload($normalizedNetwork, $range, $bucketMinutes);
            }

            $latestBucket = $latest['bucketStart'];
            $requestedStart = $this->floorToBucket(
                $latestBucket->modify(self::RANGE_MODIFIERS[$range]),
                $bucketMinutes
            );
            $sourceRows = $this->loadMetricRows($networkCode, $sourceBucketMinutes, $requestedStart, $latestBucket);
            $rows = $this->aggregateRowsToBucketMinutes($sourceRows, $sourceBucketMinutes, $bucketMinutes);
        } catch (StatisticsUnavailableException $exception) {
            throw $exception;
        } catch (Exception $exception) {
            throw new StatisticsUnavailableException('Statistics database is unavailable.', 0, $exception);
        }

        if ($rows === []) {
            return $this->emptyPayload($normalizedNetwork, $range, $bucketMinutes);
        }

        $seriesByMetric = $this->buildSeriesByMetric($rows);
        $coverage = $this->buildCoverage($rows, $requestedStart, $latestBucket, $latest['latestUpdate'], $bucketMinutes);

        return [
            'network' => $normalizedNetwork,
            'range' => $range,
            'bucketMinutes' => $bucketMinutes,
            'coverage' => $coverage,
            'sections' => $this->buildSections($seriesByMetric),
            'chart' => $this->buildChart($seriesByMetric),
        ];
    }

    private function tableExists(string $table): bool
    {
        return (int) $this->statisticsConnection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = :table',
            ['table' => $table]
        ) > 0;
    }

    /**
     * @return array{bucketStart:\DateTimeImmutable,latestUpdate:?\DateTimeImmutable}|null
     */
    private function loadLatestBucket(int $networkCode, int $bucketMinutes): ?array
    {
        $row = $this->statisticsConnection->fetchAssociative(
            'SELECT MAX(bucket_start) AS latest_bucket, MAX(updated_at) AS latest_update
             FROM network_metric_point
             WHERE network = :network AND bucket_minutes = :bucket_minutes',
            [
                'network' => $networkCode,
                'bucket_minutes' => $bucketMinutes,
            ],
            [
                'network' => ParameterType::INTEGER,
                'bucket_minutes' => ParameterType::INTEGER,
            ]
        );

        if (!is_array($row)) {
            return null;
        }

        $bucketStart = $this->parseUtcDateTime($row['latest_bucket'] ?? null);
        if ($bucketStart === null) {
            return null;
        }

        return [
            'bucketStart' => $bucketStart,
            'latestUpdate' => $this->parseUtcDateTime($row['latest_update'] ?? null),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadMetricRows(
        int $networkCode,
        int $bucketMinutes,
        \DateTimeImmutable $rangeStart,
        \DateTimeImmutable $rangeEnd
    ): array {
        return $this->statisticsConnection->fetchAllAssociative(
            'SELECT metric_key, bucket_start, bucket_end, value_decimal, updated_at
             FROM network_metric_point
             WHERE network = :network
               AND bucket_minutes = :bucket_minutes
               AND metric_key IN (:metric_keys)
               AND bucket_start >= :range_start
               AND bucket_start <= :range_end
             ORDER BY bucket_start ASC, metric_key ASC',
            [
                'network' => $networkCode,
                'bucket_minutes' => $bucketMinutes,
                'metric_keys' => array_keys(self::METRICS),
                'range_start' => $rangeStart->format('Y-m-d H:i:s'),
                'range_end' => $rangeEnd->format('Y-m-d H:i:s'),
            ],
            [
                'network' => ParameterType::INTEGER,
                'bucket_minutes' => ParameterType::INTEGER,
                'metric_keys' => ArrayParameterType::STRING,
            ]
        );
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function aggregateRowsToBucketMinutes(array $rows, int $sourceBucketMinutes, int $targetBucketMinutes): array
    {
        if ($targetBucketMinutes <= $sourceBucketMinutes || $rows === []) {
            return $rows;
        }

        $buckets = [];
        foreach ($rows as $row) {
            $metricKey = (string) ($row['metric_key'] ?? '');
            if (!isset(self::METRICS[$metricKey])) {
                continue;
            }

            $bucketStart = $this->parseUtcDateTime($row['bucket_start'] ?? null);
            if ($bucketStart === null) {
                continue;
            }

            $targetStart = $this->floorToBucket($bucketStart, $targetBucketMinutes);
            $targetEnd = $targetStart->modify(sprintf('+%d minutes', $targetBucketMinutes));
            $aggregateKey = sprintf('%s|%s', $targetStart->format('Y-m-d H:i:s'), $metricKey);

            if (!isset($buckets[$aggregateKey])) {
                $buckets[$aggregateKey] = [
                    'metric_key' => $metricKey,
                    'bucket_start' => $targetStart->format('Y-m-d H:i:s'),
                    'bucket_end' => $targetEnd->format('Y-m-d H:i:s'),
                    'sum' => 0.0,
                    'count' => 0,
                    'updated_at' => null,
                ];
            }

            $buckets[$aggregateKey]['sum'] += (float) (string) ($row['value_decimal'] ?? '0');
            $buckets[$aggregateKey]['count']++;

            $updatedAt = $this->parseUtcDateTime($row['updated_at'] ?? null);
            $currentUpdatedAt = $this->parseUtcDateTime($buckets[$aggregateKey]['updated_at']);
            if ($updatedAt !== null && ($currentUpdatedAt === null || $updatedAt > $currentUpdatedAt)) {
                $buckets[$aggregateKey]['updated_at'] = $updatedAt->format('Y-m-d H:i:s');
            }
        }

        ksort($buckets);

        $aggregatedRows = [];
        foreach ($buckets as $bucket) {
            $metricKey = (string) $bucket['metric_key'];
            $aggregation = (string) (self::METRICS[$metricKey]['aggregation'] ?? 'sum');
            $value = $aggregation === 'avg'
                ? ((float) $bucket['sum'] / max((int) $bucket['count'], 1))
                : (float) $bucket['sum'];

            $aggregatedRows[] = [
                'metric_key' => $metricKey,
                'bucket_start' => $bucket['bucket_start'],
                'bucket_end' => $bucket['bucket_end'],
                'value_decimal' => $this->normalizeNumber($value),
                'updated_at' => $bucket['updated_at'],
            ];
        }

        return $aggregatedRows;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,list<array{bucketStart:\DateTimeImmutable,bucketEnd:\DateTimeImmutable,value:float,updatedAt:?\DateTimeImmutable}>>
     */
    private function buildSeriesByMetric(array $rows): array
    {
        $series = [];
        foreach ($rows as $row) {
            $metricKey = (string) ($row['metric_key'] ?? '');
            if (!isset(self::METRICS[$metricKey])) {
                continue;
            }

            $bucketStart = $this->parseUtcDateTime($row['bucket_start'] ?? null);
            $bucketEnd = $this->parseUtcDateTime($row['bucket_end'] ?? null);
            if ($bucketStart === null || $bucketEnd === null) {
                continue;
            }

            $series[$metricKey][] = [
                'bucketStart' => $bucketStart,
                'bucketEnd' => $bucketEnd,
                'value' => (float) (string) ($row['value_decimal'] ?? '0'),
                'updatedAt' => $this->parseUtcDateTime($row['updated_at'] ?? null),
            ];
        }

        return $series;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function buildCoverage(
        array $rows,
        \DateTimeImmutable $requestedStart,
        \DateTimeImmutable $latestBucket,
        ?\DateTimeImmutable $latestUpdate,
        int $bucketMinutes
    ): array {
        $firstBucket = null;
        $lastBucket = null;
        $maxUpdatedAt = $latestUpdate;
        $bucketKeys = [];

        foreach ($rows as $row) {
            $bucketStart = $this->parseUtcDateTime($row['bucket_start'] ?? null);
            if ($bucketStart === null) {
                continue;
            }
            $bucketKey = $bucketStart->format('Y-m-d H:i:s');
            $bucketKeys[$bucketKey] = true;

            if ($firstBucket === null || $bucketStart < $firstBucket) {
                $firstBucket = $bucketStart;
            }
            if ($lastBucket === null || $bucketStart > $lastBucket) {
                $lastBucket = $bucketStart;
            }

            $updatedAt = $this->parseUtcDateTime($row['updated_at'] ?? null);
            if ($updatedAt !== null && ($maxUpdatedAt === null || $updatedAt > $maxUpdatedAt)) {
                $maxUpdatedAt = $updatedAt;
            }
        }

        $partialThreshold = $requestedStart->modify(sprintf('+%d minutes', $bucketMinutes));
        $isPartial = $firstBucket !== null && $firstBucket > $partialThreshold;

        return [
            'requestedStart' => $this->formatAtom($requestedStart),
            'requestedEnd' => $this->formatAtom($latestBucket),
            'firstBucket' => $this->formatAtom($firstBucket),
            'lastBucket' => $this->formatAtom($lastBucket),
            'latestUpdate' => $this->formatAtom($maxUpdatedAt),
            'isPartial' => $isPartial,
            'bucketCount' => count($bucketKeys),
        ];
    }

    /**
     * @param array<string,list<array{bucketStart:\DateTimeImmutable,bucketEnd:\DateTimeImmutable,value:float,updatedAt:?\DateTimeImmutable}>> $seriesByMetric
     * @return list<array<string,mixed>>
     */
    private function buildSections(array $seriesByMetric): array
    {
        $sections = [];
        foreach (self::SECTIONS as $sectionId => $sectionConfig) {
            $cards = [];
            foreach (self::METRICS as $metricKey => $metricConfig) {
                if (($metricConfig['section'] ?? '') !== $sectionId) {
                    continue;
                }
                $cards[] = $this->buildCard($metricKey, $metricConfig, $seriesByMetric[$metricKey] ?? []);
            }

            $sections[] = [
                'id' => $sectionId,
                'label' => $sectionConfig['label'],
                'description' => $sectionConfig['description'],
                'cards' => $cards,
            ];
        }

        return $sections;
    }

    /**
     * @param array<string,mixed> $metricConfig
     * @param list<array{bucketStart:\DateTimeImmutable,bucketEnd:\DateTimeImmutable,value:float,updatedAt:?\DateTimeImmutable}> $series
     * @return array<string,mixed>
     */
    private function buildCard(string $metricKey, array $metricConfig, array $series): array
    {
        $aggregation = (string) ($metricConfig['aggregation'] ?? 'latest');
        $value = $this->aggregateSeries($series, $aggregation);
        $first = $series[0]['value'] ?? null;
        $last = $series !== [] ? $series[array_key_last($series)]['value'] : null;

        return [
            'metricKey' => $metricKey,
            'label' => (string) $metricConfig['label'],
            'value' => $this->roundMetricValue($value),
            'valueDecimal' => $this->normalizeNumber($value),
            'aggregation' => $aggregation,
            'format' => (string) ($metricConfig['format'] ?? 'decimal'),
            'suffix' => $metricConfig['suffix'] ?? null,
            'changePercent' => $this->changePercent($first, $last),
            'sparkline' => $this->buildSparkline($series),
        ];
    }

    /**
     * @param list<array{bucketStart:\DateTimeImmutable,bucketEnd:\DateTimeImmutable,value:float,updatedAt:?\DateTimeImmutable}> $series
     */
    private function aggregateSeries(array $series, string $aggregation): float
    {
        if ($series === []) {
            return 0.0;
        }

        return match ($aggregation) {
            'sum' => array_reduce($series, static fn (float $carry, array $point): float => $carry + $point['value'], 0.0),
            'avg' => array_reduce($series, static fn (float $carry, array $point): float => $carry + $point['value'], 0.0) / count($series),
            default => $series[array_key_last($series)]['value'],
        };
    }

    /**
     * @param list<array{bucketStart:\DateTimeImmutable,bucketEnd:\DateTimeImmutable,value:float,updatedAt:?\DateTimeImmutable}> $series
     * @return list<float>
     */
    private function buildSparkline(array $series): array
    {
        if ($series === []) {
            return [];
        }

        $maxPoints = 48;
        if (count($series) <= $maxPoints) {
            return array_map(fn (array $point): float => $this->roundMetricValue($point['value']), $series);
        }

        $sampled = [];
        $step = (count($series) - 1) / ($maxPoints - 1);
        for ($i = 0; $i < $maxPoints; $i++) {
            $index = (int) round($i * $step);
            $sampled[] = $this->roundMetricValue($series[$index]['value']);
        }

        return $sampled;
    }

    /**
     * @param array<string,list<array{bucketStart:\DateTimeImmutable,bucketEnd:\DateTimeImmutable,value:float,updatedAt:?\DateTimeImmutable}>> $seriesByMetric
     * @return array<string,mixed>
     */
    private function buildChart(array $seriesByMetric): array
    {
        $chartMetrics = ['transactions', 'operations', 'tps'];
        $points = [];

        foreach ($chartMetrics as $metricKey) {
            foreach ($seriesByMetric[$metricKey] ?? [] as $point) {
                $bucketKey = $point['bucketStart']->format('Y-m-d H:i:s');
                $points[$bucketKey] ??= [
                    'bucketStart' => $this->formatAtom($point['bucketStart']),
                    'bucketEnd' => $this->formatAtom($point['bucketEnd']),
                    'transactions' => 0,
                    'operations' => 0,
                    'tps' => 0,
                ];
                $points[$bucketKey][$metricKey] = $this->roundMetricValue($point['value']);
            }
        }

        ksort($points);

        return [
            'title' => 'Network activity',
            'series' => [
                ['key' => 'transactions', 'label' => 'Transactions', 'type' => 'bar', 'axis' => 'left'],
                ['key' => 'operations', 'label' => 'Operations', 'type' => 'bar', 'axis' => 'left'],
                ['key' => 'tps', 'label' => 'TPS', 'type' => 'line', 'axis' => 'right'],
            ],
            'points' => array_values($points),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyPayload(string $network, string $range, int $bucketMinutes): array
    {
        return [
            'network' => $network,
            'range' => $range,
            'bucketMinutes' => $bucketMinutes,
            'coverage' => [
                'requestedStart' => null,
                'requestedEnd' => null,
                'firstBucket' => null,
                'lastBucket' => null,
                'latestUpdate' => null,
                'isPartial' => true,
                'bucketCount' => 0,
            ],
            'sections' => $this->buildSections([]),
            'chart' => [
                'title' => 'Network activity',
                'series' => [
                    ['key' => 'transactions', 'label' => 'Transactions', 'type' => 'bar', 'axis' => 'left'],
                    ['key' => 'operations', 'label' => 'Operations', 'type' => 'bar', 'axis' => 'left'],
                    ['key' => 'tps', 'label' => 'TPS', 'type' => 'line', 'axis' => 'right'],
                ],
                'points' => [],
            ],
        ];
    }

    private function changePercent(?float $first, ?float $last): ?float
    {
        if ($first === null || $last === null || abs($first) < 0.0000000001) {
            return null;
        }

        return round((($last - $first) / abs($first)) * 100, 2);
    }

    private function roundMetricValue(float $value): float
    {
        return round($value, 6);
    }

    private function normalizeNumber(float $value): string
    {
        $normalized = number_format($value, 14, '.', '');
        $normalized = rtrim($normalized, '0');

        return rtrim($normalized, '.') ?: '0';
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

        try {
            return (new \DateTimeImmutable(trim($value), new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function floorToBucket(\DateTimeImmutable $dateTime, int $bucketMinutes): \DateTimeImmutable
    {
        $bucketSeconds = max($bucketMinutes, 1) * 60;
        $timestamp = intdiv($dateTime->getTimestamp(), $bucketSeconds) * $bucketSeconds;

        return (new \DateTimeImmutable(sprintf('@%d', $timestamp)))->setTimezone(new \DateTimeZone('UTC'));
    }

    private function formatAtom(?\DateTimeImmutable $dateTime): ?string
    {
        return $dateTime?->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
    }
}
