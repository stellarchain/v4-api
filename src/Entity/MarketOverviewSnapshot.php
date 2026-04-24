<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter as OpenApiParameter;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'market_overview_snapshot')]
#[ORM\UniqueConstraint(name: 'uniq_market_overview_snapshot_network', columns: ['network'])]
#[ApiResource(
    cacheHeaders: [
        'max_age' => 300,
        'shared_max_age' => 300,
        'vary' => ['Accept', 'Content-Type', 'Origin'],
    ],
    operations: [
        new GetCollection(
            uriTemplate: '/market/overview',
            openapi: new OpenApiOperation(
                tags: ['Market'],
                summary: 'Get latest market overview snapshot per network.',
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
            paginationEnabled: false
        ),
    ],
    order: ['recordedAt' => 'DESC', 'id' => 'DESC']
)]
#[ApiFilter(OrderFilter::class, properties: ['recordedAt'])]
final class MarketOverviewSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(options: ['default' => 1])]
    private int $network = 1;

    #[ORM\Column(type: 'decimal', precision: 20, scale: 10, nullable: true)]
    private ?string $xlmPriceUsd = null;

    #[ORM\Column(type: 'decimal', precision: 30, scale: 7, nullable: true)]
    private ?string $xlmVolume24h = null;

    #[ORM\Column(type: 'bigint')]
    private string $totalTrades24h = '0';

    #[ORM\Column]
    private int $activeAssets24h = 0;

    #[ORM\Column]
    private int $trackedAssets = 0;

    #[ORM\Column]
    private int $totalAccounts = 0;

    #[ORM\Column]
    private int $totalContracts = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $recordedAt;

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

    public function getXlmPriceUsd(): ?string
    {
        return $this->xlmPriceUsd;
    }

    public function setXlmPriceUsd(?string $xlmPriceUsd): self
    {
        $this->xlmPriceUsd = $xlmPriceUsd;

        return $this;
    }

    public function getXlmVolume24h(): ?string
    {
        return $this->xlmVolume24h;
    }

    public function setXlmVolume24h(?string $xlmVolume24h): self
    {
        $this->xlmVolume24h = $xlmVolume24h;

        return $this;
    }

    public function getTotalTrades24h(): string
    {
        return $this->totalTrades24h;
    }

    public function setTotalTrades24h(string $totalTrades24h): self
    {
        $this->totalTrades24h = $totalTrades24h;

        return $this;
    }

    public function getActiveAssets24h(): int
    {
        return $this->activeAssets24h;
    }

    public function setActiveAssets24h(int $activeAssets24h): self
    {
        $this->activeAssets24h = $activeAssets24h;

        return $this;
    }

    public function getTrackedAssets(): int
    {
        return $this->trackedAssets;
    }

    public function setTrackedAssets(int $trackedAssets): self
    {
        $this->trackedAssets = $trackedAssets;

        return $this;
    }

    public function getTotalAccounts(): int
    {
        return $this->totalAccounts;
    }

    public function setTotalAccounts(int $totalAccounts): self
    {
        $this->totalAccounts = $totalAccounts;

        return $this;
    }

    public function getTotalContracts(): int
    {
        return $this->totalContracts;
    }

    public function setTotalContracts(int $totalContracts): self
    {
        $this->totalContracts = $totalContracts;

        return $this;
    }

    public function getRecordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function setRecordedAt(\DateTimeImmutable $recordedAt): self
    {
        $this->recordedAt = $recordedAt;

        return $this;
    }
}
