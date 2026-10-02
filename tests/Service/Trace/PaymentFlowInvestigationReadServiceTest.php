<?php

declare(strict_types=1);

namespace App\Tests\Service\Trace;

use App\Exception\StatisticsUnavailableException;
use App\Service\Stellar\StellarNetworkResolver;
use App\Service\Trace\PaymentFlowAccountMetadataReadServiceInterface;
use App\Service\Trace\PaymentFlowInvestigationReadService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

final class PaymentFlowInvestigationReadServiceTest extends TestCase
{
    public const FOCUS_ADDRESS = 'GDUY7J7A33TQWOSOQGDO776GGLM3UQERL4J3SPT56F6YS4ID7MLDERI4';
    public const COUNTERPARTY_ADDRESS = 'GCG2UOYGTCNL73ARZS4Z3HWN3L7UR3GPEMRXMQ7KLMR7A4JBHOP3GO7V';

    public function testItEnrichesPaymentFlowPayloadWithAccountMetadata(): void
    {
        $metadataService = new class implements PaymentFlowAccountMetadataReadServiceInterface {
            /** @var list<string> */
            public array $requestedAddresses = [];

            public function readByAddresses(int $networkCode, array $addresses): array
            {
                TestCase::assertSame(1, $networkCode);
                $this->requestedAddresses = $addresses;

                return [
                    PaymentFlowInvestigationReadServiceTest::FOCUS_ADDRESS => [
                        'address' => PaymentFlowInvestigationReadServiceTest::FOCUS_ADDRESS,
                        'known' => true,
                        'label' => 'Known Destination',
                        'verified' => true,
                        'orgName' => null,
                        'createdAt' => '2026-05-15T10:00:00+00:00',
                        'updatedAt' => '2026-05-15T10:05:00+00:00',
                        'firstTransactionAt' => '2026-05-01T00:00:00+00:00',
                        'lastTransactionAt' => '2026-05-15T10:05:00+00:00',
                        'totalTransactions' => '42',
                        'paymentsCount' => '20',
                        'tradesCount' => '3',
                        'nativeBalance' => '123.4567000',
                        'rankPosition' => 12,
                        'rankScore' => '99.00000000',
                        'metricUpdatedAt' => '2026-05-15T10:05:00+00:00',
                    ],
                    PaymentFlowInvestigationReadServiceTest::COUNTERPARTY_ADDRESS => [
                        'address' => PaymentFlowInvestigationReadServiceTest::COUNTERPARTY_ADDRESS,
                        'known' => true,
                        'label' => null,
                        'verified' => false,
                        'orgName' => null,
                        'createdAt' => null,
                        'updatedAt' => null,
                        'firstTransactionAt' => '2026-05-10T00:00:00+00:00',
                        'lastTransactionAt' => '2026-05-15T10:00:00+00:00',
                        'totalTransactions' => '8',
                        'paymentsCount' => '8',
                        'tradesCount' => '0',
                        'nativeBalance' => '0',
                        'rankPosition' => null,
                        'rankScore' => '0',
                        'metricUpdatedAt' => null,
                    ],
                ];
            }
        };

        $service = new PaymentFlowInvestigationReadService(
            $this->statisticsConnectionWithRows([$this->paymentFlowRow()]),
            new StellarNetworkResolver(),
            $metadataService
        );

        $payload = $service->read('mainnet', self::FOCUS_ADDRESS, null, null, null, 'both', 50);

        self::assertContains(self::FOCUS_ADDRESS, $metadataService->requestedAddresses);
        self::assertContains(self::COUNTERPARTY_ADDRESS, $metadataService->requestedAddresses);
        self::assertSame('Known Destination', $payload['accounts'][self::FOCUS_ADDRESS]['label']);
        self::assertTrue($payload['accountContext']['focusAccount']['verified']);
        self::assertSame(2, $payload['accountContext']['knownAccounts']);
        self::assertSame(1, $payload['accountContext']['labeledAccounts']);
        self::assertSame(1, $payload['accountContext']['verifiedAccounts']);
        self::assertSame('Known Destination', $payload['graph']['nodes'][0]['label']);
        self::assertSame('Known Destination', $payload['events'][0]['toAccount']['label']);
        self::assertSame(self::COUNTERPARTY_ADDRESS, $payload['events'][0]['fromAccount']['address']);
    }

