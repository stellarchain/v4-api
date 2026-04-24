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
use App\State\ContractHolderBalanceProvider;

#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/contracts/{contractId}/holder-balances',
            provider: ContractHolderBalanceProvider::class,
            uriVariables: [
                'contractId' => new Link(fromClass: Contract::class, identifiers: ['contractId']),
            ],
            openapi: new OpenApiOperation(
                tags: ['Contract'],
                summary: 'Get balances where contractId acts as holder/address in other contracts',
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
                        description: 'Max number of contracts returned (1..200). Default: 50.',
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
    ]
)]
final class ContractHolderBalance
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        private string $relatedContractId,
        private string $balanceRaw,
        private string $inflowRaw,
        private string $outflowRaw,
    ) {
    }

    public function getRelatedContractId(): string
    {
        return $this->relatedContractId;
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
