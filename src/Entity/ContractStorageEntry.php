<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter as OpenApiParameter;
use App\DataProvider\ContractStorageEntriesCollectionProvider;
use Doctrine\ORM\Mapping as ORM;

#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/contracts/{contractId}/storage',
            provider: ContractStorageEntriesCollectionProvider::class,
            uriVariables: [
                'contractId' => new Link(fromClass: Contract::class, toProperty: 'contract'),
            ],
            openapi: new OpenApiOperation(
                tags: ['Contract'],
                summary: 'Get latest contract storage entries',
                parameters: [
                    new OpenApiParameter(
                        name: 'network',
                        in: 'query',
                        description: 'Filter by network (mainnet|testnet|futurenet). Default: mainnet.',
                        required: false,
                        schema: ['type' => 'string', 'enum' => ['mainnet', 'testnet', 'futurenet']]
                    ),
                    new OpenApiParameter(
                        name: 'cursor',
                        in: 'query',
                        description: 'Opaque cursor returned in meta.nextCursor.',
                        required: false,
                        schema: ['type' => 'string']
                    ),
                    new OpenApiParameter(
                        name: 'beforeId',
                        in: 'query',
                        description: 'Keyset cursor: return storage rows with id < beforeId.',
                        required: false,
                        schema: ['type' => 'integer', 'minimum' => 1]
                    ),
                    new OpenApiParameter(
                        name: 'ledgerStart',
                        in: 'query',
                        description: 'Inclusive minimum last_modified_ledger_seq filter.',
                        required: false,
                        schema: ['type' => 'integer', 'minimum' => 1]
                    ),
                    new OpenApiParameter(
                        name: 'ledgerEnd',
                        in: 'query',
                        description: 'Inclusive maximum last_modified_ledger_seq filter.',
                        required: false,
                        schema: ['type' => 'integer', 'minimum' => 1]
                    ),
                ]
            ),
            paginationEnabled: false
        ),
    ]
)]
#[ApiFilter(OrderFilter::class, properties: ['lastModifiedLedgerSeq', 'updatedAt'])]
#[ORM\Entity]
#[ORM\Table(
    name: 'contract_storage_entries',
    uniqueConstraints: [new ORM\UniqueConstraint(name: 'uniq_contract_storage_key', columns: ['contract_id', 'storage_key'])],
    indexes: [
        new ORM\Index(name: 'idx_contract_storage_contract_ledger', columns: ['contract_id', 'last_modified_ledger_seq']),
    ]
)]
class ContractStorageEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Contract::class, inversedBy: 'storageEntries')]
    #[ORM\JoinColumn(name: 'contract_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Contract $contract = null;

    #[ORM\Column(name: 'storage_key', length: 512)]
    private ?string $storageKey = null;

    #[ORM\Column(name: 'entry_xdr', type: 'text', nullable: true)]
    private ?string $entryXdr = null;

    #[ORM\Column(name: 'entry_decoded', type: 'json', nullable: true)]
    private mixed $entryDecoded = null;

    #[ORM\Column(name: 'last_modified_ledger_seq', nullable: true)]
    private ?int $lastModifiedLedgerSeq = null;

    #[ORM\Column(name: 'live_until_ledger_seq', nullable: true)]
    private ?int $liveUntilLedgerSeq = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $updatedAt = null;

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

    public function getStorageKey(): ?string
    {
        return $this->storageKey;
    }

    public function setStorageKey(string $storageKey): self
    {
        $this->storageKey = $storageKey;

        return $this;
    }

    public function getEntryXdr(): ?string
    {
        return $this->entryXdr;
    }

    public function setEntryXdr(?string $entryXdr): self
    {
        $this->entryXdr = $entryXdr;

        return $this;
    }

    public function getEntryDecoded(): mixed
    {
        return $this->entryDecoded;
    }

    public function setEntryDecoded(mixed $entryDecoded): self
    {
        $this->entryDecoded = $entryDecoded;

        return $this;
    }

    public function getLastModifiedLedgerSeq(): ?int
    {
        return $this->lastModifiedLedgerSeq;
    }

    public function setLastModifiedLedgerSeq(?int $lastModifiedLedgerSeq): self
    {
        $this->lastModifiedLedgerSeq = $lastModifiedLedgerSeq;

        return $this;
    }

    public function getLiveUntilLedgerSeq(): ?int
    {
        return $this->liveUntilLedgerSeq;
    }

    public function setLiveUntilLedgerSeq(?int $liveUntilLedgerSeq): self
    {
        $this->liveUntilLedgerSeq = $liveUntilLedgerSeq;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
