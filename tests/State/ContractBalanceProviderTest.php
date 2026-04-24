<?php

declare(strict_types=1);

namespace App\Tests\State;

use App\Repository\ContractBalanceReadRepository;
use App\Service\Stellar\StellarNetworkResolver;
use App\State\ContractBalanceProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class ContractBalanceProviderTest extends TestCase
{
    public function testProvideReturnsBalancesForContractAndNetwork(): void
    {
        $readRepository = $this->createMock(ContractBalanceReadRepository::class);
        $readRepository->expects(self::once())
            ->method('findBalancesByContract')
            ->with('CDUMMYCONTRACT', 2, 2)
            ->willReturn([
                [
                    'address' => 'GAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAWHF',
                    'balance_raw' => '2500',
                    'inflow_raw' => '3000',
                    'outflow_raw' => '500',
                ],
            ]);

        $requestStack = new RequestStack();
        $requestStack->push(new Request([
            'network' => 'testnet',
            'limit' => '2',
        ]));

        $provider = new ContractBalanceProvider($readRepository, new StellarNetworkResolver(), $requestStack);
        $result = $provider->provide(
            operation: $this->createMock(\ApiPlatform\Metadata\Operation::class),
            uriVariables: ['contractId' => 'cDummyContract']
        );

        self::assertCount(1, $result);
        self::assertSame('GAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAWHF', $result[0]->getAddress());
        self::assertSame('2500', $result[0]->getBalanceRaw());
        self::assertSame('3000', $result[0]->getInflowRaw());
        self::assertSame('500', $result[0]->getOutflowRaw());
    }

    public function testProvideReturnsEmptyWhenContractDoesNotExist(): void
    {
        $readRepository = $this->createMock(ContractBalanceReadRepository::class);
        $readRepository->expects(self::once())
            ->method('findBalancesByContract')
            ->willReturn([]);

        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        $provider = new ContractBalanceProvider($readRepository, new StellarNetworkResolver(), $requestStack);
        $result = $provider->provide(
            operation: $this->createMock(\ApiPlatform\Metadata\Operation::class),
            uriVariables: ['contractId' => 'CDUMMYCONTRACT']
        );

        self::assertSame([], $result);
    }
}

