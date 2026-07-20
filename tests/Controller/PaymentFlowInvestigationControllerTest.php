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
                int $limit
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
                int $limit
            ): array {
                throw new StatisticsUnavailableException('No table.');
            }
        };

        $controller = new PaymentFlowInvestigationController($service);
        $response = $controller(Request::create('/v1/payment-flow/investigation?q=GDUY7J7A33TQWOSOQGDO776GGLM3UQERL4J3SPT56F6YS4ID7MLDERI4'));

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        self::assertSame('statistics_unavailable', json_decode((string) $response->getContent(), true)['error']['type']);
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
                int $limit
            ): array {
                throw new \LogicException('The service should not be called.');
            }
        };
    }
}
