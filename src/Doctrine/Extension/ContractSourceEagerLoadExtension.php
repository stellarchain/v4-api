<?php

declare(strict_types=1);

namespace App\Doctrine\Extension;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Contract;
use Doctrine\ORM\QueryBuilder;

/**
 * Eagerly loads ContractSource relationship to prevent identity map conflicts
 * when multiple contracts reference the same ContractSource via wasm_id.
 */
final class ContractSourceEagerLoadExtension implements QueryCollectionExtensionInterface
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

        // Contract no longer has ORM association to ContractSource because contracts.wasm_id
        // references a non-PK business key in contract_sources. Source snapshot is loaded
        // separately in provider layer.
        return;
    }
}
