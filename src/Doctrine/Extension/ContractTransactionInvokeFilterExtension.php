<?php

namespace App\Doctrine\Extension;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\ContractTransaction;
use Doctrine\ORM\QueryBuilder;

final class ContractTransactionInvokeFilterExtension implements QueryCollectionExtensionInterface
{
    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = []
    ): void {
        if ($resourceClass !== ContractTransaction::class) {
            return;
        }

        $rootAliases = $queryBuilder->getRootAliases();
        $rootAlias = $rootAliases[0] ?? null;
        if (!is_string($rootAlias) || $rootAlias === '') {
            return;
        }

        $filters = is_array($context['filters'] ?? null) ? $context['filters'] : [];
        $invocationsOnly = $this->parseNullableBool($filters['invocationsOnly'] ?? $filters['invocations_only'] ?? null);
        if ($invocationsOnly !== true) {
            return;
        }

        $queryBuilder
            ->andWhere(sprintf('%s.hostFunctions IS NOT NULL', $rootAlias))
            ->andWhere(sprintf('%s.hostFunctions <> :empty_host_functions', $rootAlias))
            ->andWhere(sprintf('%s.hostFunctions LIKE :invoke_contracts_any', $rootAlias))
            ->andWhere(sprintf('%s.hostFunctions NOT LIKE :invoke_contracts_empty', $rootAlias))
            ->setParameter('empty_host_functions', '')
            ->setParameter('invoke_contracts_any', '%"invokeContracts":[%')
            ->setParameter('invoke_contracts_empty', '%"invokeContracts":[]%');
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
}

