<?php

namespace App\Doctrine\Extension;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Asset;
use Doctrine\ORM\QueryBuilder;

final class AssetRatingAverageOrderExtension implements QueryCollectionExtensionInterface
{
    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = []
    ): void {
        if ($resourceClass !== Asset::class) {
            return;
        }

        $order = $context['filters']['order']['ratingAverage'] ?? null;
        if (!is_string($order)) {
            return;
        }

        $direction = strtoupper(trim($order));
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            return;
        }

        $rootAliases = $queryBuilder->getRootAliases();
        $rootAlias = $rootAliases[0] ?? null;
        if (!is_string($rootAlias) || $rootAlias === '') {
            return;
        }

        $queryBuilder
            ->addSelect(sprintf(
                'CASE WHEN %1$s.ratingAverage IS NULL OR %1$s.ratingAverage = 0 THEN 1 ELSE 0 END AS HIDDEN ratingAverageEmptyOrder',
                $rootAlias
            ))
            ->addOrderBy('ratingAverageEmptyOrder', 'ASC')
            ->addOrderBy($rootAlias . '.ratingAverage', $direction);
    }
}

