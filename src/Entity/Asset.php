<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter as OpenApiParameter;
use App\DataProvider\AssetItemDataProvider;
use App\Repository\AssetRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\Mapping as ORM;
use App\Entity\AssetMetricHistory;
use App\Entity\AssetStatistic;

#[ORM\Entity(repositoryClass: AssetRepository::class)]
#[ApiResource(
    cacheHeaders: [
        'max_age' => 60,
        'shared_max_age' => 60,
        'vary' => ['Accept', 'Content-Type', 'Origin'],
    ],
    operations: [
        new Get(
            uriTemplate: '/assets/{assetKey}',
            provider: AssetItemDataProvider::class,
            openapi: new OpenApiOperation(
                tags: ['Asset'],
                parameters: [
                    new OpenApiParameter(
                        name: 'network',
                        in: 'query',
                        description: 'Network to query (mainnet|testnet|futurenet). Default: mainnet.',
                        required: false,
                        schema: ['type' => 'string', 'enum' => ['mainnet', 'testnet', 'futurenet']]
                    ),
                ]
            )
        ),
        new GetCollection(
            openapi: new OpenApiOperation(
                tags: ['Asset'],
                parameters: [
                    new OpenApiParameter(
                        name: 'network',
                        in: 'query',
                        description: 'Filter by network (mainnet|testnet|futurenet). Default: mainnet.',
                        required: false,
                        schema: ['type' => 'string', 'enum' => ['mainnet', 'testnet', 'futurenet']]
                    ),
                    new OpenApiParameter(
                        name: 'assetKey',
                        in: 'query',
                        description: 'Filter by exact asset key (e.g. XLM-native).',
                        required: false,
                        schema: ['type' => 'string']
                    ),
                    new OpenApiParameter(
                        name: 'code',
                        in: 'query',
                        description: 'Filter by asset code (partial match).',
                        required: false,
                        schema: ['type' => 'string']
                    ),
                    new OpenApiParameter(
                        name: 'issuer',
                        in: 'query',
                        description: 'Filter by exact asset issuer.',
                        required: false,
                        schema: ['type' => 'string']
                    ),
                    new OpenApiParameter(
                        name: 'order[ratingAverage]',
                        in: 'query',
                        description: 'Sort by rating average (asc|desc).',
                        required: false,
                        schema: ['type' => 'string', 'enum' => ['asc', 'desc']]
                    ),
                ]
            )
        ),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: ['assetKey' => 'exact', 'code' => 'partial', 'issuer' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['ratingAverage'])]
