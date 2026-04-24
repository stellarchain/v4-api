<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter as OpenApiParameter;
use App\Repository\AssetStatisticRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: AssetStatisticRepository::class)]
#[ORM\Table(name: 'asset_statistic')]
#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/assets/{assetKey}/statistics',
            uriVariables: [
                'assetKey' => new Link(fromClass: Asset::class, identifiers: ['assetKey'], toProperty: 'asset'),
            ],
            openapi: new OpenApiOperation(
                tags: ['Asset'],
                summary: 'Get asset statistics history',
                parameters: [
                    new OpenApiParameter(
                        name: 'network',
                        in: 'query',
                        description: 'Filter by network (mainnet|testnet|futurenet). Default: mainnet.',
                        required: false,
                        schema: ['type' => 'string', 'enum' => ['mainnet', 'testnet', 'futurenet']]
                    ),
                ]
            ),
            paginationEnabled: true,
            paginationItemsPerPage: 50,
            order: ['recordedAt' => 'DESC'],
        ),
    ],
)]
class AssetStatistic
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'statisticHistory')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Asset $asset;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 8, nullable: true)]
    #[Groups(['asset:read'])]
    private ?string $price = null;

    #[ORM\Column(type: 'decimal', precision: 36, scale: 0, nullable: true)]
    #[Groups(['asset:read'])]
    private ?string $supply = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    #[Groups(['asset:read'])]
    private ?int $trades = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    #[Groups(['asset:read'])]
    private ?int $tradedAmount = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    #[Groups(['asset:read'])]
    private ?int $payments = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    #[Groups(['asset:read'])]
    private ?int $paymentsAmount = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['asset:read'])]
    private ?int $trustlinesTotal = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['asset:read'])]
    private ?int $trustlinesAuthorized = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['asset:read'])]
    private ?int $trustlinesFunded = null;

    #[ORM\Column(type: 'decimal', precision: 8, scale: 2, nullable: true)]
    #[Groups(['asset:read'])]
    private ?string $ratingAverage = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['asset:read'])]
    private \DateTimeImmutable $recordedAt;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAsset(): Asset
    {
        return $this->asset;
    }

    public function setAsset(Asset $asset): static
    {
        $this->asset = $asset;

        return $this;
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function setPrice(?string $price): static
    {
        $this->price = $price;

        return $this;
    }

    public function getSupply(): ?string
    {
        return $this->supply;
    }

    public function setSupply(?string $supply): static
    {
        $this->supply = $supply;

        return $this;
    }

    public function getTrades(): ?int
    {
        return $this->trades;
    }

    public function setTrades(?int $trades): static
    {
        $this->trades = $trades;

        return $this;
    }

    public function getTradedAmount(): ?int
    {
        return $this->tradedAmount;
    }

    public function setTradedAmount(?int $tradedAmount): static
    {
        $this->tradedAmount = $tradedAmount;

        return $this;
    }

    public function getPayments(): ?int
    {
        return $this->payments;
    }

    public function setPayments(?int $payments): static
    {
        $this->payments = $payments;

        return $this;
    }

    public function getPaymentsAmount(): ?int
    {
        return $this->paymentsAmount;
    }

    public function setPaymentsAmount(?int $paymentsAmount): static
    {
        $this->paymentsAmount = $paymentsAmount;

        return $this;
    }

    public function getTrustlinesTotal(): ?int
    {
        return $this->trustlinesTotal;
    }

    public function setTrustlinesTotal(?int $trustlinesTotal): static
    {
        $this->trustlinesTotal = $trustlinesTotal;

        return $this;
    }

    public function getTrustlinesAuthorized(): ?int
    {
        return $this->trustlinesAuthorized;
    }

    public function setTrustlinesAuthorized(?int $trustlinesAuthorized): static
    {
        $this->trustlinesAuthorized = $trustlinesAuthorized;

        return $this;
    }

    public function getTrustlinesFunded(): ?int
    {
        return $this->trustlinesFunded;
    }

    public function setTrustlinesFunded(?int $trustlinesFunded): static
    {
        $this->trustlinesFunded = $trustlinesFunded;

        return $this;
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

    public function getRecordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function setRecordedAt(\DateTimeImmutable $recordedAt): static
    {
        $this->recordedAt = $recordedAt;

        return $this;
    }
}
