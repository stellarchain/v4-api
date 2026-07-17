<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\StatisticsUnavailableException;
use App\Service\Statistics\NetworkStatisticsReadServiceInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class NetworkStatisticsController
{
    private const DEFAULT_NETWORK = 'mainnet';
    private const DEFAULT_RANGE = '7d';
    private const DEFAULT_BUCKET_MINUTES = 5;
    private const MAX_LIMIT_BUCKETS = 1440;
    private const ALLOWED_RANGES = ['24h', '7d', '30d', '1y'];
    private const ALLOWED_NETWORKS = ['mainnet', 'public', 'testnet', 'test', 'futurenet', 'future'];

    public function __construct(
        private readonly NetworkStatisticsReadServiceInterface $statisticsReadService,
    ) {
    }

    #[Route('/v1/statistics/network', name: 'network_statistics', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $network = $this->queryString($request, 'network', self::DEFAULT_NETWORK);
        if (!in_array(strtolower($network), self::ALLOWED_NETWORKS, true)) {
            return $this->error('Invalid network. Use mainnet, testnet, or futurenet.', Response::HTTP_BAD_REQUEST, 'invalid_network');
        }

        $range = strtolower($this->queryString($request, 'range', self::DEFAULT_RANGE));
        if (!in_array($range, self::ALLOWED_RANGES, true)) {
            return $this->error('Invalid range. Use 24h, 7d, 30d, or 1y.', Response::HTTP_BAD_REQUEST, 'invalid_range');
        }

        $bucketMinutes = $this->queryPositiveInt($request, 'bucketMinutes', self::DEFAULT_BUCKET_MINUTES);
        if ($bucketMinutes === null || $bucketMinutes < 5 || $bucketMinutes > 1440 || $bucketMinutes % 5 !== 0) {
            return $this->error('Invalid bucketMinutes. Use a multiple of 5 between 5 and 1440.', Response::HTTP_BAD_REQUEST, 'invalid_bucket_minutes');
        }

        $limitBuckets = $this->queryPositiveInt($request, 'limitBuckets', $this->defaultLimitBuckets($bucketMinutes));
        if ($limitBuckets === null || $limitBuckets < 1 || $limitBuckets > self::MAX_LIMIT_BUCKETS) {
            return $this->error(
                sprintf('Invalid limitBuckets. Use a value between 1 and %d.', self::MAX_LIMIT_BUCKETS),
                Response::HTTP_BAD_REQUEST,
                'invalid_limit_buckets'
            );
        }

        $beforeRaw = $request->query->get('before');
        $before = null;
        if (is_string($beforeRaw) && trim($beforeRaw) !== '') {
            try {
                $before = new \DateTimeImmutable(trim($beforeRaw), new \DateTimeZone('UTC'));
            } catch (\Exception) {
                return $this->error('Invalid before timestamp. Use an ISO-8601 datetime.', Response::HTTP_BAD_REQUEST, 'invalid_before');
            }
        }

        try {
            $payload = $this->statisticsReadService->read($network, $range, $bucketMinutes, $before, $limitBuckets);
        } catch (StatisticsUnavailableException) {
            return $this->error('Statistics are temporarily unavailable.', Response::HTTP_SERVICE_UNAVAILABLE, 'statistics_unavailable');
        }

        $response = new JsonResponse($payload, Response::HTTP_OK);
        $response->headers->set('Cache-Control', 'public, max-age=60, s-maxage=60, stale-while-revalidate=120');

        return $response;
    }

    private function queryString(Request $request, string $key, string $default): string
    {
        $value = $request->query->get($key);
        if (!is_string($value) || trim($value) === '') {
            return $default;
        }

        return trim($value);
    }

    private function queryPositiveInt(Request $request, string $key, int $default): ?int
    {
        $value = $request->query->get($key);
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^[0-9]+$/', trim($value)) === 1) {
            $parsed = (int) trim($value);

            return $parsed > 0 ? $parsed : null;
        }

        return null;
    }

    private function defaultLimitBuckets(int $bucketMinutes): int
    {
        if ($bucketMinutes <= 5) {
            return 288;
        }
        if ($bucketMinutes <= 60) {
            return 168;
        }

        return 90;
    }

    private function error(string $message, int $status, string $type): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'type' => $type,
                'code' => $status,
                'message' => $message,
            ],
        ], $status);
    }
}
