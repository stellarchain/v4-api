<?php

namespace App\Doctrine\Extension;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Account;
use App\Entity\AccountMetricInterval;
use App\Entity\Asset;
use App\Entity\AssetStatistic;
use App\Entity\Contract;
use App\Entity\ContractEvent;
use App\Entity\ContractStorageEntry;
use App\Entity\ContractTransaction;
use App\Entity\MarketAssetSnapshot;
use App\Entity\MarketOverviewSnapshot;
use App\Entity\NetworkMetricPoint;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\ORM\QueryBuilder;

final class NetworkFilterExtension implements QueryCollectionExtensionInterface
{
    public function __construct(
        private readonly StellarNetworkResolver $stellarNetworkResolver,
    ) {
    }

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = []
    ): void {
        $networkCode = $this->resolveNetworkCode($context);
        if ($networkCode === null) {
            return;
        }

        $rootAliases = $queryBuilder->getRootAliases();
        $rootAlias = $rootAliases[0] ?? null;
        if (!is_string($rootAlias) || $rootAlias === '') {
            return;
        }

        if (in_array($resourceClass, [Account::class, Asset::class, Contract::class, MarketAssetSnapshot::class, MarketOverviewSnapshot::class, NetworkMetricPoint::class], true)) {
            $queryBuilder
                ->andWhere(sprintf('%s.network = :network', $rootAlias))
                ->setParameter('network', $networkCode);
            return;
        }

        if (in_array($resourceClass, [ContractTransaction::class, ContractEvent::class, ContractStorageEntry::class], true)) {
            $contractAlias = $queryNameGenerator->generateJoinAlias('contract_network');
            $queryBuilder
                ->leftJoin($rootAlias . '.contract', $contractAlias)
                ->andWhere(sprintf('%s.network = :network', $contractAlias))
                ->setParameter('network', $networkCode);
            return;
        }

        if ($resourceClass === AssetStatistic::class) {
            $assetAlias = $queryNameGenerator->generateJoinAlias('asset_network');
            $queryBuilder
                ->leftJoin($rootAlias . '.asset', $assetAlias)
                ->andWhere(sprintf('%s.network = :network', $assetAlias))
                ->setParameter('network', $networkCode);
            return;
        }

        if ($resourceClass === AccountMetricInterval::class) {
            $accountAlias = $queryNameGenerator->generateJoinAlias('account_network');
            $queryBuilder
                ->leftJoin($rootAlias . '.account', $accountAlias)
                ->andWhere(sprintf('%s.network = :network', $accountAlias))
                ->setParameter('network', $networkCode);
        }
    }

    private function resolveNetworkCode(array $context): ?int
    {
        $networkFilter = $context['filters']['network'] ?? null;
        $networkInput = is_string($networkFilter) ? trim($networkFilter) : null;
        $network = $this->stellarNetworkResolver->normalizeNetwork($networkInput, 'mainnet');

        return $this->stellarNetworkResolver->resolveNetworkCode($network);
    }
}
