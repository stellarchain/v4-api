<?php

namespace App\DataProvider;

use ApiPlatform\DependencyInjection\Attribute\AsTaggedItem;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Asset;
use App\Repository\AssetRepository;
use App\Repository\MarketAssetSnapshotRepository;
use App\Service\Stellar\StellarNetworkResolver;

#[AsTaggedItem('api_platform.state.provider')]
final class AssetItemDataProvider implements ProviderInterface
{
    public function __construct(
        private readonly AssetRepository $repository,
        private readonly MarketAssetSnapshotRepository $marketAssetSnapshotRepository,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?Asset
    {
        $assetKey = $uriVariables['assetKey'] ?? $uriVariables['id'] ?? null;
        if ($assetKey === null) {
            return null;
        }
        $networkInput = $context['filters']['network'] ?? null;
        $network = $this->stellarNetworkResolver->normalizeNetwork(is_string($networkInput) ? $networkInput : null);
        $networkCode = $this->stellarNetworkResolver->resolveNetworkCode($network) ?? 1;

        $asset = $this->repository->findOneByAssetKey($assetKey, $networkCode)
            ?? $this->repository->findFirstByBaseKey($assetKey, $networkCode);
        if (!$asset instanceof Asset) {
            return null;
        }

        $snapshot = $this->marketAssetSnapshotRepository->findOneBy([
            'asset' => $asset,
            'network' => $networkCode,
        ]);

        if ($snapshot !== null) {
            $asset->setMarket([
                'rank' => $snapshot->getRankPosition(),
                'score' => $snapshot->getScore(),
                'price_xlm' => $snapshot->getPriceXlm(),
                'price_change_1h' => $snapshot->getPriceChange1h(),
                'price_change_24h' => $snapshot->getPriceChange24h(),
                'price_change_7d' => $snapshot->getPriceChange7d(),
                'volume_xlm_24h' => $snapshot->getVolumeXlm24h(),
                'trades_24h' => $snapshot->getTrades24h(),
                'trustlines_total' => $snapshot->getTrustlinesTotal(),
                'supply' => $snapshot->getSupply(),
                'sparkline_1h' => $snapshot->getSparkline1h(),
                'updated_at' => $snapshot->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            ]);
        } else {
            $asset->setMarket(null);
        }

        return $asset;
    }
}
