<?php

declare(strict_types=1);

namespace App\Tests\DataProvider;

use ApiPlatform\Metadata\Operation;
use App\DataProvider\ContractItemDataProvider;
use App\Service\Stellar\Soroban\ContractRpcFallbackResolverInterface;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class ContractItemDataProviderTest extends TestCase
{
    private const CONTRACT_ID = 'CCJTPZVVYSHDFTJV23GXXCAGDIIA2GCC36T35L6WMB4VT3NR4R6NRHLG';
    private const CONTRACT_ID_HEX = '9337e6b5c48e32cd35d6cd7b88061a100d1842dfa7beafd6607959edb1e47cd8';

    public function testFallsBackToRpcWhenContractIsMissingFromDatabase(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn(false);

        $rpcFallbackResolver = $this->createMock(ContractRpcFallbackResolverInterface::class);
        $rpcFallbackResolver->expects(self::once())
            ->method('resolve')
            ->with(self::CONTRACT_ID, 'mainnet')
            ->willReturn([
                'contractId' => self::CONTRACT_ID,
                'contractIdHex' => self::CONTRACT_ID_HEX,
                'wasmId' => '8ae1550db6f33311e473c1e853d192dd72623cc02c993608a86382469473fa91',
                'executableType' => 0,
                'isSac' => false,
            ]);

        $result = $this->createProvider($connection, $rpcFallbackResolver)->provide(
            $this->createMock(Operation::class),
            ['contractId' => strtolower(self::CONTRACT_ID)],
        );

        self::assertIsObject($result);
        self::assertSame(self::CONTRACT_ID, $result->contractId);
        self::assertSame(self::CONTRACT_ID_HEX, $result->contractIdHex);
        self::assertSame('mainnet', $result->network);
        self::assertSame('8ae1550db6f33311e473c1e853d192dd72623cc02c993608a86382469473fa91', $result->wasmId);
        self::assertSame(0, $result->executableType);
        self::assertFalse($result->isSac);
        self::assertSame(0, $result->totalTransactions);
        self::assertSame(0, $result->totalEvents);
        self::assertSame('unverified', $result->verificationStatus);
    }

    public function testReturnsNullWhenContractIsMissingFromDatabaseAndRpc(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn(false);

        $rpcFallbackResolver = $this->createMock(ContractRpcFallbackResolverInterface::class);
        $rpcFallbackResolver->expects(self::once())
            ->method('resolve')
            ->with(self::CONTRACT_ID, 'mainnet')
            ->willReturn(null);

        $result = $this->createProvider($connection, $rpcFallbackResolver)->provide(
            $this->createMock(Operation::class),
            ['contractId' => self::CONTRACT_ID],
        );

        self::assertNull($result);
    }

    private function createProvider(
        Connection $connection,
        ContractRpcFallbackResolverInterface $rpcFallbackResolver,
    ): ContractItemDataProvider {
        $requestStack = new RequestStack();
        $requestStack->push(new Request(['network' => 'mainnet']));

        return new ContractItemDataProvider(
            $connection,
            new StellarNetworkResolver(),
            $requestStack,
            $rpcFallbackResolver,
        );
    }
}