    public function testItKeepsFlowPayloadWhenAccountMetadataLookupFails(): void
    {
        $metadataService = new class implements PaymentFlowAccountMetadataReadServiceInterface {
            public function readByAddresses(int $networkCode, array $addresses): array
            {
                throw new \RuntimeException('Main DB unavailable.');
            }
        };

        $service = new PaymentFlowInvestigationReadService(
            $this->statisticsConnectionWithRows([$this->paymentFlowRow()]),
            new StellarNetworkResolver(),
            $metadataService
        );

        $payload = $service->read('mainnet', self::FOCUS_ADDRESS, null, null, null, 'both', 50);

        self::assertSame(1, $payload['summary']['events']);
        self::assertSame([], $payload['accounts']);
        self::assertTrue($payload['accountContext']['metadataUnavailable']);
        self::assertContains(
            'Account labels and first/last transaction metadata are temporarily unavailable from the main application database.',
            $payload['riskContext']['limitations']
        );
        self::assertSame(0, $payload['riskContext']['score']);
    }

    public function testEmptyFilteredPageDoesNotClaimTheTargetHasNoActivity(): void
    {
        $metadataService = $this->createMock(PaymentFlowAccountMetadataReadServiceInterface::class);
        $metadataService->method('readByAddresses')->willReturn([]);
        $service = new PaymentFlowInvestigationReadService(
            $this->statisticsConnectionWithRows([]),
            new StellarNetworkResolver(),
            $metadataService
        );

        $payload = $service->read('mainnet', self::FOCUS_ADDRESS, null, 70000000, null, 'both', 50);

        self::assertSame(0, $payload['summary']['events']);
        self::assertSame('No matching payment-flow rows', $payload['riskContext']['signals'][0]['label']);
        self::assertStringContainsString('selected filters', $payload['riskContext']['signals'][0]['description']);
        self::assertStringContainsString('does not prove', $payload['riskContext']['signals'][0]['description']);
    }

    public function testItPreservesExactAmountsInEventsAndPageAggregates(): void
    {
        $first = $this->paymentFlowRow();
        $first['source_amount_decimal'] = '12345678901234567890.12345678901234';
        $first['destination_amount_decimal'] = '12345678901234567890.12345678901234';

        $second = $this->paymentFlowRow();
        $second['id'] = 1002;
        $second['operation_id'] = '268341957822431234';
        $second['source_amount_decimal'] = '0.00000000000001';
        $second['destination_amount_decimal'] = '0.00000000000001';

        $outgoing = $this->paymentFlowRow();
        $outgoing['id'] = 1003;
        $outgoing['operation_id'] = '268341957822431235';
        $outgoing['from_address'] = self::FOCUS_ADDRESS;
        $outgoing['to_address'] = self::COUNTERPARTY_ADDRESS;
        $outgoing['source_amount_decimal'] = '0.5';
        $outgoing['destination_amount_decimal'] = '0.5';

        $metadataService = $this->createMock(PaymentFlowAccountMetadataReadServiceInterface::class);
        $metadataService->method('readByAddresses')->willReturn([]);
        $service = new PaymentFlowInvestigationReadService(
            $this->statisticsConnectionWithRows([$first, $second, $outgoing]),
            new StellarNetworkResolver(),
            $metadataService
        );

        $payload = $service->read('mainnet', self::FOCUS_ADDRESS, null, null, null, 'both', 50);

        self::assertSame('12345678901234567890.12345678901234', $payload['events'][0]['sourceAmount']);
        self::assertSame('12345678901234567890.12345678901234', $payload['events'][0]['destinationAmount']);
        self::assertSame('0.00000000000001', $payload['events'][1]['destinationAmount']);
        self::assertSame('12345678901234567890.12345678901235', $payload['summary']['nativeReceived']);
        self::assertSame('0.5', $payload['summary']['nativeSent']);
        self::assertSame('12345678901234567890.12345678901235', $payload['graph']['edges'][0]['totalXlm']);
        self::assertSame('12345678901234567890.12345678901235', $payload['counterparties'][0]['nativeReceived']);
        self::assertSame('0.5', $payload['counterparties'][0]['nativeSent']);
        self::assertSame('12345678901234567890.12345678901235', $payload['flowGroups'][0]['sourceAmountTotal']);
        self::assertSame('12345678901234567890.12345678901235', $payload['flowGroups'][0]['destinationAmountTotal']);
        self::assertSame(2, $payload['flowGroups'][0]['events']);
        self::assertCount(2, $payload['flowGroups']);
    }

