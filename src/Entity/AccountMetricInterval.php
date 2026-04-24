<?php

namespace App\Entity;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter as OpenApiParameter;
use App\Repository\AccountMetricIntervalRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AccountMetricIntervalRepository::class, readOnly: true)]
#[ORM\Table(name: 'account_metric_interval')]
#[GetCollection(
    uriTemplate: '/accounts/{address}/statistics',
    uriVariables: [
        'address' => new Link(fromClass: Account::class, identifiers: ['address'], toProperty: 'account'),
    ],
    openapi: new OpenApiOperation(
        tags: ['Account'],
        summary: 'Get account interval statistics history',
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
    paginationItemsPerPage: 100,
    order: ['intervalStart' => 'DESC'],
)]
class AccountMetricInterval
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'account_id', nullable: false, onDelete: 'CASCADE')]
    private ?Account $account = null;

    #[ORM\Column(name: 'interval_start', type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $intervalStart = null;

    #[ORM\Column(name: 'interval_end', type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $intervalEnd = null;

    #[ORM\Column(name: 'total_transactions')]
    private int $totalTransactions = 0;

    #[ORM\Column(name: 'payment_operations')]
    private int $paymentOperations = 0;

    #[ORM\Column(name: 'trade_operations')]
    private int $tradeOperations = 0;

    #[ORM\Column(name: 'asset_transactions')]
    private int $assetTransactions = 0;

    #[ORM\Column(name: 'contract_transactions')]
    private int $contractTransactions = 0;

    #[ORM\Column(name: 'successful_transactions')]
    private int $successfulTransactions = 0;

    #[ORM\Column(name: 'failed_transactions')]
    private int $failedTransactions = 0;

    #[ORM\Column(name: 'operation_count')]
    private int $operationCount = 0;

    #[ORM\Column(name: 'fee_charged_sum', type: Types::DECIMAL, precision: 30, scale: 0)]
    private string $feeChargedSum = '0';

    #[ORM\Column(name: 'max_fee_sum', type: Types::DECIMAL, precision: 30, scale: 0)]
    private string $maxFeeSum = '0';

    #[ORM\Column(name: 'first_tx_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $firstTxAt = null;

    #[ORM\Column(name: 'last_tx_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastTxAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function getIntervalStart(): ?\DateTimeImmutable
    {
        return $this->intervalStart;
    }

    public function getIntervalEnd(): ?\DateTimeImmutable
    {
        return $this->intervalEnd;
    }

    public function getTotalTransactions(): int
    {
        return $this->totalTransactions;
    }

    public function getPaymentOperations(): int
    {
        return $this->paymentOperations;
    }

    public function getTradeOperations(): int
    {
        return $this->tradeOperations;
    }

    public function getAssetTransactions(): int
    {
        return $this->assetTransactions;
    }

    public function getContractTransactions(): int
    {
        return $this->contractTransactions;
    }

    public function getSuccessfulTransactions(): int
    {
        return $this->successfulTransactions;
    }

    public function getFailedTransactions(): int
    {
        return $this->failedTransactions;
    }

    public function getOperationCount(): int
    {
        return $this->operationCount;
    }

    public function getFeeChargedSum(): string
    {
        return $this->feeChargedSum;
    }

    public function getMaxFeeSum(): string
    {
        return $this->maxFeeSum;
    }

    public function getFirstTxAt(): ?\DateTimeImmutable
    {
        return $this->firstTxAt;
    }

    public function getLastTxAt(): ?\DateTimeImmutable
    {
        return $this->lastTxAt;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
