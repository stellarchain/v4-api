<?php

namespace App\Entity;

use App\Repository\AccountMetricRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AccountMetricRepository::class)]
class AccountMetric
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'total_transactions', type: Types::BIGINT, options: ['default' => 0])]
    private string $transactionsPerHour = '0';

    #[ORM\OneToOne(inversedBy: 'accountMetric', cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    private ?Account $account = null;

    #[ORM\Column(name: 'native_balance', type: Types::DECIMAL, precision: 36, scale: 7, options: ['default' => '0.0000000'])]
    private string $nativeBalance = '0.0000000';

    #[ORM\Column(name: 'payments_count', type: Types::BIGINT, options: ['default' => 0])]
    private string $paymentsCount = '0';

    #[ORM\Column(name: 'trades_count', type: Types::BIGINT, options: ['default' => 0])]
    private string $tradesCount = '0';

    #[ORM\Column(name: 'rank_score', type: Types::DECIMAL, precision: 20, scale: 8, options: ['default' => '0'])]
    private string $rankScore = '0';

    #[ORM\Column(name: 'rank_position', nullable: true)]
    private ?int $rankPosition = null;

    #[ORM\Column(name: 'metric_updated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $metricUpdatedAt = null;

    #[ORM\Column(name: 'first_transaction_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $firstTransactionAt = null;

    #[ORM\Column(name: 'last_transaction_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastTransactionAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTransactionsPerHour(): string
    {
        return $this->transactionsPerHour;
    }

    public function setTransactionsPerHour(string $transactionsPerHour): static
    {
        $this->transactionsPerHour = $transactionsPerHour;

        return $this;
    }

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function setAccount(Account $account): static
    {
        $this->account = $account;

        return $this;
    }

    public function getNativeBalance(): string
    {
        return $this->nativeBalance;
    }

    public function setNativeBalance(string $native_balance): static
    {
        $this->nativeBalance = $native_balance;

        return $this;
    }

    public function getPaymentsCount(): string
    {
        return $this->paymentsCount;
    }

    public function setPaymentsCount(string $paymentsCount): static
    {
        $this->paymentsCount = $paymentsCount;

        return $this;
    }

    public function getTradesCount(): string
    {
        return $this->tradesCount;
    }

    public function setTradesCount(string $tradesCount): static
    {
        $this->tradesCount = $tradesCount;

        return $this;
    }

    public function getRankScore(): string
    {
        return $this->rankScore;
    }

    public function setRankScore(string $rankScore): static
    {
        $this->rankScore = $rankScore;

        return $this;
    }

    public function getRankPosition(): ?int
    {
        return $this->rankPosition;
    }

    public function setRankPosition(?int $rankPosition): static
    {
        $this->rankPosition = $rankPosition;

        return $this;
    }

    public function getMetricUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->metricUpdatedAt;
    }

    public function setMetricUpdatedAt(?\DateTimeImmutable $metricUpdatedAt): static
    {
        $this->metricUpdatedAt = $metricUpdatedAt;

        return $this;
    }

    public function getFirstTransactionAt(): ?\DateTimeImmutable
    {
        return $this->firstTransactionAt;
    }

    public function setFirstTransactionAt(?\DateTimeImmutable $firstTransactionAt): static
    {
        $this->firstTransactionAt = $firstTransactionAt;

        return $this;
    }

    public function getLastTransactionAt(): ?\DateTimeImmutable
    {
        return $this->lastTransactionAt;
    }

    public function setLastTransactionAt(?\DateTimeImmutable $lastTransactionAt): static
    {
        $this->lastTransactionAt = $lastTransactionAt;

        return $this;
    }
}