    public function testAssetFilterChecksBothSidesBeforePagination(): void
    {
        $metadataService = $this->createMock(PaymentFlowAccountMetadataReadServiceInterface::class);
        $metadataService->method('readByAddresses')->willReturn([]);
        $service = new PaymentFlowInvestigationReadService(
            $this->statisticsConnectionWithRows([$this->paymentFlowRow()], '(e.source_asset_id = :asset_id OR e.destination_asset_id = :asset_id)'),
            new StellarNetworkResolver(),
            $metadataService
        );

        $payload = $service->read('mainnet', self::FOCUS_ADDRESS, null, null, null, 'both', 50, null, null, 'native:XLM');

        self::assertSame('native:XLM', $payload['query']['asset']);
        self::assertSame(1, $payload['summary']['events']);
    }

    public function testAssetOnlySearchUsesTheSideIndexAndLabelsItsCoverage(): void
    {
        $metadataService = $this->createMock(PaymentFlowAccountMetadataReadServiceInterface::class);
        $metadataService->method('readByAddresses')->willReturn([]);
        $service = new PaymentFlowInvestigationReadService(
            $this->statisticsConnectionWithRows(
                [$this->paymentFlowRow()],
                'payment_flow_asset_side s',
                ['network' => 1, 'asset_id' => 20, 'limit' => 51, 'ledger_from' => 90, 'ledger_to' => 100]
            ),
            new StellarNetworkResolver(),
            $metadataService
        );

        $payload = $service->read('mainnet', null, null, null, null, 'both', 50, null, null, 'native:XLM');

        self::assertSame('asset', $payload['query']['targetType']);
        self::assertSame('native:XLM', $payload['query']['asset']);
        self::assertSame('both', $payload['events'][0]['assetMatch']);
        self::assertSame(1, $payload['summary']['incomingEvents']);
        self::assertSame(1, $payload['summary']['outgoingEvents']);
        self::assertSame(90, $payload['coverage']['assetIndexFirstBuiltLedger']);
        self::assertSame(100, $payload['coverage']['assetIndexLatestBuiltLedger']);
        self::assertStringContainsString('Coverage may contain gaps', $payload['coverage']['note']);
    }

    public function testAssetOnlySearchRefusesAnUnbuiltSideIndex(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchOne')
            ->willReturnCallback([self::class, 'fetchOneFixtureWithoutAssetCoverage']);
        $connection->method('fetchAllAssociative')->willReturn([]);
        $metadataService = $this->createMock(PaymentFlowAccountMetadataReadServiceInterface::class);
        $service = new PaymentFlowInvestigationReadService(
            $connection,
            new StellarNetworkResolver(),
            $metadataService
        );

        $this->expectException(StatisticsUnavailableException::class);
        $this->expectExceptionMessage('Asset investigation index has no built coverage.');

        $service->read('mainnet', null, null, null, null, 'both', 50, null, null, 'native:XLM');
    }

    public function testBoundedTwoHopTraceReturnsAssetContinuousCandidatePath(): void
    {
        $root = $this->paymentFlowRow();
        $root['ledger'] = 100;
        $root['from_address_id'] = 10;
        $root['to_address_id'] = 11;
        $root['from_address'] = self::FOCUS_ADDRESS;
        $root['to_address'] = self::COUNTERPARTY_ADDRESS;

        $second = $this->paymentFlowRow();
        $second['id'] = 1002;
        $second['ledger'] = 101;
        $second['operation_id'] = '268341957822431234';
        $second['from_address_id'] = 11;
        $second['to_address_id'] = 12;
        $second['from_address'] = self::COUNTERPARTY_ADDRESS;
        $second['to_address'] = 'GSECONDHOPDESTINATION';

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturnCallback([self::class, 'fetchOneFixture']);
        $connection->method('fetchAllAssociative')->willReturnOnConsecutiveCalls([$root], [$second]);
        $metadataService = $this->createMock(PaymentFlowAccountMetadataReadServiceInterface::class);
        $metadataService->method('readByAddresses')->willReturn([]);
        $service = new PaymentFlowInvestigationReadService($connection, new StellarNetworkResolver(), $metadataService);

        $payload = $service->read(
            'mainnet',
            self::FOCUS_ADDRESS,
            null,
            100,
            200,
            'outgoing',
            50,
            null,
            null,
            null,
            null,
            null,
            null,
            2
        );

        self::assertSame(2, $payload['query']['depth']);
        self::assertSame(2, $payload['trace']['depthReturned']);
        self::assertSame(1, $payload['trace']['frontierAccounts']);
        self::assertFalse($payload['trace']['truncated']);
        self::assertCount(1, $payload['trace']['candidatePaths']);
        self::assertSame(['1001', '1002'], $payload['trace']['candidatePaths'][0]['eventIds']);
        self::assertSame('native:XLM', $payload['trace']['candidatePaths'][0]['asset']['key']);
        self::assertCount(2, $payload['graph']['edges']);
        self::assertCount(3, $payload['graph']['nodes']);
    }

