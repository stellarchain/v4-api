<?php

declare(strict_types=1);

namespace App\Doctrine\Extension;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use App\Entity\MarketAssetSnapshot;
use Doctrine\ORM\QueryBuilder;

final class MarketAssetSnapshotSearchFilterExtension implements QueryCollectionExtensionInterface
{
    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?\ApiPlatform\Metadata\Operation $operation = null,
        array $context = [],
    ): void {
        if ($resourceClass !== MarketAssetSnapshot::class) {
            return;
        }

        $filters = is_array($context['filters'] ?? null) ? $context['filters'] : [];
        $search = $this->trimNullable($filters['search'] ?? $filters['q'] ?? null);
        $code = $this->trimNullable($filters['code'] ?? null);
        $issuer = $this->trimNullable($filters['issuer'] ?? null);
        $orgName = $this->trimNullable($filters['orgName'] ?? null);

        if ($search === null && $code === null && $issuer === null && $orgName === null) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0] ?? 'o';
        $assetAlias = $this->ensureAssetJoin($queryBuilder, $rootAlias, $queryNameGenerator);

        if ($search !== null) {
            $searchLower = mb_strtolower($search);
            $escapedSearch = addcslashes($searchLower, '%_');
            $orX = $queryBuilder->expr()->orX(
                sprintf('LOWER(%s.code) LIKE :market_search_code', $assetAlias),
                sprintf('LOWER(COALESCE(%s.issuer, \'\')) LIKE :market_search_issuer', $assetAlias),
                sprintf('%s.tomlInfo LIKE :market_search_org_name', $assetAlias),
                sprintf('%s.tomlInfo LIKE :market_search_org_name_lc', $assetAlias),
            );
            $queryBuilder
                ->andWhere($orX)
                ->setParameter('market_search_code', '%' . $escapedSearch . '%')
                ->setParameter('market_search_issuer', '%' . $escapedSearch . '%')
                ->setParameter('market_search_org_name', '%"ORG_NAME"%' . $search . '%')
                ->setParameter('market_search_org_name_lc', '%"org_name"%' . $searchLower . '%');
        }

        if ($code !== null) {
            $queryBuilder
                ->andWhere(sprintf('LOWER(%s.code) LIKE :market_code_filter', $assetAlias))
                ->setParameter('market_code_filter', '%' . mb_strtolower($code) . '%');
        }

        if ($issuer !== null) {
            $queryBuilder
                ->andWhere(sprintf('%s.issuer = :market_issuer_filter', $assetAlias))
                ->setParameter('market_issuer_filter', $issuer);
        }

        if ($orgName !== null) {
            $escapedOrgName = addcslashes($orgName, '%_');
            $queryBuilder
                ->andWhere(sprintf('(%s.tomlInfo LIKE :market_org_name_filter OR %s.tomlInfo LIKE :market_org_name_filter_lc)', $assetAlias, $assetAlias))
                ->setParameter('market_org_name_filter', '%"ORG_NAME"%' . $escapedOrgName . '%')
                ->setParameter('market_org_name_filter_lc', '%"org_name"%' . mb_strtolower($escapedOrgName) . '%');
        }
    }

    private function ensureAssetJoin(
        QueryBuilder $queryBuilder,
        string $rootAlias,
        QueryNameGeneratorInterface $queryNameGenerator
    ): string {
        $joinPart = $queryBuilder->getDQLPart('join');
        $rootJoins = is_array($joinPart[$rootAlias] ?? null) ? $joinPart[$rootAlias] : [];

        foreach ($rootJoins as $join) {
            if ($join->getJoin() === $rootAlias . '.asset') {
                return $join->getAlias();
            }
        }

        $assetAlias = $queryNameGenerator->generateJoinAlias('asset');
        $queryBuilder->innerJoin($rootAlias . '.asset', $assetAlias);

        return $assetAlias;
    }

    private function trimNullable(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
