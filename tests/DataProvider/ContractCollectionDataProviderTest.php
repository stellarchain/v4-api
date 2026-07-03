<?php

declare(strict_types=1);

namespace App\Tests\DataProvider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\HasNextPagePaginatorInterface;
use ApiPlatform\State\Pagination\PaginatorInterface;
use App\DataProvider\ContractCollectionDataProvider;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class ContractCollectionDataProviderTest extends TestCase
{
    public function testProvideReturnsExactTotalAndNormalizedRows(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with(
                self::callback(static fn (string $sql): bool => str_contains($sql, 'COUNT(DISTINCT c.id)')),
                self::callback(static fn (array $params): bool => ($params['network'] ?? null) === 1
                    && ($params['is_sac'] ?? null) === true
                    && ($params['source_code_verified'] ?? null) === true
                    && ($params['search'] ?? null) === '%CLPX%'
                    && !array_key_exists('limit_rows', $params)
                    && !array_key_exists('offset_rows', $params)),
                self::anything()
            )
            ->willReturn(3);
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::callback(static fn (string $sql): bool => str_contains($sql, 'ORDER BY c.total_invokes DESC NULLS LAST, c.id DESC')),
                self::callback(static fn (array $params): bool => ($params['limit_rows'] ?? null) === 1
                    && ($params['offset_rows'] ?? null) === 1
                    && ($params['search'] ?? null) === '%CLPX%'),
                self::anything()
            )
            ->willReturn([
                [
                    'id' => 12,
                    'contract_id' => 'CBDRPADR3KIBJNUBNRTTO4P7NO5RVPMYKRJB5YCZUZ6B66RKYK324UJY',
                    'network' => 1,
                    'asset_code' => 'CLPX',
                    'asset_issuer' => 'GDYISSUER',
                    'created_at' => '2026-01-01 00:00:00',
                    'deployed_at' => '2026-01-01 00:00:00',
                    'is_sac' => true,
                    'source_code_verified' => true,
                    'sep55_verified' => false,
                    'total_transactions' => 2,
                    'total_operations' => 2,
                    'total_events' => 1,
                    'total_storage_entries' => 0,
                    'total_invokes' => 2,
                    'verified_display_name' => 'CLPX',
                    'verified_metadata_type' => 'sep41',
                    'verified_is_sep41' => true,
                    'verified_symbol' => 'CLPX',
                    'verified_decimals' => 7,
                    'verified_metadata_is_verified' => true,
                    'verified_website' => 'https://example.com',
                    'verified_description' => 'Token',
                    'verified_icon_url' => 'https://example.com/icon.png',
                    'verified_added_at' => '2026-01-02',
                    'verified_raw_payload' => '{"name":"CLPX"}',
                    'verified_source_name' => 'stellar.toml',
                    'source_code_sha256' => 'abc',
                    'wasm_blob_sha256' => 'def',
                    'source_status' => 1,
                    'source_error_message' => null,
                    'source_decompiled_at' => '2026-01-03 00:00:00',
                ],
            ]);

        $requestStack = new RequestStack();
        $requestStack->push(new Request([
            'network' => 'mainnet',
            'page' => '2',
            'itemsPerPage' => '1',
            'sac' => 'true',
            'sourceCodeVerified' => 'true',
            'search' => 'CLPX',
            'order' => ['totalInvokes' => 'desc'],
        ]));

        $provider = new ContractCollectionDataProvider($connection, new StellarNetworkResolver(), $requestStack);
        $result = $provider->provide($this->createMock(Operation::class));

        self::assertInstanceOf(PaginatorInterface::class, $result);
        self::assertInstanceOf(HasNextPagePaginatorInterface::class, $result);
        self::assertSame(3.0, $result->getTotalItems());
        self::assertSame(2.0, $result->getCurrentPage());
        self::assertSame(3.0, $result->getLastPage());
        self::assertTrue($result->hasNextPage());
        self::assertCount(1, $result);

        $items = iterator_to_array($result);
        self::assertIsObject($items[0]);
        self::assertTrue($items[0]->isSac);
        self::assertSame('CLPX', $items[0]->assetCode);
        self::assertIsObject($items[0]->verifiedMetadata);
        self::assertTrue($items[0]->verifiedMetadata->isSep41);
        self::assertIsObject($items[0]->verifiedMetadata->rawPayload);
        self::assertSame('CLPX', $items[0]->verifiedMetadata->rawPayload->name);
    }
}
