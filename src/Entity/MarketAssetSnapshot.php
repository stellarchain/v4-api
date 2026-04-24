<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\ExistsFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter as OpenApiParameter;
use App\Repository\MarketAssetSnapshotRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MarketAssetSnapshotRepository::class)]
#[ORM\Table(name: 'market_asset_snapshot')]
#[ORM\UniqueConstraint(name: 'uniq_market_asset_snapshot_asset', columns: ['asset_id'])]
#[ORM\Index(name: 'idx_market_asset_snapshot_network_rank', columns: ['network', 'rank_position', 'id'])]
#[ORM\Index(name: 'idx_market_asset_snapshot_network_updated', columns: ['network', 'updated_at'])]
#[ApiResource(
    order: ['rankPosition' => 'ASC', 'id' => 'ASC'],
    cacheHeaders: [
        'max_age' => 300,
        'shared_max_age' => 300,
        'vary' => ['Accept', 'Content-Type', 'Origin'],
    ],
    paginationEnabled: true,
    paginationItemsPerPage: 50,
    paginationMaximumItemsPerPage: 200,
    paginationClientItemsPerPage: true,
    operations: [
        new GetCollection(
            uriTemplate: '/market/assets',
            name: 'market_assets_collection',
            openapi: new OpenApiOperation(
                tags: ['Market'],
                parameters: [
                    new OpenApiParameter(
                        name: 'network',
                        in: 'query',
                        description: 'Filter by network (mainnet|testnet|futurenet). Default: mainnet.',
                        required: false,
                        schema: ['type' => 'string', 'enum' => ['mainnet', 'testnet', 'futurenet']]
                    ),
                    new OpenApiParameter(
                        name: 'search',
                        in: 'query',
                        description: 'Single input search across asset code, issuer and TOML documentation.ORG_NAME.',
                        required: false,
                        schema: ['type' => 'string']
                    ),
                    new OpenApiParameter(
                        name: 'q',
                        in: 'query',
                        description: 'Alias for search.',
                        required: false,
                        schema: ['type' => 'string']
                    ),
                    new OpenApiParameter(
                        name: 'code',
                        in: 'query',
                        description: 'Filter by asset code (partial match). Alias for asset.code.',
                        required: false,
                        schema: ['type' => 'string']
                    ),
                    new OpenApiParameter(
                        name: 'issuer',
                        in: 'query',
                        description: 'Filter by exact asset issuer. Alias for asset.issuer.',
                        required: false,
                        schema: ['type' => 'string']
                    ),
                    new OpenApiParameter(
                        name: 'orgName',
                        in: 'query',
                        description: 'Filter by TOML organization name (tomlInfo.documentation.ORG_NAME, partial match).',
                        required: false,
                        schema: ['type' => 'string']
                    ),
                ]
            ),
        ),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: ['asset.assetKey' => 'exact', 'asset.code' => 'partial', 'asset.issuer' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['rankPosition', 'score', 'updatedAt'])]
