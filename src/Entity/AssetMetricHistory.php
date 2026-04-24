<?php

namespace App\Entity;

use App\Repository\AssetMetricHistoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AssetMetricHistoryRepository::class)]
#[ORM\Table(name: 'asset_metric_history', indexes: [
    new ORM\Index(name: 'idx_asset_metric_history_asset_time', columns: ['asset_id', 'recorded_at']),
    new ORM\Index(name: 'idx_asset_metric_history_source_key_time', columns: ['source', 'metric_key', 'recorded_at']),
])]
class AssetMetricHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'metricHistory')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Asset $asset;

    #[ORM\Column(length: 64)]
    private string $source;

    #[ORM\Column(name: 'metric_key', length: 191)]
    private string $metricKey;

    #[ORM\Column(name: 'value_decimal', type: Types::DECIMAL, precision: 36, scale: 14, nullable: true)]
    private ?string $valueDecimal = null;

    #[ORM\Column(name: 'value_text', length: 255, nullable: true)]
    private ?string $valueText = null;

    #[ORM\Column(name: 'recorded_at')]
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

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getMetricKey(): string
    {
        return $this->metricKey;
    }

    public function setMetricKey(string $metricKey): static
    {
        $this->metricKey = $metricKey;

        return $this;
    }

    public function getValueDecimal(): ?string
    {
        return $this->valueDecimal;
    }

    public function setValueDecimal(?string $valueDecimal): static
    {
        $this->valueDecimal = $valueDecimal;

        return $this;
    }

    public function getValueText(): ?string
    {
        return $this->valueText;
    }

    public function setValueText(?string $valueText): static
    {
        $this->valueText = $valueText;

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
