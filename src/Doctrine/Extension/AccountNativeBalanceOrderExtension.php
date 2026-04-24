<?php

namespace App\Doctrine\Extension;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Account;
use Doctrine\ORM\QueryBuilder;

final class AccountNativeBalanceOrderExtension implements QueryCollectionExtensionInterface
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

        $order = $context['filters']['order']['accountMetric.nativeBalance'] ?? null;
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

        $metricAlias = 'am_order';
        $joins = $queryBuilder->getDQLPart('join');
        $hasMetricJoin = false;
        if (isset($joins[$rootAlias])) {
            foreach ($joins[$rootAlias] as $join) {
                if ($join->getAlias() === $metricAlias) {
                    $hasMetricJoin = true;
                    break;
                }
            }
        }
        if (!$hasMetricJoin) {
            $queryBuilder->leftJoin($rootAlias . '.accountMetric', $metricAlias);
        }

        $queryBuilder
            ->addSelect(sprintf(
                'CASE WHEN %1$s.nativeBalance IS NULL OR %1$s.nativeBalance = 0 THEN 1 ELSE 0 END AS HIDDEN nativeBalanceEmptyOrder',
                $metricAlias
            ))
            ->addOrderBy('nativeBalanceEmptyOrder', 'ASC')
            ->addOrderBy($metricAlias . '.nativeBalance', $direction);
    }
}

