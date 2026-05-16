<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\NetworkStatisticsController;
use App\Exception\StatisticsUnavailableException;
use App\Service\Statistics\NetworkStatisticsReadServiceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

final class NetworkStatisticsControllerTest extends TestCase
{
    public function testItUsesDefaultQueryParameters(): void
    {
        $service = new class implements NetworkStatisticsReadServiceInterface {
            public array $calls = [];

            public function read(string $network, string $range, int $bucketMinutes): array
            {
                $this->calls[] = [$network, $range, $bucketMinutes];

                return [
                    'network' => 'mainnet',
                    'range' => '7d',
                    'bucketMinutes' => 5,
                    'coverage' => ['bucketCount' => 0],
                    'sections' => [],
                    'chart' => ['points' => []],
                ];
            }
        };

        $controller = new NetworkStatisticsController($service);
        $response = $controller(Request::create('/v1/statistics/network'));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame([['mainnet', '7d', 5]], $service->calls);
        self::assertSame('mainnet', json_decode((string) $response->getContent(), true)['network']);
    }

    public function testItRejectsInvalidRange(): void
    {
        $controller = new NetworkStatisticsController($this->unusedService());
        $response = $controller(Request::create('/v1/statistics/network?range=90d'));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('invalid_range', json_decode((string) $response->getContent(), true)['error']['type']);
    }

    public function testItRejectsInvalidNetwork(): void
    {
        $controller = new NetworkStatisticsController($this->unusedService());
        $response = $controller(Request::create('/v1/statistics/network?network=badnet'));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('invalid_network', json_decode((string) $response->getContent(), true)['error']['type']);
    }

    public function testItRejectsInvalidBucketMinutes(): void
    {
        $controller = new NetworkStatisticsController($this->unusedService());
        $response = $controller(Request::create('/v1/statistics/network?bucketMinutes=0'));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('invalid_bucket_minutes', json_decode((string) $response->getContent(), true)['error']['type']);
    }

    public function testItMapsStatisticsUnavailableToServiceUnavailable(): void
    {
        $service = new class implements NetworkStatisticsReadServiceInterface {
            public function read(string $network, string $range, int $bucketMinutes): array
            {
                throw new StatisticsUnavailableException('No table.');
            }
        };

        $controller = new NetworkStatisticsController($service);
        $response = $controller(Request::create('/v1/statistics/network'));

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        self::assertSame('statistics_unavailable', json_decode((string) $response->getContent(), true)['error']['type']);
    }

    private function unusedService(): NetworkStatisticsReadServiceInterface
    {
        return new class implements NetworkStatisticsReadServiceInterface {
            public function read(string $network, string $range, int $bucketMinutes): array
            {
                throw new \LogicException('The service should not be called.');
            }
        };
    }
}
