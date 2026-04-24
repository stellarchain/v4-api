<?php

namespace App\Entity;

use App\Repository\AccountRepository;
use Doctrine\ORM\Mapping as ORM;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Common\Filter\OrderFilterInterface;
use App\DataProvider\AccountItemDataProvider;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter as OpenApiParameter;
use Symfony\Component\Serializer\Annotation\SerializedName;

#[ORM\Entity(repositoryClass: AccountRepository::class)]
#[ORM\Table(name: 'account', uniqueConstraints: [new ORM\UniqueConstraint(name: 'uniq_account_address_network', columns: ['address', 'network'])])]
#[ApiResource(
    cacheHeaders: [
        'max_age' => 60,
        'shared_max_age' => 60,
        'vary' => ['Accept', 'Content-Type', 'Origin'],
    ],
    operations: [
        new Get(
            provider: AccountItemDataProvider::class,
            openapi: new OpenApiOperation(
                tags: ['Account'],
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
                tags: ['Account'],
                parameters: [
                    new OpenApiParameter(
                        name: 'network',
                        in: 'query',
                        description: 'Filter by network (mainnet|testnet|futurenet). Default: mainnet.',
                        required: false,
                        schema: ['type' => 'string', 'enum' => ['mainnet', 'testnet', 'futurenet']]
                    ),
                    new OpenApiParameter(
                        name: 'top',
                        in: 'query',
                        description: 'Top ranked accounts window (default: 2000, max: 5000).',
                        required: false,
                        schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 5000]
                    ),
                ]
            )
        )
    ]
)]
#[ApiFilter(SearchFilter::class, properties: ['label' => 'partial', 'address' => 'exact'])]
#[ApiFilter(
    OrderFilter::class,
    properties: [
        'accountMetric.rankPosition',
        'accountMetric.rankScore',
        'accountMetric.transactionsPerHour',
        'accountMetric.nativeBalance',
        'accountMetric.paymentsCount',
        'accountMetric.tradesCount',
        'accountMetric.firstTransactionAt',
        'accountMetric.lastTransactionAt',
        'accountMetric.metricUpdatedAt',
    ],
    arguments: ['orderNullsComparison' => OrderFilterInterface::NULLS_ALWAYS_LAST]
)]
class Account
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[ApiProperty(identifier: false)]
    private ?int $id = null;

    #[ORM\Column(length: 56)]
    #[ApiProperty(identifier: true)]
    private ?string $address = null;

    #[ORM\Column(options: ['default' => 1])]
    private int $network = 1;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(name: 'created_at')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at')]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column]
    private ?bool $verified = null;

    #[ORM\OneToOne(mappedBy: 'account', cascade: ['persist', 'remove'])]
    #[ApiProperty(readableLink: true)]
    #[SerializedName('metrics')]
    private ?AccountMetric $accountMetric = null;

    private ?array $stellarData = null;

    private ?array $activity24h = null;

    private ?array $assets = null;

    private ?array $contracts = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(string $address): static
    {
        $this->address = $address;

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

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $created_at): static
    {
        $this->createdAt = $created_at;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updated_at): static
    {
        $this->updatedAt = $updated_at;

        return $this;
    }

    public function isVerified(): ?bool
    {
        return $this->verified;
    }

    public function setVerified(bool $verified): static
    {
        $this->verified = $verified;

        return $this;
    }

    public function getAccountMetric(): ?AccountMetric
    {
        return $this->accountMetric;
    }

    public function setAccountMetric(AccountMetric $accountMetric): static
    {
        // set the owning side of the relation if necessary
        if ($accountMetric->getAccount() !== $this) {
            $accountMetric->setAccount($this);
        }

        $this->accountMetric = $accountMetric;

        return $this;
    }

    public function getStellarData(): ?array
    {
        return $this->stellarData;
    }

    public function setStellarData(?array $stellarData): static
    {
        $this->stellarData = $stellarData;

        return $this;
    }

    public function getAssets(): ?array
    {
        return $this->assets;
    }

    public function setAssets(?array $assets): static
    {
        $this->assets = $assets;

        return $this;
    }

    public function getActivity24h(): ?array
    {
        return $this->activity24h;
    }

    public function setActivity24h(?array $activity24h): static
    {
        $this->activity24h = $activity24h;

        return $this;
    }

    public function getContracts(): ?array
    {
        return $this->contracts;
    }

    public function setContracts(?array $contracts): static
    {
        $this->contracts = $contracts;

        return $this;
    }
}
