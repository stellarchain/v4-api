<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter as OpenApiParameter;
use App\DataProvider\ContractTransactionsCollectionProvider;
use Doctrine\ORM\Mapping as ORM;

#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/contracts/{contractId}/transactions',
            provider: ContractTransactionsCollectionProvider::class,
            uriVariables: [
                'contractId' => new Link(fromClass: Contract::class, toProperty: 'contract'),
            ],
            openapi: new OpenApiOperation(
                tags: ['Contract'],
                summary: 'Get contract transactions history',
                parameters: [
                    new OpenApiParameter(
                        name: 'network',
                        in: 'query',
                        description: 'Filter by network (mainnet|testnet|futurenet). Default: mainnet.',
                        required: false,
                        schema: ['type' => 'string', 'enum' => ['mainnet', 'testnet', 'futurenet']]
                    ),
                    new OpenApiParameter(
                        name: 'invocationsOnly',
                        in: 'query',
                        description: 'Return only transactions with non-empty invokeContracts in host_functions.',
                        required: false,
                        schema: ['type' => 'boolean']
                    ),
                    new OpenApiParameter(
                        name: 'beforeId',
                        in: 'query',
                        description: 'Keyset cursor: return transactions with id < beforeId (faster than deep OFFSET pagination).',
                        required: false,
                        schema: ['type' => 'integer', 'minimum' => 1]
                    ),
                ]
            ),
            paginationEnabled: false
        ),
    ]
)]
#[ApiFilter(OrderFilter::class, properties: ['createdAt'])]
#[ORM\Entity]
#[ORM\Table(
    name: 'contract_transactions',
    uniqueConstraints: [new ORM\UniqueConstraint(name: 'uniq_contract_tx_hash', columns: ['contract_id', 'tx_hash'])]
)]
class ContractTransaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Contract::class, inversedBy: 'transactions')]
    #[ORM\JoinColumn(name: 'contract_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Contract $contract = null;

    #[ORM\Column(name: 'tx_hash', length: 300)]
    private ?string $txHash = null;

    #[ORM\Column(name: 'source_account', length: 300, nullable: true)]
    private ?string $sourceAccount = null;

    #[ORM\Column(name: 'host_functions', type: 'text', nullable: true)]
    private ?string $hostFunctions = null;

    #[ORM\Column(name: 'fee_charged')]
    private int $feeCharged = 0;

    #[ORM\Column(name: 'max_fee')]
    private int $maxFee = 0;

    #[ORM\Column(name: 'ledger', nullable: true)]
    private ?int $ledger = null;

    #[ORM\Column(name: 'total_operations', nullable: true)]
    private ?int $totalOperations = null;

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

    public function getSourceAccount(): ?string
    {
        return $this->sourceAccount;
    }

    public function setSourceAccount(?string $sourceAccount): self
    {
        $this->sourceAccount = $sourceAccount;

        return $this;
    }

    public function getHostFunctions(): ?string
    {
        return $this->hostFunctions;
    }

    public function setHostFunctions(?string $hostFunctions): self
    {
        $this->hostFunctions = $hostFunctions;

        return $this;
    }

    public function getFeeCharged(): int
    {
        return $this->feeCharged;
    }

    public function setFeeCharged(int $feeCharged): self
    {
        $this->feeCharged = $feeCharged;

        return $this;
    }

    public function getMaxFee(): int
    {
        return $this->maxFee;
    }

    public function setMaxFee(int $maxFee): self
    {
        $this->maxFee = $maxFee;

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

    public function getTotalOperations(): ?int
    {
        return $this->totalOperations;
    }

    public function setTotalOperations(?int $totalOperations): self
    {
        $this->totalOperations = $totalOperations;

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
