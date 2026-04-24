<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ProjectCategory;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * @implements ProviderInterface<list<ProjectCategory>>
 */
final class ProjectCategoryProvider implements ProviderInterface
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private readonly Connection $connection,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $rows = $this->connection->fetchFirstColumn(
            "SELECT DISTINCT category FROM projects WHERE category IS NOT NULL AND category <> '' ORDER BY category ASC"
        );

        $result = [];
        $id = 1;
        foreach ($rows as $category) {
            $name = trim((string) $category);
            if ($name === '') {
                continue;
            }

            $result[] = new ProjectCategory($id, $name);
            $id++;
        }

        return $result;
    }
}
