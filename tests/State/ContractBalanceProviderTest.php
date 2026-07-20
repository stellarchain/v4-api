<?php

declare(strict_types=1);

namespace App\Tests\State;

use App\Repository\ContractBalanceReadRepository;
use App\Service\Stellar\StellarNetworkResolver;
use App\State\ContractBalanceProvider;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class ContractBalanceProviderTest extends TestCase
{
    public function testProvideReturnsBalancesForContractAndNetwork(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->expects(self::exactly(2))
            ->method('fetchOne')
            ->willReturn(42, 42);
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->willReturn([
                [
                    'address' => 'GAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAWHF',
                    'balance_raw' => '2500',
                    'inflow_raw' => '3000',
                    'outflow_raw' => '500',
                ],
            ]);
        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn([
                'holders_count' => 1,
                'indexed_balance_raw' => '2500',
                'inflow_raw' => '3000',
                'outflow_raw' => '500',
            ]);
        $readRepository = new ContractBalanceReadRepository($connection);

        $requestStack = new RequestStack();
        $request = new Request([
            'network' => 'testnet',
            'limit' => '2',
        ]);
        $requestStack->push($request);

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

        $meta = $request->attributes->get('_cursor_meta');
        self::assertIsArray($meta);
        self::assertSame(2, $meta['limit']);
        self::assertSame(0, $meta['offset']);
        self::assertFalse($meta['hasMore']);
        self::assertSame([
            'holdersCount' => 1,
            'indexedBalanceRaw' => '2500',
            'inflowRaw' => '3000',
            'outflowRaw' => '500',
            'hasMore' => false,
        ], $meta['summary']);
    }

    public function testProvideReturnsEmptyWhenContractDoesNotExist(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))
            ->method('fetchOne')
            ->willReturn(false, false);
        $connection->expects(self::never())->method('fetchAllAssociative');
        $connection->expects(self::never())->method('fetchAssociative');
        $readRepository = new ContractBalanceReadRepository($connection);

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
