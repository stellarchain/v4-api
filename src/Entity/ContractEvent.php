<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter as OpenApiParameter;
use App\DataProvider\ContractEventsCollectionProvider;
use Doctrine\ORM\Mapping as ORM;

#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/contracts/{contractId}/events',
            provider: ContractEventsCollectionProvider::class,
            uriVariables: [
                'contractId' => new Link(fromClass: Contract::class, toProperty: 'contract'),
            ],
            openapi: new OpenApiOperation(
                tags: ['Contract'],
                summary: 'Get contract events history',
                parameters: [
                    new OpenApiParameter(
                        name: 'network',
                        in: 'query',
                        description: 'Filter by network (mainnet|testnet|futurenet). Default: mainnet.',
                        required: false,
                        schema: ['type' => 'string', 'enum' => ['mainnet', 'testnet', 'futurenet']]
                    ),
                    new OpenApiParameter(
                        name: 'beforeId',
                        in: 'query',
                        description: 'Keyset cursor: return events with id < beforeId (faster than deep OFFSET pagination).',
                        required: false,
                        schema: ['type' => 'integer', 'minimum' => 1]
                    ),
                    new OpenApiParameter(
                        name: 'cursor',
                        in: 'query',
                        description: 'Opaque cursor returned in meta.nextCursor.',
                        required: false,
                        schema: ['type' => 'string']
                    ),
                    new OpenApiParameter(
                        name: 'ledgerStart',
                        in: 'query',
                        description: 'Inclusive minimum ledger filter.',
                        required: false,
                        schema: ['type' => 'integer', 'minimum' => 1]
                    ),
                    new OpenApiParameter(
                        name: 'ledgerEnd',
                        in: 'query',
                        description: 'Inclusive maximum ledger filter.',
                        required: false,
                        schema: ['type' => 'integer', 'minimum' => 1]
                    ),
                    new OpenApiParameter(
                        name: 'txHash',
                        in: 'query',
                        description: 'Filter events by transaction hash.',
                        required: false,
                        schema: ['type' => 'string']
                    ),
                ]
            ),
            paginationEnabled: false
        ),
    ]
)]
#[ApiFilter(OrderFilter::class, properties: ['ledger', 'ledgerClosedAt', 'createdAt'])]
#[ORM\Entity]
#[ORM\Table(
    name: 'contract_events',
    uniqueConstraints: [new ORM\UniqueConstraint(name: 'uniq_contract_event_idx', columns: ['contract_id', 'tx_hash', 'event_idx'])],
    indexes: [
        new ORM\Index(name: 'idx_contract_events_contract_ledger', columns: ['contract_id', 'ledger']),
        new ORM\Index(name: 'idx_contract_events_contract_tx', columns: ['contract_id', 'tx_hash']),
        new ORM\Index(name: 'idx_contract_events_contract_type', columns: ['contract_id', 'event_type']),
    ]
)]
class ContractEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Contract::class, inversedBy: 'events')]
    #[ORM\JoinColumn(name: 'contract_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Contract $contract = null;

    #[ORM\Column(name: 'tx_hash', length: 300)]
    private ?string $txHash = null;

    #[ORM\Column(name: 'event_idx')]
    private int $eventIndex = 0;

    #[ORM\Column(name: 'ledger', nullable: true)]
    private ?int $ledger = null;

    #[ORM\Column(name: 'ledger_closed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $ledgerClosedAt = null;

    #[ORM\Column(name: 'event_type', length: 64, options: ['default' => 'unknown'])]
    private string $eventType = 'unknown';

    /** @var array<int,mixed>|null */
    #[ORM\Column(name: 'topic_decoded', type: 'json', nullable: true)]
    private ?array $topicDecoded = null;

    #[ORM\Column(name: 'value_decoded', type: 'json', nullable: true)]
    private mixed $valueDecoded = null;

    /** @var array<int,string>|null */
    #[ORM\Column(name: 'addresses', type: 'json', nullable: true)]
    private ?array $addresses = null;

    #[ORM\Column(name: 'amount_raw', length: 100, nullable: true)]
    private ?string $amountRaw = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $createdAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContract(): ?Contract
    {
        return $this->contract;
    }

    public function setContract(?Contract $contract): self
    {
        $this->contract = $contract;

        return $this;
    }

    public function getTxHash(): ?string
    {
        return $this->txHash;
    }

    public function setTxHash(string $txHash): self
    {
        $this->txHash = $txHash;

        return $this;
    }

    public function getEventIndex(): int
    {
        return $this->eventIndex;
    }

    public function setEventIndex(int $eventIndex): self
    {
        $this->eventIndex = $eventIndex;

        return $this;
    }

    public function getLedger(): ?int
    {
        return $this->ledger;
    }

    public function setLedger(?int $ledger): self
    {
        $this->ledger = $ledger;

        return $this;
    }

    public function getLedgerClosedAt(): ?\DateTimeImmutable
    {
        return $this->ledgerClosedAt;
    }

    public function setLedgerClosedAt(?\DateTimeImmutable $ledgerClosedAt): self
    {
        $this->ledgerClosedAt = $ledgerClosedAt;

        return $this;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function setEventType(string $eventType): self
    {
        $this->eventType = $eventType;

        return $this;
    }

    /**
     * @return array<int,mixed>|null
     */
    public function getTopicDecoded(): ?array
    {
        return $this->topicDecoded;
    }

    /**
     * @param array<int,mixed>|null $topicDecoded
     */
    public function setTopicDecoded(?array $topicDecoded): self
    {
        $this->topicDecoded = $topicDecoded;

        return $this;
    }

    public function getValueDecoded(): mixed
    {
        return $this->valueDecoded;
    }

    public function setValueDecoded(mixed $valueDecoded): self
    {
        $this->valueDecoded = $valueDecoded;

        return $this;
    }

    /**
     * @return array<int,string>|null
     */
    public function getAddresses(): ?array
    {
        return $this->addresses;
    }

    /**
     * @param array<int,string>|null $addresses
     */
    public function setAddresses(?array $addresses): self
    {
        $this->addresses = $addresses;

        return $this;
    }

    public function getAmountRaw(): ?string
    {
        return $this->amountRaw;
    }

    public function setAmountRaw(?string $amountRaw): self
    {
        $this->amountRaw = $amountRaw;

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
}