    public function testMinimumAssetAmountIsAppliedBeforePaginationAndRecordedInQuery(): void
    {
        $metadataService = $this->createMock(PaymentFlowAccountMetadataReadServiceInterface::class);
        $metadataService->method('readByAddresses')->willReturn([]);
        $service = new PaymentFlowInvestigationReadService(
            $this->statisticsConnectionWithRows(
                [$this->paymentFlowRow()],
                'e.destination_amount_decimal >= CAST(:min_asset_amount AS NUMERIC)',
                ['network' => 1, 'limit' => 51, 'address_id' => 10, 'asset_id' => 20, 'min_asset_amount' => '0.0000001']
            ),
            new StellarNetworkResolver(),
            $metadataService
        );

        $payload = $service->read('mainnet', self::FOCUS_ADDRESS, null, null, null, 'both', 50, null, null, 'native:XLM', null, null, '0.0000001');

        self::assertSame('0.0000001', $payload['query']['minAssetAmount']);
        self::assertSame(1, $payload['summary']['events']);
    }

    public function testCursorCannotBeReusedWithDifferentMinimumAssetAmount(): void
    {
        $rows = [$this->paymentFlowRow(), $this->paymentFlowRow()];
        $rows[1]['id'] = 1000;
        $metadataService = $this->createMock(PaymentFlowAccountMetadataReadServiceInterface::class);
        $metadataService->method('readByAddresses')->willReturn([]);
        $service = new PaymentFlowInvestigationReadService(
            $this->statisticsConnectionWithRows($rows), new StellarNetworkResolver(), $metadataService
        );
        $cursor = $service->read('mainnet', self::FOCUS_ADDRESS, null, null, null, 'both', 1, null, null, 'native:XLM', null, null, '0.1')['coverage']['nextCursor'];

        $this->expectException(\InvalidArgumentException::class);
        $service->read('mainnet', self::FOCUS_ADDRESS, null, null, null, 'both', 1, $cursor, null, 'native:XLM', null, null, '0.2');
    }

    public function testGroupedFlowMarksUnknownAmountsWithoutMixingAssetPairs(): void
    {
        $first = $this->paymentFlowRow();
        $second = $this->paymentFlowRow();
        $second['id'] = 1002;
        $second['source_amount_decimal'] = null;
        $third = $this->paymentFlowRow();
        $third['id'] = 1003;
        $third['source_asset_type'] = 'credit_alphanum4';
        $third['source_asset_code'] = 'USD';
        $third['source_asset_issuer'] = self::COUNTERPARTY_ADDRESS;

        $metadataService = $this->createMock(PaymentFlowAccountMetadataReadServiceInterface::class);
        $metadataService->method('readByAddresses')->willReturn([]);
        $service = new PaymentFlowInvestigationReadService(
            $this->statisticsConnectionWithRows([$first, $second, $third]),
            new StellarNetworkResolver(),
            $metadataService
        );

        $groups = $service->read('mainnet', self::FOCUS_ADDRESS, null, null, null, 'both', 50)['flowGroups'];

        self::assertCount(2, $groups);
        self::assertSame(2, $groups[0]['events']);
        self::assertNull($groups[0]['sourceAmountTotal']);
        self::assertSame('50', $groups[0]['destinationAmountTotal']);
        self::assertSame('credit_alphanum4:USD:' . self::COUNTERPARTY_ADDRESS, $groups[1]['sourceAsset']['key']);
    }

    public function testCursorCannotBeReusedWithDifferentAssetFilter(): void
    {
        $rows = [$this->paymentFlowRow(), $this->paymentFlowRow()];
        $rows[1]['id'] = 1000;
        $metadataService = $this->createMock(PaymentFlowAccountMetadataReadServiceInterface::class);
        $metadataService->method('readByAddresses')->willReturn([]);
        $service = new PaymentFlowInvestigationReadService(
            $this->statisticsConnectionWithRows($rows),
            new StellarNetworkResolver(),
            $metadataService
        );
        $cursor = $service->read('mainnet', self::FOCUS_ADDRESS, null, null, null, 'both', 1)['coverage']['nextCursor'];
        self::assertNotNull($cursor);

        $this->expectException(\InvalidArgumentException::class);
        $service->read('mainnet', self::FOCUS_ADDRESS, null, null, null, 'both', 1, $cursor, null, 'native:XLM');
    }

