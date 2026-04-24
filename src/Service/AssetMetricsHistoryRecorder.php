<?php

namespace App\Service;

use App\Entity\Asset;
use App\Entity\AssetMetricHistory;
use App\Repository\AssetRepository;
use Doctrine\ORM\EntityManagerInterface;

final class AssetMetricsHistoryRecorder
{
    private const DEFAULT_NETWORK_CODE = 1;
    /**
     * @var array<string,list<string>>
     */
    private const METRIC_WHITELIST = [
        'coingecko_stellar' => [
            'market_data.current_price.usd',
            'market_data.market_cap.usd',
            'market_data.total_volume.usd',
            'market_data.fully_diluted_valuation.usd',
            'market_cap_rank',
            'market_data.high_24h.usd',
            'market_data.low_24h.usd',
            'market_data.price_change_percentage_24h',
            'market_data.price_change_percentage_7d',
            'market_data.price_change_percentage_30d',
            'market_data.market_cap_change_percentage_24h',
            'watchlist_portfolio_users',
        ],
        'coingecko_global' => [
            'data.total_market_cap.usd',
            'data.total_volume.usd',
            'data.market_cap_change_percentage_24h_usd',
            'data.volume_change_percentage_24h_usd',
            'data.market_cap_percentage.btc',
        ],
        'stellar_dashboard' => [
            'totalSupply',
            'circulatingSupply',
            'feePool',
            'upgradeReserve',
            'sdfMandate',
            'burnedLumens',
            'inflationLumens',
        ],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AssetRepository $assetRepository,
    ) {
    }

    /**
     * Persists one row per scalar metric (not full JSON blobs).
     *
     * @param array<string,mixed> $payload
     */
    public function recordXlmNativeSnapshot(array $payload): int
    {
        $asset = $this->findOrCreateXlmNativeAsset();
        $recordedAt = new \DateTimeImmutable();
        $inserted = 0;

        foreach ($payload as $source => $sourcePayload) {
            if (!isset(self::METRIC_WHITELIST[$source]) || !is_array($sourcePayload)) {
                continue;
            }

            foreach (self::METRIC_WHITELIST[$source] as $metricKey) {
                $value = $this->getValueByPath($sourcePayload, $metricKey);
                if ($value === null) {
                    continue;
                }

                if (!is_int($value) && !is_float($value) && !is_string($value) && !is_bool($value)) {
                    continue;
                }

                $history = (new AssetMetricHistory())
                    ->setAsset($asset)
                    ->setSource($source)
                    ->setMetricKey($metricKey)
                    ->setRecordedAt($recordedAt);

                if (is_int($value) || is_float($value)) {
                    $history->setValueDecimal($this->normalizeDecimal($value));
                } elseif (is_bool($value)) {
                    $history->setValueText($value ? 'true' : 'false');
                } elseif (is_string($value) && is_numeric($value)) {
                    $history->setValueDecimal($this->normalizeDecimal((float) $value));
                } else {
                    $history->setValueText((string) $value);
                }

                $this->entityManager->persist($history);
                $inserted++;
            }
        }

        $asset->setUpdatedAt($recordedAt);
        $this->entityManager->flush();

        return $inserted;
    }

    private function findOrCreateXlmNativeAsset(): Asset
    {
        $asset = $this->assetRepository->findXlmNative(self::DEFAULT_NETWORK_CODE);
        if ($asset !== null) {
            return $asset;
        }

        $now = new \DateTimeImmutable();
        $asset = (new Asset())
            ->setAssetKey('XLM-native')
            ->setNetwork(self::DEFAULT_NETWORK_CODE)
            ->setCode('XLM')
            ->setIssuer(null)
            ->setIsNative(true)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);

        $this->entityManager->persist($asset);

        return $asset;
    }

    private function getValueByPath(array $data, string $path): mixed
    {
        $segments = explode('.', $path);
        $cursor = $data;
        foreach ($segments as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    private function normalizeDecimal(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        $normalized = sprintf('%.14F', $value);
        return rtrim(rtrim($normalized, '0'), '.');
    }
}