#[ApiFilter(ExistsFilter::class, properties: ['priceXlm'])]
final class MarketAssetSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Asset $asset;

    #[ORM\Column(options: ['default' => 1])]
    private int $network = 1;

    #[ORM\Column(name: 'rank_position')]
    private int $rankPosition = 0;

    #[ORM\Column(type: 'decimal', precision: 20, scale: 8)]
    private string $score = '0.00000000';

    #[ORM\Column(type: 'decimal', precision: 18, scale: 8, nullable: true)]
    private ?string $priceXlm = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 4, nullable: true)]
    private ?string $priceChange1h = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 4, nullable: true)]
    private ?string $priceChange24h = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 4, nullable: true)]
    private ?string $priceChange7d = null;

    #[ORM\Column(type: 'decimal', precision: 30, scale: 7, nullable: true)]
    private ?string $volumeXlm24h = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $trades24h = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $trustlinesTotal = null;

    #[ORM\Column(type: 'decimal', precision: 36, scale: 0, nullable: true)]
    private ?string $supply = null;

    #[ORM\Column(name: 'sparkline1h', type: 'json', nullable: true)]
    private ?array $sparkline1h = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAsset(): Asset
    {
        return $this->asset;
    }

    public function setAsset(Asset $asset): self
    {
        $this->asset = $asset;

        return $this;
    }

    #[ApiProperty(readable: true, writable: false)]
    public function getAssetKey(): ?string
    {
        return isset($this->asset) ? $this->asset->getAssetKey() : null;
    }

    #[ApiProperty(readable: true, writable: false)]
    public function getCode(): ?string
    {
        return isset($this->asset) ? $this->asset->getCode() : null;
    }

    #[ApiProperty(readable: true, writable: false)]
    public function getIssuer(): ?string
    {
        return isset($this->asset) ? $this->asset->getIssuer() : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    #[ApiProperty(readable: true, writable: false)]
    public function getTomlInfo(): ?array
    {
        return isset($this->asset) ? $this->asset->getTomlInfo() : null;
    }

    #[ApiProperty(readable: true, writable: false)]
    public function getImageUrl(): ?string
    {
        return isset($this->asset) ? $this->asset->getImageUrl() : null;
    }

    #[ApiProperty(readable: true, writable: false)]
    public function getHomeUrl(): ?string
    {
        return isset($this->asset) ? $this->asset->getHomeUrl() : null;
    }

    #[ApiProperty(readable: true, writable: false)]
    public function getHomeDomain(): ?string
    {
        return isset($this->asset) ? $this->asset->getHomeDomain() : null;
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

    public function getRankPosition(): int
    {
        return $this->rankPosition;
    }

    public function setRankPosition(int $rankPosition): self
    {
        $this->rankPosition = $rankPosition;

        return $this;
    }

    public function getScore(): string
    {
        return $this->score;
    }

    public function setScore(string $score): self
    {
        $this->score = $score;

        return $this;
    }

    public function getPriceXlm(): ?string
    {
        return $this->priceXlm;
    }

    public function setPriceXlm(?string $priceXlm): self
    {
        $this->priceXlm = $priceXlm;

        return $this;
    }

    public function getPriceChange1h(): ?string
    {
        return $this->priceChange1h;
    }

    public function setPriceChange1h(?string $priceChange1h): self
    {
        $this->priceChange1h = $priceChange1h;

        return $this;
    }

    public function getPriceChange24h(): ?string
    {
        return $this->priceChange24h;
    }

    public function setPriceChange24h(?string $priceChange24h): self
    {
        $this->priceChange24h = $priceChange24h;

        return $this;
    }

    public function getPriceChange7d(): ?string
    {
        return $this->priceChange7d;
    }

    public function setPriceChange7d(?string $priceChange7d): self
    {
        $this->priceChange7d = $priceChange7d;

        return $this;
    }

    public function getVolumeXlm24h(): ?string
    {
        return $this->volumeXlm24h;
    }

    public function setVolumeXlm24h(?string $volumeXlm24h): self
    {
        $this->volumeXlm24h = $volumeXlm24h;

        return $this;
    }

    public function getTrades24h(): ?int
    {
        return $this->trades24h;
    }

    public function setTrades24h(?int $trades24h): self
    {
        $this->trades24h = $trades24h;

        return $this;
    }

    public function getTrustlinesTotal(): ?int
    {
        return $this->trustlinesTotal;
    }

    public function setTrustlinesTotal(?int $trustlinesTotal): self
    {
        $this->trustlinesTotal = $trustlinesTotal;

        return $this;
    }

    public function getSupply(): ?string
    {
        return $this->supply;
    }

    public function setSupply(?string $supply): self
    {
        $this->supply = $supply;

        return $this;
    }

    /**
     * @return list<string>|null
     */
    public function getSparkline1h(): ?array
    {
        return $this->sparkline1h;
    }

    /**
     * @param list<string>|null $sparkline1h
     */
    public function setSparkline1h(?array $sparkline1h): self
    {
        $this->sparkline1h = $sparkline1h;

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