    public function testUtcDayFiltersUseLedgerIndexBoundsBeforeEventPagination(): void
    {
        $row = $this->paymentFlowRow();
        $row['ledger'] = 20;
        $connection = $this->statisticsConnectionWithRows(
            [$row],
            'e.ledger >= :ledger_from',
            ['network' => 1, 'limit' => 51, 'address_id' => 10, 'ledger_from' => 20, 'ledger_to' => 29]
        );
        $connection->method('fetchAssociative')->willReturnCallback([self::class, 'fetchTransactionAtOrAfterLedger']);
        $metadataService = $this->createMock(PaymentFlowAccountMetadataReadServiceInterface::class);
        $metadataService->method('readByAddresses')->willReturn([]);
        $service = new PaymentFlowInvestigationReadService($connection, new StellarNetworkResolver(), $metadataService);

        $payload = $service->read('mainnet', self::FOCUS_ADDRESS, null, null, null, 'both', 50, null, null, null, '2026-05-15', '2026-05-15');

        self::assertSame('2026-05-15', $payload['query']['dateFrom']);
        self::assertSame('2026-05-15', $payload['query']['dateTo']);
        self::assertSame(1, $payload['summary']['events']);
    }

    public static function fetchTransactionAtOrAfterLedger(string $sql, array $params): array|false
    {
        $rows = [
            ['ledger' => 10, 'closed_at' => '2026-05-14 23:59:59'],
            ['ledger' => 20, 'closed_at' => '2026-05-15 10:38:33'],
            ['ledger' => 30, 'closed_at' => '2026-05-16 00:00:00'],
        ];
        if (str_contains($sql, 'ORDER BY ledger DESC')) {
            return $rows[2];
        }
        foreach ($rows as $row) {
            if ($row['ledger'] >= $params['ledger']) {
                return $row;
            }
        }

        return false;
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function statisticsConnectionWithRows(array $rows, ?string $expectedSqlFragment = null, ?array $expectedParams = null): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchOne')
            ->willReturnCallback([self::class, 'fetchOneFixture']);
        if ($expectedSqlFragment !== null) {
            $expectation = $connection->expects(self::once())->method('fetchAllAssociative');
            if ($expectedParams !== null) {
                $expectation->with(self::stringContains($expectedSqlFragment), $expectedParams)->willReturn($rows);
            } else {
                $expectation->with(self::stringContains($expectedSqlFragment))->willReturn($rows);
            }
        } else {
            $connection->method('fetchAllAssociative')->willReturn($rows);
        }

        return $connection;
    }

    public static function fetchOneFixture(string $sql): mixed
    {
        if (str_contains($sql, 'information_schema.tables')) {
            return 1;
        }
        if (str_contains($sql, 'payment_flow_asset_side_build_ledger') && str_contains($sql, 'ASC')) {
            return 90;
        }
        if (str_contains($sql, 'payment_flow_asset_side_build_ledger')) {
            return 100;
        }
        if (str_contains($sql, 'payment_flow_address')) {
            return 10;
        }
        if (str_contains($sql, 'payment_flow_asset')) {
            return 20;
        }

        return null;
    }

    public static function fetchOneFixtureWithoutAssetCoverage(string $sql): mixed
    {
        if (str_contains($sql, 'payment_flow_asset_side_build_ledger')) {
            return null;
        }

        return self::fetchOneFixture($sql);
    }

    /**
     * @return array<string,mixed>
     */
    private function paymentFlowRow(): array
    {
        return [
            'id' => 1001,
            'ledger' => 62577983,
            'operation_id' => '268341957822431233',
            'operation_index' => 1,
            'operation_type' => 'payment',
            'successful' => true,
            'from_address_id' => 11,
            'to_address_id' => 10,
            'source_amount_decimal' => '25.0000000',
            'destination_amount_decimal' => '25.0000000',
            'closed_at' => '2026-05-15 10:38:33',
            'tx_hash' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'memo_type' => 'text',
            'memo' => 'evidence',
            'source_account' => self::COUNTERPARTY_ADDRESS,
            'from_address' => self::COUNTERPARTY_ADDRESS,
            'to_address' => self::FOCUS_ADDRESS,
            'source_asset_type' => 'native',
            'source_asset_code' => null,
            'source_asset_issuer' => null,
            'destination_asset_type' => 'native',
            'destination_asset_code' => null,
            'destination_asset_issuer' => null,
        ];
    }
}
