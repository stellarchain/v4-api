<?php

declare(strict_types=1);

namespace App\Tests\State;

use App\Repository\ContractBalanceReadRepository;
use App\Service\Stellar\StellarNetworkResolver;
use App\State\ContractHolderBalanceProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class ContractHolderBalanceProviderTest extends TestCase
{
    public function testProvideReturnsHolderBalancesAcrossContracts(): void
    {
        $readRepository = $this->createMock(ContractBalanceReadRepository::class);
        $readRepository->expects(self::once())
            ->method('findHolderBalancesAcrossContracts')
            ->with('CDYYTZZ7J2ADE6UVYZ4P37PJY25LVIG3EUKEMI4JNJSM4QVEYSITUHIU', 1, 10)
            ->willReturn([
                [
                    'related_contract_id' => 'CBNKCU3HGFKHFOF7JTGXQCNKE3G3DXS5RDBQUKQMIIECYKXPIOUGB2S3',
                    'balance_raw' => '229584058',
                    'inflow_raw' => '229584058',
                    'outflow_raw' => '0',
                ],
            ]);

        $requestStack = new RequestStack();
        $requestStack->push(new Request([
            'network' => 'mainnet',
            'limit' => '10',
        ]));

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
    }
}

