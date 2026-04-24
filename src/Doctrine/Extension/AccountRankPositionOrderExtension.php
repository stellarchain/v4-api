<?php

namespace App\Doctrine\Extension;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Account;
use Doctrine\ORM\QueryBuilder;

final class AccountRankPositionOrderExtension implements QueryCollectionExtensionInterface
{
    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = []
    ): void {
        if ($resourceClass !== Account::class) {
            return;
        }

        $orderFilter = $context['filters']['order'] ?? null;
        if (is_array($orderFilter) && $orderFilter !== []) {
            return;
        }

        $rootAliases = $queryBuilder->getRootAliases();
        $rootAlias = $rootAliases[0] ?? null;
        if (!is_string($rootAlias) || $rootAlias === '') {
            return;
        }

        $metricAlias = 'am_rank_order';
        $joins = $queryBuilder->getDQLPart('join');
        $hasJoin = false;
        if (isset($joins[$rootAlias])) {
            foreach ($joins[$rootAlias] as $join) {
                if ($join->getAlias() === $metricAlias) {
                    $hasJoin = true;
                    break;
                }
            }
        }

        if (!$hasJoin) {
            $queryBuilder->leftJoin($rootAlias . '.accountMetric', $metricAlias);
        }

        $queryBuilder
            ->addSelect(sprintf(
                'CASE WHEN %1$s.rankPosition IS NULL THEN 1 ELSE 0 END AS HIDDEN rankPositionEmptyOrder',
                $metricAlias
            ))
            ->addOrderBy('rankPositionEmptyOrder', 'ASC')
            ->addOrderBy($metricAlias . '.rankPosition', 'ASC')
            ->addOrderBy($rootAlias . '.id', 'ASC');
    }
}

