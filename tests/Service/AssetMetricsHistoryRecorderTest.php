<?php

namespace App\Tests\Service;

use App\Entity\Asset;
use App\Entity\AssetMetricHistory;
use App\Repository\AssetRepository;
use App\Service\AssetMetricsHistoryRecorder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class AssetMetricsHistoryRecorderTest extends TestCase
{
    public function testRecordsOnlyWhitelistedMetrics(): void
    {
        $asset = (new Asset())
            ->setAssetKey('XLM-native')
            ->setCode('XLM')
            ->setIsNative(true)
            ->setCreatedAt(new \DateTimeImmutable())
            ->setUpdatedAt(new \DateTimeImmutable());

        $assetRepository = $this->createMock(AssetRepository::class);
        $assetRepository->expects(self::once())
            ->method('findXlmNative')
            ->willReturn($asset);

        $recordedKeys = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(24))
            ->method('persist')
            ->with(self::callback(function (mixed $entity) use (&$recordedKeys): bool {
                if (!$entity instanceof AssetMetricHistory) {
                    return false;
                }
                $recordedKeys[] = sprintf('%s.%s', $entity->getSource(), $entity->getMetricKey());
                return true;
            }));
        $entityManager->expects(self::once())->method('flush');

        $recorder = new AssetMetricsHistoryRecorder($entityManager, $assetRepository);

        $inserted = $recorder->recordXlmNativeSnapshot([
            'coingecko_stellar' => [
                'market_data' => [
                    'current_price' => ['usd' => 0.15, 'eur' => 0.13],
                    'market_cap' => ['usd' => 5000000000],
                    'total_volume' => ['usd' => 120000000],
                    'fully_diluted_valuation' => ['usd' => 8000000000],
                    'high_24h' => ['usd' => 0.16],
                    'low_24h' => ['usd' => 0.14],
                    'price_change_percentage_24h' => -0.6,
                    'price_change_percentage_7d' => -9.4,
                    'price_change_percentage_30d' => -29.2,
                    'market_cap_change_percentage_24h' => -0.64,
                ],
                'market_cap_rank' => 22,
                'watchlist_portfolio_users' => 390000,
                'description' => ['en' => 'very long text should not be persisted'],
            ],
            'coingecko_global' => [
                'data' => [
                    'total_market_cap' => ['usd' => 3000000000000],
                    'total_volume' => ['usd' => 110000000000],
                    'market_cap_change_percentage_24h_usd' => -1.46,
                    'volume_change_percentage_24h_usd' => -13.76,
                    'market_cap_percentage' => ['btc' => 58.4, 'eth' => 10.1],
                ],
            ],
            'stellar_dashboard' => [
                'totalSupply' => '50001786883.6589518',
                'circulatingSupply' => '32726840143.4744233',
                'feePool' => '9296060.1755792',
                'upgradeReserve' => '259285847.5125903',
                'sdfMandate' => '17006364832.496359',
                'burnedLumens' => '55442115203.6883347',
                'inflationLumens' => '5443902087.3472865',
                '_details' => 'https://www.stellar.org/developers/guides/lumen-supply-metrics.html',
            ],
        ]);

        self::assertSame(24, $inserted);
        self::assertCount(24, $recordedKeys);
        self::assertContains('coingecko_stellar.market_data.current_price.usd', $recordedKeys);
        self::assertContains('coingecko_global.data.total_market_cap.usd', $recordedKeys);
        self::assertContains('stellar_dashboard.totalSupply', $recordedKeys);
        self::assertNotContains('coingecko_stellar.description.en', $recordedKeys);
        self::assertNotContains('stellar_dashboard._details', $recordedKeys);
        self::assertNotContains('coingecko_global.data.market_cap_percentage.eth', $recordedKeys);
    }
}
