<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter as OpenApiParameter;
use App\Entity\Contract;
use App\State\ContractArgumentUsagesProvider;

#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/contracts/{contractId}/argument-usages',
            provider: ContractArgumentUsagesProvider::class,
            uriVariables: [
                'contractId' => new Link(fromClass: Contract::class, identifiers: ['contractId']),
            ],
            openapi: new OpenApiOperation(
                tags: ['Contract'],
                summary: 'List transactions where this contract id appears in invoke arguments',
                parameters: [
                    new OpenApiParameter(
                        name: 'network',
                        in: 'query',
                        description: 'Filter by network (mainnet|testnet|futurenet). Default: mainnet.',
                        required: false,
                        schema: ['type' => 'string', 'enum' => ['mainnet', 'testnet', 'futurenet']]
                    ),
                    new OpenApiParameter(
                        name: 'beforeId',
                        in: 'query',
                        description: 'Keyset cursor: return rows with id < beforeId.',
                        required: false,
                        schema: ['type' => 'integer', 'minimum' => 1]
                    ),
                    new OpenApiParameter(
                        name: 'itemsPerPage',
                        in: 'query',
                        description: 'Items per page. Default: 30. Max: 200.',
                        required: false,
                        schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]
                    ),
                ]
            ),
            paginationEnabled: false
        ),
    ]
)]
final class ContractArgumentUsage
{
    /**
     * @param list<array{functionName:string,matchedPaths:list<string>}> $matches
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        private int $id,
        private string $txHash,
        private string $targetContractId,
        private ?string $sourceAccount,
        private ?int $ledger,
        private ?\DateTimeImmutable $createdAt,
        private int $matchesCount,
        private array $matches,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getTxHash(): string
    {
        return $this->txHash;
    }

    public function getTargetContractId(): string
    {
        return $this->targetContractId;
    }

    public function getSourceAccount(): ?string
    {
        return $this->sourceAccount;
    }

    public function getLedger(): ?int
    {
        return $this->ledger;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getMatchesCount(): int
    {
        return $this->matchesCount;
    }

    /**
     * @return list<array{functionName:string,matchedPaths:list<string>}>
     */
    public function getMatches(): array
    {
        return $this->matches;
    }
}
