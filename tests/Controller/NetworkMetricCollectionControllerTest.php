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
                int $itemsPerPage,
                int $windowDays,
                ?string $before = null
            ): array {
                $this->calls[] = [$network, $metricKey, $bucketMinutes, $page, $itemsPerPage, $windowDays, $before];

                return [
                    'network' => 'mainnet',
                    'networkCode' => 1,
                    'metricKey' => 'transactions',
                    'bucketMinutes' => 60,
                    'page' => 2,
                    'itemsPerPage' => 10,
                    'totalItems' => 25,
                    'window' => [
                        'start' => '2026-05-01T00:00:00+00:00',
                        'end' => '2026-05-31T00:00:00+00:00',
                        'olderBefore' => null,
                        'newerBefore' => null,
                        'isLatest' => true,
                    ],
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
            '/v1/network-metrics?network=mainnet&metricKey=transactions&bucketMinutes=60&page=2&itemsPerPage=10&windowDays=30'
        ));
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame([['mainnet', 'transactions', 60, 2, 10, 30, null]], $service->calls);
        self::assertSame(25, $payload['totalItems']);
        self::assertSame('2026-05-01T00:00:00+00:00', $payload['window']['start']);
        self::assertSame('198617', $payload['member'][0]['valueDecimal']);
        self::assertArrayHasKey('previous', $payload['view']);
        self::assertArrayHasKey('next', $payload['view']);
        self::assertStringContainsString('windowDays=30', $payload['view']['next']);
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

    public function testItRejectsMisleadingActiveAddressResampling(): void
    {
        $controller = new NetworkMetricCollectionController($this->unusedService(), new NetworkMetricCatalog());
        foreach ([60, 1440] as $bucketMinutes) {
            $response = $controller(Request::create('/v1/network-metrics', 'GET', [
                'metricKey' => 'active-addresses', 'bucketMinutes' => $bucketMinutes,
            ]));
            self::assertSame(422, $response->getStatusCode());
            self::assertSame('metric_aggregation_not_available', json_decode((string) $response->getContent(), true)['error']['type']);
        }
    }

    public function testItRequiresBoundedWindowBeforeCallingReader(): void
    {
        $controller = new NetworkMetricCollectionController($this->unusedService(), new NetworkMetricCatalog());
        $response = $controller(Request::create('/v1/network-metrics?metricKey=transactions'));

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('window_required', json_decode((string) $response->getContent(), true)['error']['type']);
        self::assertStringContainsString('windowDays', json_decode((string) $response->getContent(), true)['error']['message']);
    }

    public function testItRejectsMissingWindowOrMisalignedWindowCursor(): void
    {
        $controller = new NetworkMetricCollectionController($this->unusedService(), new NetworkMetricCatalog());

        $withoutWindow = $controller(Request::create('/v1/network-metrics?metricKey=transactions&before=2026-05-15T00:00:00Z'));
        $misaligned = $controller(Request::create('/v1/network-metrics?metricKey=transactions&windowDays=30&bucketMinutes=60&before=2026-05-15T00:05:00Z'));
        $invalidDate = $controller(Request::create('/v1/network-metrics?metricKey=transactions&windowDays=30&before=2026-02-30T00:00:00Z'));
        $tooLarge = $controller(Request::create('/v1/network-metrics?metricKey=transactions&windowDays=31'));

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $withoutWindow->getStatusCode());
        self::assertSame(Response::HTTP_BAD_REQUEST, $misaligned->getStatusCode());
        self::assertSame(Response::HTTP_BAD_REQUEST, $invalidDate->getStatusCode());
        self::assertSame(Response::HTTP_BAD_REQUEST, $tooLarge->getStatusCode());
    }

    public function testItForwardsBoundedWindowAndReturnsWindowMetadata(): void
    {
        $service = new class implements NetworkMetricSeriesReadServiceInterface {
            public array $calls = [];

            public function read(
                string $network,
                ?string $metricKey,
                int $bucketMinutes,
                int $page,
                int $itemsPerPage,
                int $windowDays,
                ?string $before = null
            ): array {
                $this->calls[] = [$windowDays, $before];

                return [
                    'network' => $network,
                    'networkCode' => 1,
                    'metricKey' => $metricKey,
                    'bucketMinutes' => $bucketMinutes,
                    'page' => $page,
                    'itemsPerPage' => $itemsPerPage,
                    'totalItems' => 0,
                    'window' => [
                        'start' => '2026-05-14T00:00:00+00:00',
                        'end' => '2026-05-15T00:00:00+00:00',
                        'olderBefore' => null,
                        'newerBefore' => null,
                        'isLatest' => true,
                    ],
                    'items' => [],
                ];
            }
        };
        $controller = new NetworkMetricCollectionController($service, new NetworkMetricCatalog());
        $response = $controller(Request::create('/v1/network-metrics?metricKey=transactions&windowDays=1&before=2026-05-15T00:00:00Z'));
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame([[1, '2026-05-15T00:00:00Z']], $service->calls);
        self::assertSame('2026-05-14T00:00:00+00:00', $payload['window']['start']);
    }

    public function testItMapsUnavailableStatisticsToServiceUnavailable(): void
    {
        $service = new class implements NetworkMetricSeriesReadServiceInterface {
            public function read(
                string $network,
                ?string $metricKey,
                int $bucketMinutes,
                int $page,
                int $itemsPerPage,
                int $windowDays,
                ?string $before = null
            ): array {
                throw new StatisticsUnavailableException('Unavailable.');
            }
        };

        $controller = new NetworkMetricCollectionController($service, new NetworkMetricCatalog());
        $response = $controller(Request::create('/v1/network-metrics?metricKey=transactions&windowDays=30'));

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        self::assertSame('statistics_unavailable', json_decode((string) $response->getContent(), true)['error']['type']);
    }

    public function testItRejectsPageNumbersThatOverflowAnInteger(): void
    {
        $controller = new NetworkMetricCollectionController($this->unusedService(), new NetworkMetricCatalog());
        $response = $controller(Request::create('/v1/network-metrics?metricKey=transactions&windowDays=30&page=999999999999999999999999'));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('invalid_page', json_decode((string) $response->getContent(), true)['error']['type']);
    }

    public function testItStillAcceptsLeadingZeroPageNumbers(): void
    {
        $service = new class implements NetworkMetricSeriesReadServiceInterface {
            public function read(
                string $network,
                ?string $metricKey,
                int $bucketMinutes,
                int $page,
                int $itemsPerPage,
                int $windowDays,
                ?string $before = null
            ): array {
                TestCase::assertSame(1, $page);

                return [
                    'network' => $network, 'networkCode' => 1, 'metricKey' => $metricKey,
                    'bucketMinutes' => $bucketMinutes, 'page' => $page,
                    'itemsPerPage' => $itemsPerPage, 'totalItems' => 0,
                    'window' => null, 'items' => [],
                ];
            }
        };
        $controller = new NetworkMetricCollectionController($service, new NetworkMetricCatalog());
        $response = $controller(Request::create('/v1/network-metrics?metricKey=transactions&windowDays=30&page=01'));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testOutOfRangePageLinksBackToTheLastValidPage(): void
    {
        $service = new class implements NetworkMetricSeriesReadServiceInterface {
            public function read(
                string $network,
                ?string $metricKey,
                int $bucketMinutes,
                int $page,
                int $itemsPerPage,
                int $windowDays,
                ?string $before = null
            ): array {
                return [
                    'network' => $network, 'networkCode' => 1, 'metricKey' => $metricKey,
                    'bucketMinutes' => $bucketMinutes, 'page' => $page,
                    'itemsPerPage' => $itemsPerPage, 'totalItems' => 3,
                    'window' => null, 'items' => [],
                ];
            }
        };
        $controller = new NetworkMetricCollectionController($service, new NetworkMetricCatalog());
        $response = $controller(Request::create('/v1/network-metrics?metricKey=transactions&windowDays=30&page=999&itemsPerPage=2'));
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('page=2', $payload['view']['previous']);
        self::assertArrayNotHasKey('next', $payload['view']);
    }

    private function unusedService(): NetworkMetricSeriesReadServiceInterface
    {
        return new class implements NetworkMetricSeriesReadServiceInterface {
            public function read(
                string $network,
                ?string $metricKey,
                int $bucketMinutes,
                int $page,
                int $itemsPerPage,
                int $windowDays,
                ?string $before = null
            ): array {
                throw new \LogicException('The service should not be called.');
            }
        };
    }
}
