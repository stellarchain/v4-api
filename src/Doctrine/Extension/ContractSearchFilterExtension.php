<?php

namespace App\Doctrine\Extension;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Contract;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class ContractSearchFilterExtension implements QueryCollectionExtensionInterface
{
    private const MAX_CONTRACT_IDS_FILTER = 15;

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

        $filters = is_array($context['filters'] ?? null) ? $context['filters'] : [];

        $contractId = $this->trimNullableString($filters['contract_id'] ?? $filters['contractId'] ?? null);
        if ($contractId !== null) {
            $queryBuilder
                ->andWhere(sprintf('%s.contractId = :contract_id_filter', $rootAlias))
                ->setParameter('contract_id_filter', $contractId);
        }
        $contractIds = $this->normalizeContractIdsFilter($filters['contractIds'] ?? $filters['contract_ids'] ?? null);
        if ($contractIds !== []) {
            $queryBuilder
                ->andWhere(sprintf('%s.contractId IN (:contract_ids_filter)', $rootAlias))
                ->setParameter('contract_ids_filter', $contractIds);
        }

        $assetCode = $this->trimNullableString($filters['asset_code'] ?? $filters['assetCode'] ?? null);
        if ($assetCode !== null) {
            $queryBuilder
                ->andWhere(sprintf('%s.assetCode = :asset_code_filter', $rootAlias))
                ->setParameter('asset_code_filter', $assetCode);
        }

        $search = $this->trimNullableString($filters['search'] ?? $filters['q'] ?? null);
        if ($search !== null) {
            $verifiedAlias = 'contract_verified_metadata_search';
            if (!$this->hasJoinAlias($queryBuilder, $verifiedAlias)) {
                $queryBuilder->leftJoin(sprintf('%s.verifiedMetadata', $rootAlias), $verifiedAlias);
            }
            $needle = mb_strtolower($search);
            $queryBuilder
                ->andWhere(sprintf(
                    '(LOWER(%1$s.contractId) LIKE :contract_search OR LOWER(COALESCE(%1$s.assetCode, \'\')) LIKE :contract_search OR LOWER(COALESCE(%1$s.assetIssuer, \'\')) LIKE :contract_search OR LOWER(COALESCE(%2$s.displayName, \'\')) LIKE :contract_search OR LOWER(COALESCE(%2$s.symbol, \'\')) LIKE :contract_search)',
                    $rootAlias,
                    $verifiedAlias
                ))
                ->setParameter('contract_search', '%'.$needle.'%');
        }

        $sac = $this->parseNullableBool($filters['sac'] ?? $filters['isSac'] ?? null);
        if ($sac !== null) {
            $queryBuilder
                ->andWhere(sprintf('%s.isSac = :is_sac_filter', $rootAlias))
                ->setParameter('is_sac_filter', $sac);
        }

        $sourceCodeVerified = $this->parseNullableBool($filters['sourceCodeVerified'] ?? $filters['source_code_verified'] ?? null);
        if ($sourceCodeVerified !== null) {
            $queryBuilder
                ->andWhere(sprintf('%s.sourceCodeVerified = :source_code_verified_filter', $rootAlias))
                ->setParameter('source_code_verified_filter', $sourceCodeVerified);
        }
    }

    private function trimNullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function parseNullableBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private function normalizeContractIdsFilter(mixed $value): array
    {
        $values = [];
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '') {
                $values = str_contains($trimmed, ',')
                    ? array_map('trim', explode(',', $trimmed))
                    : [$trimmed];
            }
        } elseif (is_array($value)) {
            foreach ($value as $item) {
                if (!is_string($item)) {
                    continue;
                }

                $trimmed = trim($item);
                if ($trimmed !== '') {
                    $values[] = $trimmed;
                }
            }
        }

        if ($values === []) {
            return [];
        }

        $values = array_values(array_unique($values));
        if (count($values) > self::MAX_CONTRACT_IDS_FILTER) {
            throw new BadRequestHttpException(sprintf(
                'contractIds supports maximum %d values.',
                self::MAX_CONTRACT_IDS_FILTER
            ));
        }

        return $values;
    }

    private function hasJoinAlias(QueryBuilder $queryBuilder, string $alias): bool
    {
        foreach ($queryBuilder->getDQLPart('join') as $joins) {
            foreach ($joins as $join) {
                if ($join->getAlias() === $alias) {
                    return true;
                }
            }
        }

        return false;
    }
}
