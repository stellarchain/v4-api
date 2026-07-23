<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\NetworkMetricCollectionController;
use App\Exception\StatisticsUnavailableException;
use App\Service\Statistics\NetworkMetricCatalog;
use App\Service\Statistics\NetworkMetricSeriesReadServiceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

final class NetworkMetricCollectionControllerTest extends TestCase
{
    public function testItReturnsAggregatedMetricCollection(): void
    {
        $service = new class implements NetworkMetricSeriesReadServiceInterface {
            public array $calls = [];

            public function read(
                string $network,
                ?string $metricKey,
                int $bucketMinutes,
                int $page,
                int $itemsPerPage
            ): array {
                $this->calls[] = [$network, $metricKey, $bucketMinutes, $page, $itemsPerPage];

                return [
                    'network' => 'mainnet',
                    'networkCode' => 1,
                    'metricKey' => 'transactions',
                    'bucketMinutes' => 60,
                    'page' => 2,
                    'itemsPerPage' => 10,
                    'totalItems' => 25,
                    'items' => [[
                        'metricKey' => 'transactions',
                        'bucketMinutes' => 60,
                        'valueDecimal' => '198617',
                    ]],
                ];
            }
        };

        $controller = new NetworkMetricCollectionController($service, new NetworkMetricCatalog());
        $response = $controller(Request::create(
            '/v1/network-metrics?network=mainnet&metricKey=transactions&bucketMinutes=60&page=2&itemsPerPage=10'
        ));
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame([['mainnet', 'transactions', 60, 2, 10]], $service->calls);
        self::assertSame(25, $payload['totalItems']);
        self::assertSame('198617', $payload['member'][0]['valueDecimal']);
        self::assertArrayHasKey('previous', $payload['view']);
        self::assertArrayHasKey('next', $payload['view']);
    }

    public function testItRejectsUnknownMetric(): void
    {
        $controller = new NetworkMetricCollectionController($this->unusedService(), new NetworkMetricCatalog());
        $response = $controller(Request::create('/v1/network-metrics?metricKey=unknown'));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('invalid_metric_key', json_decode((string) $response->getContent(), true)['error']['type']);
    }

    public function testItRejectsDocumentedButUnavailableMetric(): void
    {
        $controller = new NetworkMetricCollectionController($this->unusedService(), new NetworkMetricCatalog());
        $response = $controller(Request::create('/v1/network-metrics?metricKey=top-payers'));

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('metric_not_available', json_decode((string) $response->getContent(), true)['error']['type']);
    }

    public function testItRequiresMetricKey(): void
    {
        $controller = new NetworkMetricCollectionController($this->unusedService(), new NetworkMetricCatalog());
        $response = $controller(Request::create('/v1/network-metrics'));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('missing_metric_key', json_decode((string) $response->getContent(), true)['error']['type']);
    }

    public function testItRejectsUnsupportedBucket(): void
    {
        $controller = new NetworkMetricCollectionController($this->unusedService(), new NetworkMetricCatalog());
        $response = $controller(Request::create('/v1/network-metrics?metricKey=transactions&bucketMinutes=7'));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('invalid_bucket_minutes', json_decode((string) $response->getContent(), true)['error']['type']);
    }

    public function testItMapsUnavailableStatisticsToServiceUnavailable(): void
    {
        $service = new class implements NetworkMetricSeriesReadServiceInterface {
            public function read(
                string $network,
                ?string $metricKey,
                int $bucketMinutes,
                int $page,
                int $itemsPerPage
            ): array {
                throw new StatisticsUnavailableException('Unavailable.');
            }
        };

        $controller = new NetworkMetricCollectionController($service, new NetworkMetricCatalog());
        $response = $controller(Request::create('/v1/network-metrics?metricKey=transactions'));

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        self::assertSame('statistics_unavailable', json_decode((string) $response->getContent(), true)['error']['type']);
    }

    private function unusedService(): NetworkMetricSeriesReadServiceInterface
    {
        return new class implements NetworkMetricSeriesReadServiceInterface {
            public function read(
                string $network,
                ?string $metricKey,
                int $bucketMinutes,
                int $page,
                int $itemsPerPage
            ): array {
                throw new \LogicException('The service should not be called.');
            }
        };
    }
}
