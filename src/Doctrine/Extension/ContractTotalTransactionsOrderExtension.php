<?php

namespace App\Doctrine\Extension;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Contract;
use Doctrine\ORM\QueryBuilder;

final class ContractTotalTransactionsOrderExtension implements QueryCollectionExtensionInterface
{
    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = []
    ): void {
        if ($resourceClass !== Contract::class) {
            return;
        }

        $rootAliases = $queryBuilder->getRootAliases();
        $rootAlias = $rootAliases[0] ?? null;
        if (!is_string($rootAlias) || $rootAlias === '') {
            return;
        }

        $orderFilters = is_array($context['filters']['order'] ?? null) ? $context['filters']['order'] : [];

        $assetCodeOrder = $this->normalizeDirection($orderFilters['asset_code'] ?? $orderFilters['assetCode'] ?? null);
        if ($assetCodeOrder !== null) {
            $queryBuilder->andWhere(sprintf('%s.assetCode IS NOT NULL AND %s.assetCode <> \'\'', $rootAlias, $rootAlias));
            $queryBuilder->addOrderBy(sprintf('%s.assetCode', $rootAlias), $assetCodeOrder);
        }

        $totalTransactionsOrder = $this->normalizeDirection($orderFilters['totalTransactions'] ?? null);
        if ($totalTransactionsOrder !== null) {
            $queryBuilder->addOrderBy(sprintf('%s.totalTransactions', $rootAlias), $totalTransactionsOrder);
        }

        $totalInvokesOrder = $this->normalizeDirection($orderFilters['totalInvokes'] ?? null);
        if ($totalInvokesOrder !== null) {
            $queryBuilder->addOrderBy(sprintf('%s.totalInvokes', $rootAlias), $totalInvokesOrder);
        }
    }

    private function normalizeDirection(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $direction = strtoupper(trim($value));
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            return null;
        }

        return $direction;
    }
}
