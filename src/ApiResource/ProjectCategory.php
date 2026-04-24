<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\State\ProjectCategoryProvider;

#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/projects/categories',
            provider: ProjectCategoryProvider::class,
            openapi: new OpenApiOperation(tags: ['Projects'])
        ),
    ],
    cacheHeaders: [
        'max_age' => 3600,
        'shared_max_age' => 3600,
        'vary' => ['Accept', 'Content-Type', 'Origin'],
    ],
    paginationEnabled: false,
)]
final class ProjectCategory
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        private int $id,
        private string $name,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
