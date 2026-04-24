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
use App\State\ContractBalanceProvider;

#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/contracts/{contractId}/balances',
            provider: ContractBalanceProvider::class,
            uriVariables: [
                'contractId' => new Link(fromClass: Contract::class, identifiers: ['contractId']),
            ],
            openapi: new OpenApiOperation(
                tags: ['Contract'],
                summary: 'Get contract holder balances computed from contract events',
                parameters: [
                    new OpenApiParameter(
                        name: 'network',
                        in: 'query',
                        description: 'Filter by network (mainnet|testnet|futurenet). Default: mainnet.',
                        required: false,
                        schema: ['type' => 'string', 'enum' => ['mainnet', 'testnet', 'futurenet']]
                    ),
                    new OpenApiParameter(
                        name: 'limit',
                        in: 'query',
                        description: 'Max number of holders returned (1..200). Default: 50.',
                        required: false,
                        schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]
                    ),
                    new OpenApiParameter(
                        name: 'offset',
                        in: 'query',
                        description: 'Row offset for pagination. Default: 0.',
                        required: false,
                        schema: ['type' => 'integer', 'minimum' => 0]
                    ),
                ]
            ),
            paginationEnabled: false
        ),
    ],
    cacheHeaders: [
        'max_age' => 60,
        'shared_max_age' => 60,
        'vary' => ['Accept', 'Content-Type', 'Origin'],
    ],
)]
final class ContractBalance
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        private string $address,
        private string $balanceRaw,
        private string $inflowRaw,
        private string $outflowRaw,
    ) {
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    public function getBalanceRaw(): string
    {
        return $this->balanceRaw;
    }

    public function getInflowRaw(): string
    {
        return $this->inflowRaw;
    }

    public function getOutflowRaw(): string
    {
        return $this->outflowRaw;
    }
}
