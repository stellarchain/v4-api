<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter as OpenApiParameter;
use App\DataProvider\ContractCollectionDataProvider;
use App\DataProvider\ContractItemDataProvider;
use App\Repository\ContractRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Ignore;
use Symfony\Component\Serializer\Annotation\SerializedName;

#[ORM\Entity(repositoryClass: ContractRepository::class)]
#[ORM\Table(name: 'contracts', uniqueConstraints: [new ORM\UniqueConstraint(name: 'uniq_contract_id_network', columns: ['contract_id', 'network'])])]
#[ApiResource(
    cacheHeaders: [
        'max_age' => 60,
        'shared_max_age' => 60,
        'vary' => ['Accept', 'Content-Type', 'Origin'],
    ],
    operations: [
        new Get(
            uriTemplate: '/contracts/{contractId}',
            provider: ContractItemDataProvider::class,
            openapi: new OpenApiOperation(
                tags: ['Contract'],
                parameters: [
                    new OpenApiParameter(
                        name: 'base65wasm',
                        in: 'query',
                        description: 'Deprecated. Ignored.',
                        required: false,
                        schema: ['type' => 'string', 'example' => '1']
                    ),
                    new OpenApiParameter(
                        name: 'network',
                        in: 'query',
                        description: 'Soroban network to query (mainnet|testnet|futurenet). Default: mainnet.',
                        required: false,
                        schema: ['type' => 'string', 'enum' => ['mainnet', 'testnet', 'futurenet']]
                    ),
                ]
            )
        ),
        new GetCollection(
            provider: ContractCollectionDataProvider::class,
            openapi: new OpenApiOperation(
                tags: ['Contract'],
                parameters: [
                    new OpenApiParameter(
                        name: 'network',
                        in: 'query',
                        description: 'Filter by network (mainnet|testnet|futurenet). Default: mainnet.',
                        required: false,
                        schema: ['type' => 'string', 'enum' => ['mainnet', 'testnet', 'futurenet']]
                    ),
                    new OpenApiParameter(
                        name: 'contract_id',
                        in: 'query',
                        description: 'Exact contract id filter.',
                        required: false,
                        schema: ['type' => 'string']
                    ),
                    new OpenApiParameter(
                        name: 'contractIds[]',
                        in: 'query',
                        description: 'Filter by an explicit list of contract ids (max 15).',
                        required: false,
                        schema: ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 15]
                    ),
                    new OpenApiParameter(
                        name: 'search',
                        in: 'query',
                        description: 'Unified search across contract id, asset code, asset issuer, verified name and verified symbol.',
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
                        name: 'asset_code',
                        in: 'query',
                        description: 'Exact asset code filter.',
                        required: false,
                        schema: ['type' => 'string']
                    ),
                    new OpenApiParameter(
                        name: 'sac',
                        in: 'query',
                        description: 'Filter by SAC contracts (true|false).',
                        required: false,
                        schema: ['type' => 'boolean']
                    ),
                    new OpenApiParameter(
                        name: 'sourceCodeVerified',
                        in: 'query',
                        description: 'Filter by source code verification state (true|false).',
                        required: false,
                        schema: ['type' => 'boolean']
                    ),
                    new OpenApiParameter(
                        name: 'order[totalTransactions]',
                        in: 'query',
                        description: 'Sort by total number of transactions per contract (asc|desc).',
                        required: false,
                        schema: ['type' => 'string', 'enum' => ['asc', 'desc']]
                    ),
                    new OpenApiParameter(
                        name: 'order[totalInvokes]',
                        in: 'query',
                        description: 'Sort by total invoke transactions per contract (asc|desc).',
                        required: false,
                        schema: ['type' => 'string', 'enum' => ['asc', 'desc']]
                    ),
                    new OpenApiParameter(
                        name: 'order[asset_code]',
                        in: 'query',
                        description: 'Sort by asset code (asc|desc).',
                        required: false,
                        schema: ['type' => 'string', 'enum' => ['asc', 'desc']]
                    ),
                ]
            )
        )
    ]
)]
#[ApiFilter(OrderFilter::class, properties: ['createdAt'])]
class Contract
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[ApiProperty(identifier: false)]
    private ?int $id = null;

    #[ORM\Column(name: 'contract_id', length: 300)]
    #[ApiProperty(identifier: true)]
    private ?string $contractId = null;

    #[ORM\Column(name: 'contract_id_hex', length: 64, nullable: true)]
    private ?string $contractIdHex = null;

    #[ORM\Column(name: 'asset_code', length: 255, nullable: true)]
    private ?string $assetCode = null;

    #[ORM\Column(name: 'asset_address', length: 255, nullable: true)]
    private ?string $assetAddress = null;

    #[ORM\Column(name: 'asset_issuer', length: 255, nullable: true)]
    private ?string $assetIssuer = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ApiProperty(readable: false, writable: false)]
    #[ORM\Column(name: 'contract_code', type: 'text', nullable: true)]
    private ?string $contractCode = null;

    #[ORM\Column(name: 'source_code_verified', options: ['default' => false])]
    private bool $sourceCodeVerified = false;

    #[ORM\Column(name: 'contract_type', nullable: true)]
    private ?int $contractType = null;

    #[ORM\Column(options: ['default' => 1])]
    private int $network = 1;

    #[ORM\Column(name: 'wasm_id', length: 64, nullable: true)]
    private ?string $wasmId = null;

    #[ORM\Column(name: 'executable_type', nullable: true)]
    private ?int $executableType = null;

    #[ORM\Column(name: 'is_sac', options: ['default' => false])]
    private bool $isSac = false;

    #[ORM\OneToOne(mappedBy: 'contract', targetEntity: ContractVerifiedMetadata::class, cascade: ['persist', 'remove'], orphanRemoval: true, fetch: 'LAZY')]
    private ?ContractVerifiedMetadata $verifiedMetadata = null;

    /** @var Collection<int, ContractTransaction> */
    #[ApiProperty(readable: false, writable: false)]
    #[ORM\OneToMany(mappedBy: 'contract', targetEntity: ContractTransaction::class, cascade: ['persist', 'remove'], orphanRemoval: true, fetch: 'EXTRA_LAZY')]
    private Collection $transactions;

    /** @var Collection<int, ContractEvent> */
    #[ApiProperty(readable: false, writable: false)]
    #[ORM\OneToMany(mappedBy: 'contract', targetEntity: ContractEvent::class, cascade: ['persist', 'remove'], orphanRemoval: true, fetch: 'EXTRA_LAZY')]
    private Collection $events;

    /** @var Collection<int, ContractStorageEntry> */
    #[ApiProperty(readable: false, writable: false)]
    #[ORM\OneToMany(mappedBy: 'contract', targetEntity: ContractStorageEntry::class, cascade: ['persist', 'remove'], orphanRemoval: true, fetch: 'EXTRA_LAZY')]
    private Collection $storageEntries;

    #[ORM\Column(name: 'total_transactions', options: ['default' => 0])]
    private int $totalTransactions = 0;

    #[ORM\Column(name: 'total_operations', options: ['default' => 0])]
    private int $totalOperations = 0;

    #[ORM\Column(name: 'total_events', options: ['default' => 0])]
    private int $totalEvents = 0;

    #[ORM\Column(name: 'total_effects', options: ['default' => 0])]
    private int $totalEffects = 0;

    #[ORM\Column(name: 'total_storage_entries', options: ['default' => 0])]
    private int $totalStorageEntries = 0;

    #[ORM\Column(name: 'total_invokes', options: ['default' => 0])]
    private int $totalInvokes = 0;

    /**
     * Runtime override used when Doctrine association state is stale in long-lived workers.
     * Not persisted.
     */
    private ?string $resolvedWasmId = null;
    private ?string $resolvedSourceCode = null;

    public function __construct()
    {
        $this->transactions = new ArrayCollection();
        $this->events = new ArrayCollection();
        $this->storageEntries = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContractId(): ?string
    {
        return $this->contractId;
    }

    public function setContractId(string $contractId): self
    {
        $this->contractId = $contractId;

        return $this;
    }

    public function getContractIdHex(): ?string
    {
        return $this->contractIdHex;
    }

    public function setContractIdHex(?string $contractIdHex): self
    {
        $this->contractIdHex = $contractIdHex;

        return $this;
    }

    public function getAssetCode(): ?string
    {
        return $this->assetCode;
    }

    public function setAssetCode(?string $assetCode): self
    {
        $this->assetCode = $assetCode;

        return $this;
    }

    public function getAssetAddress(): ?string
    {
        return $this->assetAddress;
    }

    public function setAssetAddress(?string $assetAddress): self
    {
        $this->assetAddress = $assetAddress;

        return $this;
    }

    public function getAssetIssuer(): ?string
    {
        return $this->assetIssuer;
    }

    public function setAssetIssuer(?string $assetIssuer): self
    {
        $this->assetIssuer = $assetIssuer;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function isSourceCodeVerified(): bool
    {
        return $this->sourceCodeVerified;
    }

    public function setSourceCodeVerified(bool $sourceCodeVerified): self
    {
        $this->sourceCodeVerified = $sourceCodeVerified;

        return $this;
    }

    public function getContractType(): ?int
    {
        return $this->contractType;
    }

    public function setContractType(?int $contractType): self
    {
        $this->contractType = $contractType;

        return $this;
    }

    public function getNetwork(): ?int
    {
        return $this->network;
    }

    public function setNetwork(?int $network): self
    {
        $this->network = $network;

        return $this;
    }

    public function getWasmId(): ?string
    {
        if (is_string($this->resolvedWasmId) && $this->resolvedWasmId !== '') {
            return $this->resolvedWasmId;
        }

        return $this->wasmId;
    }

    public function setWasmId(?string $wasmId): self
    {
        $this->wasmId = is_string($wasmId) && trim($wasmId) !== '' ? trim($wasmId) : null;

        return $this;
    }

    public function setResolvedWasmId(?string $resolvedWasmId): self
    {
        $this->resolvedWasmId = is_string($resolvedWasmId) && trim($resolvedWasmId) !== ''
            ? trim($resolvedWasmId)
            : null;

        return $this;
    }

    public function getExecutableType(): ?int
    {
        return $this->executableType;
    }

    public function setExecutableType(?int $executableType): self
    {
        $this->executableType = $executableType;

        return $this;
    }

    public function isSac(): bool
    {
        return $this->isSac;
    }

    public function setIsSac(bool $isSac): self
    {
        $this->isSac = $isSac;

        return $this;
    }

    public function getVerifiedMetadata(): ?ContractVerifiedMetadata
    {
        return $this->verifiedMetadata;
    }

    public function setVerifiedMetadata(?ContractVerifiedMetadata $verifiedMetadata): self
    {
        $this->verifiedMetadata = $verifiedMetadata;
        if ($verifiedMetadata !== null && $verifiedMetadata->getContract() !== $this) {
            $verifiedMetadata->setContract($this);
        }

        return $this;
    }

    /** @return Collection<int, ContractTransaction> */
    public function getTransactions(): Collection
    {
        return $this->transactions;
    }

    public function addTransaction(ContractTransaction $transaction): self
    {
        if (!$this->transactions->contains($transaction)) {
            $this->transactions->add($transaction);
            $transaction->setContract($this);
        }

        return $this;
    }

    public function removeTransaction(ContractTransaction $transaction): self
    {
        if ($this->transactions->removeElement($transaction) && $transaction->getContract() === $this) {
            $transaction->setContract(null);
        }

        return $this;
    }

    /** @return Collection<int, ContractEvent> */
    public function getEvents(): Collection
    {
        return $this->events;
    }

    public function addEvent(ContractEvent $event): self
    {
        if (!$this->events->contains($event)) {
            $this->events->add($event);
            $event->setContract($this);
        }

        return $this;
    }

    public function removeEvent(ContractEvent $event): self
    {
        if ($this->events->removeElement($event) && $event->getContract() === $this) {
            $event->setContract(null);
        }

        return $this;
    }

    /** @return Collection<int, ContractStorageEntry> */
    public function getStorageEntries(): Collection
    {
        return $this->storageEntries;
    }

    public function addStorageEntry(ContractStorageEntry $storageEntry): self
    {
        if (!$this->storageEntries->contains($storageEntry)) {
            $this->storageEntries->add($storageEntry);
            $storageEntry->setContract($this);
        }

        return $this;
    }

    public function removeStorageEntry(ContractStorageEntry $storageEntry): self
    {
        if ($this->storageEntries->removeElement($storageEntry) && $storageEntry->getContract() === $this) {
            $storageEntry->setContract(null);
        }

        return $this;
    }

    public function getTotalTransactions(): int
    {
        return $this->totalTransactions;
    }

    public function setTotalTransactions(int $totalTransactions): self
    {
        $this->totalTransactions = max(0, $totalTransactions);

        return $this;
    }

    public function getTotalEvents(): int
    {
        return $this->totalEvents;
    }

    public function setTotalEvents(int $totalEvents): self
    {
        $this->totalEvents = max(0, $totalEvents);

        return $this;
    }

    public function getTotalOperations(): int
    {
        return $this->totalOperations;
    }

    public function setTotalOperations(int $totalOperations): self
    {
        $this->totalOperations = max(0, $totalOperations);

        return $this;
    }

    public function getTotalEffects(): int
    {
        return $this->totalEffects;
    }

    public function setTotalEffects(int $totalEffects): self
    {
        $this->totalEffects = max(0, $totalEffects);

        return $this;
    }

    public function getTotalStorageEntries(): int
    {
        return $this->totalStorageEntries;
    }

    public function setTotalStorageEntries(int $totalStorageEntries): self
    {
        $this->totalStorageEntries = max(0, $totalStorageEntries);

        return $this;
    }

    public function getTotalInvokes(): int
    {
        return $this->totalInvokes;
    }

    #[SerializedName('totalInvokeTransactions')]
    public function getTotalInvokeTransactions(): int
    {
        return $this->totalInvokes;
    }

    public function setTotalInvokes(int $totalInvokes): self
    {
        $this->totalInvokes = max(0, $totalInvokes);

        return $this;
    }

    public function getSourceCode(): ?string
    {
        if (is_string($this->resolvedSourceCode) && $this->resolvedSourceCode !== '') {
            return $this->resolvedSourceCode;
        }

        return null;
    }

    public function setResolvedSourceCode(?string $resolvedSourceCode): self
    {
        $this->resolvedSourceCode = is_string($resolvedSourceCode) && trim($resolvedSourceCode) !== ''
            ? $resolvedSourceCode
            : null;

        return $this;
    }
}
