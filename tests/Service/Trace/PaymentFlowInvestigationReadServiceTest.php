<?php

declare(strict_types=1);

namespace App\Tests\Service\Trace;

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

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function statisticsConnectionWithRows(array $rows): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchOne')
            ->willReturnCallback(static function (string $sql, array $params = [], array $types = []): mixed {
                if (str_contains($sql, 'information_schema.tables')) {
                    return 1;
                }
                if (str_contains($sql, 'payment_flow_address')) {
                    return 10;
                }

                return null;
            });
        $connection
            ->method('fetchAllAssociative')
            ->willReturn($rows);

        return $connection;
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
