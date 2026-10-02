<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\PaymentFlowInvestigationController;
use App\Exception\StatisticsUnavailableException;
use App\Service\Trace\PaymentFlowInvestigationReadServiceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

final class PaymentFlowInvestigationControllerTest extends TestCase
{
    public function testItUsesQueryAddressAndDefaults(): void
    {
        $address = 'GDUY7J7A33TQWOSOQGDO776GGLM3UQERL4J3SPT56F6YS4ID7MLDERI4';
        $service = new class implements PaymentFlowInvestigationReadServiceInterface {
            public array $calls = [];

            public function read(
                string $network,
                ?string $address,
                ?string $txHash,
                ?int $ledgerFrom,
                ?int $ledgerTo,
                string $direction,
                int $limit,
                ?string $cursor = null,
                ?string $operationType = null,
                ?string $asset = null,
                ?string $dateFrom = null,
                ?string $dateTo = null,
                ?string $minAssetAmount = null,
                int $depth = 1
            ): array {
                $this->calls[] = [$network, $address, $txHash, $ledgerFrom, $ledgerTo, $direction, $limit];

                return [
                    'network' => 'mainnet',
                    'query' => ['address' => $address],
                    'coverage' => ['rowsReturned' => 0],
                    'summary' => ['events' => 0],
                    'riskContext' => ['level' => 'limited'],
                    'graph' => ['nodes' => [], 'edges' => []],
                    'counterparties' => [],
                    'events' => [],
                ];
            }
        };

        $controller = new PaymentFlowInvestigationController($service);
        $response = $controller(Request::create('/v1/payment-flow/investigation?q=' . $address));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame([['mainnet', $address, null, null, null, 'both', 100]], $service->calls);
    }

    public function testItRejectsMissingTarget(): void
    {
        $controller = new PaymentFlowInvestigationController($this->unusedService());
        $response = $controller(Request::create('/v1/payment-flow/investigation'));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('missing_target', json_decode((string) $response->getContent(), true)['error']['type']);
    }

