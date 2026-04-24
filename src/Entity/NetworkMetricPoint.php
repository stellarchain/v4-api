<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter as OpenApiParameter;
use App\Repository\NetworkMetricPointRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NetworkMetricPointRepository::class)]
#[ORM\Table(name: 'network_metric_point')]
#[ORM\UniqueConstraint(name: 'uniq_network_metric_point_bucket', columns: ['network', 'source', 'metric_key', 'bucket_minutes', 'bucket_start'])]
#[ORM\Index(name: 'idx_network_metric_point_query', columns: ['network', 'metric_key', 'bucket_minutes', 'bucket_start', 'id'])]
#[ORM\Index(name: 'idx_network_metric_point_group', columns: ['network', 'metric_group', 'bucket_minutes', 'bucket_start'])]
#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/network-metrics',
            openapi: new OpenApiOperation(
                tags: ['Statistics'],
                summary: 'Get paginated network metric time-series points.',
                parameters: [
                    new OpenApiParameter(
                        name: 'network',
                        in: 'query',
                        description: 'Filter by network (mainnet|testnet|futurenet). Default: mainnet.',
                        required: false,
                        schema: ['type' => 'string', 'enum' => ['mainnet', 'testnet', 'futurenet']]
                    ),
                    new OpenApiParameter(
                        name: 'metricKey',
                        in: 'query',
                        description: 'Exact metric key filter (example: ledgers, transactions, tps).',
                        required: false,
                        schema: ['type' => 'string']
                    ),
                    new OpenApiParameter(
                        name: 'metricGroup',
                        in: 'query',
                        description: 'Exact metric group filter (market|blockchain|network).',
                        required: false,
                        schema: ['type' => 'string', 'enum' => ['market', 'blockchain', 'network']]
                    ),
                    new OpenApiParameter(
                        name: 'source',
                        in: 'query',
                        description: 'Exact source filter (example: horizon_db, coingecko).',
                        required: false,
                        schema: ['type' => 'string']
                    ),
                    new OpenApiParameter(
                        name: 'bucketMinutes',
                        in: 'query',
                        description: 'Exact bucket size filter in minutes.',
                        required: false,
                        schema: ['type' => 'integer']
                    ),
                ]
            )
        ),
    ],
    order: ['bucketStart' => 'DESC', 'id' => 'DESC'],
    cacheHeaders: [
        'max_age' => 300,
        'shared_max_age' => 300,
        'vary' => ['Accept', 'Content-Type', 'Origin'],
    ],
    paginationEnabled: true,
    paginationItemsPerPage: 100,
    paginationMaximumItemsPerPage: 500,
    paginationClientItemsPerPage: true,
)]
#[ApiFilter(SearchFilter::class, properties: ['metricKey' => 'exact', 'metricGroup' => 'exact', 'source' => 'exact', 'bucketMinutes' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['bucketStart', 'bucketEnd', 'valueDecimal', 'id'])]
#[ApiFilter(DateFilter::class, properties: ['bucketStart', 'bucketEnd'])]
final class NetworkMetricPoint
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(options: ['default' => 1])]
    private int $network = 1;

    #[ORM\Column(name: 'metric_group', length: 32)]
    private string $metricGroup = 'network';

    #[ORM\Column(name: 'metric_key', length: 64)]
    private string $metricKey = '';

    #[ORM\Column(length: 32)]
    private string $source = 'horizon_db';

    #[ORM\Column(name: 'bucket_minutes')]
    private int $bucketMinutes = 10;

    #[ORM\Column(name: 'bucket_start', type: 'datetime_immutable')]
    private \DateTimeImmutable $bucketStart;

    #[ORM\Column(name: 'bucket_end', type: 'datetime_immutable')]
    private \DateTimeImmutable $bucketEnd;

    #[ORM\Column(name: 'value_decimal', type: 'decimal', precision: 36, scale: 14)]
    private string $valueDecimal = '0';

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNetwork(): int
    {
        return $this->network;
    }

    public function setNetwork(int $network): self
    {
        $this->network = $network;

        return $this;
    }

    public function getMetricGroup(): string
    {
        return $this->metricGroup;
    }

    public function setMetricGroup(string $metricGroup): self
    {
        $this->metricGroup = $metricGroup;

        return $this;
    }

    public function getMetricKey(): string
    {
        return $this->metricKey;
    }

    public function setMetricKey(string $metricKey): self
    {
        $this->metricKey = $metricKey;

        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;

        return $this;
    }

    public function getBucketMinutes(): int
    {
        return $this->bucketMinutes;
    }

    public function setBucketMinutes(int $bucketMinutes): self
    {
        $this->bucketMinutes = $bucketMinutes;

        return $this;
    }

    public function getBucketStart(): \DateTimeImmutable
    {
        return $this->bucketStart;
    }

    public function setBucketStart(\DateTimeImmutable $bucketStart): self
    {
        $this->bucketStart = $bucketStart;

        return $this;
    }

    public function getBucketEnd(): \DateTimeImmutable
    {
        return $this->bucketEnd;
    }

    public function setBucketEnd(\DateTimeImmutable $bucketEnd): self
    {
        $this->bucketEnd = $bucketEnd;

        return $this;
    }

    public function getValueDecimal(): string
    {
        return $this->valueDecimal;
    }

    public function setValueDecimal(string $valueDecimal): self
    {
        $this->valueDecimal = $valueDecimal;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