#[ORM\Table(name: 'asset', uniqueConstraints: [new ORM\UniqueConstraint(name: 'uniq_asset_key_network', columns: ['asset_key', 'network'])])]
class Asset
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[ApiProperty(identifier: false)]
    private ?int $id = null;

    #[ORM\Column(name: 'asset_key', length: 128)]
    #[ApiProperty(identifier: true)]
    private string $assetKey;

    #[ORM\Column(options: ['default' => 1])]
    private int $network = 1;

    #[ORM\Column(length: 32)]
    private string $code;

    #[ORM\Column(length: 56, nullable: true)]
    private ?string $issuer = null;

    #[ORM\Column(name: 'is_native')]
    private bool $isNative = false;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'rating_average', type: 'decimal', precision: 8, scale: 2, nullable: true)]
    private ?string $ratingAverage = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $tomlInfo = null;

    #[ApiProperty(readable: false, writable: false)]
    #[ORM\OneToMany(mappedBy: 'asset', targetEntity: AssetMetricHistory::class, cascade: ['persist'], fetch: 'LAZY')]
    private Collection $metricHistory;

    #[ApiProperty(readable: false, writable: false)]
    #[ORM\OneToMany(mappedBy: 'asset', targetEntity: AssetStatistic::class, cascade: ['persist'], orphanRemoval: true, fetch: 'EXTRA_LAZY')]
    private Collection $statisticHistory;

    /**
     * @var array<string,mixed>|null
     */
    private ?array $marketData = null;

    public function __construct()
    {
        $this->metricHistory = new ArrayCollection();
        $this->statisticHistory = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAssetKey(): string
    {
        return $this->assetKey;
    }

    public function setAssetKey(string $assetKey): static
    {
        $this->assetKey = $assetKey;

        return $this;
    }

    public function getNetwork(): int
    {
        return $this->network;
    }

    public function setNetwork(int $network): static
    {
        $this->network = $network;

        return $this;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getIssuer(): ?string
    {
        return $this->issuer;
    }

    public function setIssuer(?string $issuer): static
    {
        $this->issuer = $issuer;

        return $this;
    }

    public function isNative(): bool
    {
        return $this->isNative;
    }

    public function setIsNative(bool $isNative): static
    {
        $this->isNative = $isNative;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    #[ApiProperty(readable: true, writable: false, description: 'Raw TOML metadata stored locally')]
    public function getTomlInfo(): ?array
    {
        return $this->tomlInfo;
    }

    public function setTomlInfo(?array $tomlInfo): static
    {
        $this->tomlInfo = $tomlInfo;

        return $this;
    }

    #[ApiProperty(readable: true, writable: false, description: 'Asset image URL from TOML metadata')]
    public function getImageUrl(): ?string
    {
        return $this->pickTomlString(['image', 'logo', 'icon']);
    }

    #[ApiProperty(readable: true, writable: false, description: 'Asset home URL from TOML metadata')]
    public function getHomeUrl(): ?string
    {
        return $this->pickTomlString(['home_url', 'url', 'ORG_URL']);
    }

    #[ApiProperty(readable: true, writable: false, description: 'Asset home domain from TOML metadata')]
    public function getHomeDomain(): ?string
    {
        $domain = $this->pickTomlString(['home_domain', 'homeDomain']);
        if ($domain !== null) {
            return $domain;
        }

        $homeUrl = $this->getHomeUrl();
        if ($homeUrl === null) {
            return null;
        }

        $host = parse_url($homeUrl, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    public function getRatingAverage(): ?string
    {
        return $this->ratingAverage;
    }

    public function setRatingAverage(?string $ratingAverage): static
    {
        $this->ratingAverage = $ratingAverage;

        return $this;
    }

    /** @return Collection<int, AssetMetricHistory> */
    public function getMetricHistory(): Collection
    {
        return $this->metricHistory;
    }

    public function addMetricHistory(AssetMetricHistory $history): static
    {
        if (!$this->metricHistory->contains($history)) {
            $this->metricHistory->add($history);
            $history->setAsset($this);
        }

        return $this;
    }

    public function removeMetricHistory(AssetMetricHistory $history): static
    {
        if ($this->metricHistory->removeElement($history)) {
            if ($history->getAsset() === $this) {
                $history->setAsset(null);
            }
        }

        return $this;
    }

    /** @return Collection<int, AssetStatistic> */
    #[ApiProperty(readable: false, writable: false)]
    public function getStatisticHistory(): Collection
    {
        return $this->statisticHistory;
    }

    #[ApiProperty(readableLink: true)]
    public function getLatestStatistic(): ?AssetStatistic
    {
        $latest = $this->statisticHistory
            ->matching(Criteria::create()->orderBy(['recordedAt' => Criteria::DESC])->setMaxResults(1))
            ->first();

        return $latest instanceof AssetStatistic ? $latest : null;
    }

    public function addStatistic(AssetStatistic $statistic): static
    {
        if (!$this->statisticHistory->contains($statistic)) {
            $this->statisticHistory->add($statistic);
            $statistic->setAsset($this);
        }

        return $this;
    }

    public function removeStatistic(AssetStatistic $statistic): static
    {
        if ($this->statisticHistory->removeElement($statistic)) {
            if ($statistic->getAsset() === $this) {
                $statistic->setAsset(null);
            }
        }

        return $this;
    }

    /**
     * @return array<string,mixed>|null
     */
    #[ApiProperty(readable: true, writable: false, description: 'Latest market snapshot data for this asset (network scoped)')]
    public function getMarket(): ?array
    {
        return $this->marketData;
    }

    /**
     * @param array<string,mixed>|null $marketData
     */
    public function setMarket(?array $marketData): static
    {
        $this->marketData = $marketData;

        return $this;
    }

    /**
     * @param list<string> $keys
     */
    private function pickTomlString(array $keys): ?string
    {
        if (!is_array($this->tomlInfo)) {
            return null;
        }

        foreach ($keys as $key) {
            $value = $this->tomlInfo[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
