<?php

namespace App\Doctrine\Extension;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Account;
use Doctrine\ORM\QueryBuilder;

final class AccountTopWindowExtension implements QueryCollectionExtensionInterface
{
    private const DEFAULT_TOP = 1000;
    private const MAX_TOP = 5000;

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

        $addressFilter = $context['filters']['address'] ?? null;
        if (is_string($addressFilter) && trim($addressFilter) !== '') {
            return;
        }
        if (is_array($addressFilter) && $addressFilter !== []) {
            return;
        }
        $labelFilter = $context['filters']['label'] ?? null;
        if (is_string($labelFilter) && trim($labelFilter) !== '') {
            return;
        }

        $top = self::DEFAULT_TOP;
        $requestedTop = $context['filters']['top'] ?? null;
        if (is_numeric($requestedTop)) {
            $top = max(1, min(self::MAX_TOP, (int) $requestedTop));
        }

        $rootAliases = $queryBuilder->getRootAliases();
        $rootAlias = $rootAliases[0] ?? null;
        if (!is_string($rootAlias) || $rootAlias === '') {
            return;
        }

        $metricAlias = 'am_top_window';
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
            ->andWhere(sprintf('%s.rankPosition IS NOT NULL', $metricAlias))
            ->andWhere(sprintf('%s.rankPosition <= :account_top_window', $metricAlias))
            ->setParameter('account_top_window', $top);
    }
}