    public function testItAcceptsAnExactAssetAsTheOnlySearchTarget(): void
    {
        $service = $this->createMock(PaymentFlowInvestigationReadServiceInterface::class);
        $service->expects(self::once())->method('read')->with(
            'mainnet', null, null, null, null, 'both', 100, null, null, 'native:XLM'
        )->willReturn([]);

        $response = (new PaymentFlowInvestigationController($service))(Request::create(
            '/v1/payment-flow/investigation',
            'GET',
            ['q' => 'native:XLM']
        ));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testItRejectsInvalidDirection(): void
    {
        $controller = new PaymentFlowInvestigationController($this->unusedService());
        $response = $controller(Request::create('/v1/payment-flow/investigation?q=GDUY7J7A33TQWOSOQGDO776GGLM3UQERL4J3SPT56F6YS4ID7MLDERI4&direction=sideways'));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('invalid_direction', json_decode((string) $response->getContent(), true)['error']['type']);
    }

    public function testItRejectsInvalidLedgerRange(): void
    {
        $controller = new PaymentFlowInvestigationController($this->unusedService());
        $response = $controller(Request::create('/v1/payment-flow/investigation?q=GDUY7J7A33TQWOSOQGDO776GGLM3UQERL4J3SPT56F6YS4ID7MLDERI4&ledgerFrom=20&ledgerTo=10'));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('invalid_ledger_range', json_decode((string) $response->getContent(), true)['error']['type']);
    }

    public function testItMapsStatisticsUnavailableToServiceUnavailable(): void
    {
        $service = new class implements PaymentFlowInvestigationReadServiceInterface {
            public function read(
                string $network,
                ?string $address,
                ?string $txHash,
                ?int $ledgerFrom,
                ?int $ledgerTo,
                string $direction,
                int $limit,
                ?string $cursor = null,
                ?string $operationType = null,
                ?string $asset = null,
                ?string $dateFrom = null,
                ?string $dateTo = null,
                ?string $minAssetAmount = null,
                int $depth = 1
            ): array {
                throw new StatisticsUnavailableException('No table.');
            }
        };

        $controller = new PaymentFlowInvestigationController($service);
        $response = $controller(Request::create('/v1/payment-flow/investigation?q=GDUY7J7A33TQWOSOQGDO776GGLM3UQERL4J3SPT56F6YS4ID7MLDERI4'));

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        self::assertSame('statistics_unavailable', json_decode((string) $response->getContent(), true)['error']['type']);
    }

    public function testMalformedOrOverflowingLedgerFiltersAreRejected(): void
    {
        $controller = new PaymentFlowInvestigationController($this->unusedService());
        foreach (['abc', '0', '-1', '2147483648', '9999999999999999999999', ''] as $value) {
            $response = $controller(Request::create('/v1/payment-flow/investigation', 'GET', [
                'txHash' => str_repeat('a', 64), 'ledgerFrom' => $value,
            ]));
            self::assertSame(400, $response->getStatusCode());
        }
    }

    public function testUnsupportedOperationIsRejected(): void
    {
        $controller = new PaymentFlowInvestigationController($this->unusedService());
        $response = $controller(Request::create('/v1/payment-flow/investigation', 'GET', [
            'txHash' => str_repeat('a', 64), 'operationType' => 'invoke_host_function',
        ]));
        self::assertSame(400, $response->getStatusCode());
    }

    public function testItRejectsInvalidAssetAndPassesValidAssetToReader(): void
    {
        $address = 'GDUY7J7A33TQWOSOQGDO776GGLM3UQERL4J3SPT56F6YS4ID7MLDERI4';
        $controller = new PaymentFlowInvestigationController($this->unusedService());
        $invalid = $controller(Request::create('/v1/payment-flow/investigation', 'GET', [
            'address' => $address, 'asset' => 'credit_alphanum4:TOOLONG:' . $address,
        ]));
        self::assertSame(400, $invalid->getStatusCode());
        self::assertSame('invalid_asset', json_decode((string) $invalid->getContent(), true)['error']['type']);

        $service = $this->createMock(PaymentFlowInvestigationReadServiceInterface::class);
        $service->expects(self::once())->method('read')->with(
            'mainnet', $address, null, null, null, 'both', 100, null, null, 'native:XLM'
        )->willReturn([]);
        $response = (new PaymentFlowInvestigationController($service))(Request::create('/v1/payment-flow/investigation', 'GET', [
            'address' => $address, 'asset' => 'native:XLM',
        ]));
        self::assertSame(200, $response->getStatusCode());
    }

    public function testMinimumAssetAmountRequiresAssetAndExactPositiveDecimal(): void
    {
        $address = 'GDUY7J7A33TQWOSOQGDO776GGLM3UQERL4J3SPT56F6YS4ID7MLDERI4';
        $controller = new PaymentFlowInvestigationController($this->unusedService());
        foreach ([
            ['asset' => '', 'minAssetAmount' => '0.01'],
            ['asset' => 'native:XLM', 'minAssetAmount' => '0'],
            ['asset' => 'native:XLM', 'minAssetAmount' => '1e-7'],
            ['asset' => 'native:XLM', 'minAssetAmount' => '0.000000000000001'],
        ] as $filter) {
            $response = $controller(Request::create('/v1/payment-flow/investigation', 'GET', ['address' => $address] + $filter));
            self::assertSame(400, $response->getStatusCode());
            self::assertSame('invalid_min_asset_amount', json_decode((string) $response->getContent(), true)['error']['type']);
        }

        $service = $this->createMock(PaymentFlowInvestigationReadServiceInterface::class);
        $service->expects(self::once())->method('read')->with(
            'mainnet', $address, null, null, null, 'both', 100, null, null,
            'native:XLM', null, null, '0.0000001'
        )->willReturn([]);
        $response = (new PaymentFlowInvestigationController($service))(Request::create('/v1/payment-flow/investigation', 'GET', [
            'address' => $address, 'asset' => 'native:XLM', 'minAssetAmount' => '0.0000001',
        ]));
        self::assertSame(200, $response->getStatusCode());
    }

    public function testItValidatesAndPassesInclusiveUtcDateRange(): void
    {
        $address = 'GDUY7J7A33TQWOSOQGDO776GGLM3UQERL4J3SPT56F6YS4ID7MLDERI4';
        $controller = new PaymentFlowInvestigationController($this->unusedService());
        foreach ([['2026-02-30', ''], ['2026-05-16', '2026-05-15'], ['', '2026-5-15']] as [$from, $to]) {
            $response = $controller(Request::create('/v1/payment-flow/investigation', 'GET', [
                'address' => $address, 'dateFrom' => $from, 'dateTo' => $to,
            ]));
            self::assertSame(400, $response->getStatusCode());
            self::assertSame('invalid_date_range', json_decode((string) $response->getContent(), true)['error']['type']);
        }

        $service = $this->createMock(PaymentFlowInvestigationReadServiceInterface::class);
        $service->expects(self::once())->method('read')->with(
            'mainnet', $address, null, null, null, 'both', 100, null, null, null, '2026-05-15', '2026-05-15'
        )->willReturn([]);
        $response = (new PaymentFlowInvestigationController($service))(Request::create('/v1/payment-flow/investigation', 'GET', [
            'address' => $address, 'dateFrom' => '2026-05-15', 'dateTo' => '2026-05-15',
        ]));
        self::assertSame(200, $response->getStatusCode());
    }

    public function testReadOnlyTraceAliasesBindPathTargetAndEnforceBoundedTwoHopScope(): void
    {
        $address = 'GDUY7J7A33TQWOSOQGDO776GGLM3UQERL4J3SPT56F6YS4ID7MLDERI4';
        $hash = str_repeat('a', 64);
        $service = $this->createMock(PaymentFlowInvestigationReadServiceInterface::class);
        $service->expects(self::exactly(2))->method('read')->willReturn([]);
        $controller = new PaymentFlowInvestigationController($service);

        $addressRequest = Request::create('/v1/trace/address/' . $address, 'GET', [
            'q' => $hash, 'txHash' => $hash, 'depth' => '1', 'direction' => 'outgoing',
        ]);
        self::assertSame(200, $controller->traceAddress($addressRequest, $address)->getStatusCode());
        self::assertSame($address, $addressRequest->query->get('address'));
        self::assertFalse($addressRequest->query->has('txHash'));

        $txRequest = Request::create('/v1/trace/tx/' . $hash, 'GET', [
            'q' => $address, 'address' => $address, 'depth' => '1',
        ]);
        self::assertSame(200, $controller->traceTransaction($txRequest, $hash)->getStatusCode());
        self::assertSame($hash, $txRequest->query->get('txHash'));
        self::assertFalse($txRequest->query->has('address'));

        $unbounded = $controller->traceAddress(Request::create('/v1/trace/address/' . $address, 'GET', ['depth' => '2']), $address);
        self::assertSame(422, $unbounded->getStatusCode());
        self::assertSame('depth_range_required', json_decode((string) $unbounded->getContent(), true)['error']['type']);

        $twoHopService = $this->createMock(PaymentFlowInvestigationReadServiceInterface::class);
        $twoHopService->expects(self::once())->method('read')->with(
            'mainnet', $address, null, 100, 200, 'both', 100,
            null, null, null, null, null, null, 2
        )->willReturn([]);
        $twoHopController = new PaymentFlowInvestigationController($twoHopService);
        $twoHopRequest = Request::create('/v1/trace/address/' . $address, 'GET', [
            'depth' => '2', 'ledgerFrom' => '100', 'ledgerTo' => '200',
        ]);
        self::assertSame(200, $twoHopController->traceAddress($twoHopRequest, $address)->getStatusCode());

        $transactionDepth = $controller->traceTransaction(
            Request::create('/v1/trace/tx/' . $hash, 'GET', [
                'depth' => '2', 'ledgerFrom' => '100', 'ledgerTo' => '200',
            ]),
            $hash
        );
        self::assertSame(422, $transactionDepth->getStatusCode());
        self::assertSame('depth_target_not_available', json_decode((string) $transactionDepth->getContent(), true)['error']['type']);
    }

    private function unusedService(): PaymentFlowInvestigationReadServiceInterface
    {
        return new class implements PaymentFlowInvestigationReadServiceInterface {
            public function read(
                string $network,
                ?string $address,
                ?string $txHash,
                ?int $ledgerFrom,
                ?int $ledgerTo,
                string $direction,
                int $limit,
                ?string $cursor = null,
                ?string $operationType = null,
                ?string $asset = null,
                ?string $dateFrom = null,
                ?string $dateTo = null,
                ?string $minAssetAmount = null,
                int $depth = 1
            ): array {
                throw new \LogicException('The service should not be called.');
            }
        };
    }
}
