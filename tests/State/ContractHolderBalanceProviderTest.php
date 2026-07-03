<?php

declare(strict_types=1);

namespace App\Tests\State;

use App\Repository\ContractBalanceReadRepository;
use App\Service\Stellar\StellarNetworkResolver;
use App\State\ContractHolderBalanceProvider;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class ContractHolderBalanceProviderTest extends TestCase
{
    public function testProvideReturnsHolderBalancesAcrossContracts(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->expects(self::exactly(2))
            ->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls([
                [
                    'contract_id' => 101,
                    'balance_raw' => '229584058',
                    'inflow_raw' => '229584058',
                    'outflow_raw' => '0',
                ],
                [
                    'contract_id' => 102,
                    'balance_raw' => '1',
                    'inflow_raw' => '1',
                    'outflow_raw' => '0',
                ],
            ], [
                [
                    'id' => 101,
                    'contract_id' => 'CBNKCU3HGFKHFOF7JTGXQCNKE3G3DXS5RDBQUKQMIIECYKXPIOUGB2S3',
                ],
                [
                    'id' => 102,
                    'contract_id' => 'CCW67TSZV3SSS2HXMBQ5JFGCKJNXKZM7UQUWUZPUTHXSTZLEO7SJMI75',
                ],
            ]);
        $readRepository = new ContractBalanceReadRepository($connection);

        $requestStack = new RequestStack();
        $request = new Request([
            'network' => 'mainnet',
            'limit' => '1',
        ]);
        $requestStack->push($request);

        $provider = new ContractHolderBalanceProvider($readRepository, new StellarNetworkResolver(), $requestStack);
        $result = $provider->provide(
            operation: $this->createMock(\ApiPlatform\Metadata\Operation::class),
            uriVariables: ['contractId' => 'CDYYTZZ7J2ADE6UVYZ4P37PJY25LVIG3EUKEMI4JNJSM4QVEYSITUHIU']
        );

        self::assertCount(1, $result);
        self::assertSame('CBNKCU3HGFKHFOF7JTGXQCNKE3G3DXS5RDBQUKQMIIECYKXPIOUGB2S3', $result[0]->getRelatedContractId());
        self::assertSame('229584058', $result[0]->getBalanceRaw());
        self::assertSame('229584058', $result[0]->getInflowRaw());
        self::assertSame('0', $result[0]->getOutflowRaw());

        $meta = $request->attributes->get('_cursor_meta');
        self::assertIsArray($meta);
        self::assertSame(1, $meta['limit']);
        self::assertSame(0, $meta['offset']);
        self::assertSame(1, $meta['nextOffset']);
        self::assertTrue($meta['hasMore']);
        self::assertSame(['hasMore' => true], $meta['summary']);
    }
}
