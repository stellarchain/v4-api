<?php

declare(strict_types=1);

namespace App\Tests\State;

use ApiPlatform\Metadata\Operation;
use App\Repository\ContractArgumentUsageReadRepository;
use App\Service\Stellar\StellarNetworkResolver;
use App\State\ContractArgumentUsagesProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class ContractArgumentUsagesProviderTest extends TestCase
{
    public function testProvideReturnsNestedArgumentMatchesWithMeta(): void
    {
        $targetContractId = 'CDYYTZZ7J2ADE6UVYZ4P37PJY25LVIG3EUKEMI4JNJSM4QVEYSITUHIU';

        $readRepository = $this->createMock(ContractArgumentUsageReadRepository::class);
        $readRepository->expects(self::once())
            ->method('contractExists')
            ->with($targetContractId, 1)
            ->willReturn(true);
        $readRepository->expects(self::once())
            ->method('countArgumentUsages')
            ->with($targetContractId, 1)
            ->willReturn(1);
        $readRepository->expects(self::once())
            ->method('loadCandidateRows')
            ->willReturn([
                [
                    'id' => 901,
                    'tx_hash' => '46c4edd05d612d1ef7a093afae401bec2ecc67e0aa6522fade124163d72b6376',
                    'source_account' => 'GBGVK3U6E7UWVLUDZWVICWZ6L5IWJ7YSDHAE5SRW6UXFSOACS7OU4YJS',
                    'function_name' => 'distribute',
                    'matched_paths' => json_encode(['$.args[2][0].address'], JSON_UNESCAPED_SLASHES),
                    'matches_count' => 1,
                    'ledger' => 61426367,
                    'created_at' => '2026-02-27 18:45:00',
                    'target_contract_id' => 'CCW67TSZV3SSS2HXMBQ5JFGCKJNXKZM7UQUWUZPUTHXSTZLEO7SJMI75',
                ],
            ]);

        $request = new Request([
            'network' => 'mainnet',
            'itemsPerPage' => '30',
        ]);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $provider = new ContractArgumentUsagesProvider(
            $readRepository,
            new StellarNetworkResolver(),
            $requestStack
        );

        $result = $provider->provide(
            $this->createMock(Operation::class),
            ['contractId' => $targetContractId]
        );

        self::assertCount(1, $result);
        self::assertSame(901, $result[0]->getId());
        self::assertSame('distribute', $result[0]->getMatches()[0]['functionName']);
        self::assertSame(1, $result[0]->getMatchesCount());
        self::assertSame('$.args[2][0].address', $result[0]->getMatches()[0]['matchedPaths'][0]);

        $meta = $request->attributes->get('_cursor_meta');
        self::assertIsArray($meta);
        self::assertSame(1, $meta['total']);
        self::assertFalse($meta['hasMore']);
    }
}
