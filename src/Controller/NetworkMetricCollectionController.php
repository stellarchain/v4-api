<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\StatisticsUnavailableException;
use App\Service\Statistics\NetworkMetricCatalog;
use App\Service\Statistics\NetworkMetricSeriesReadServiceInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
final class NetworkMetricCollectionController
{
    private const DEFAULT_NETWORK = 'mainnet';
    private const DEFAULT_BUCKET_MINUTES = 5;
    private const DEFAULT_ITEMS_PER_PAGE = 100;
    private const MAX_ITEMS_PER_PAGE = 500;
    private const MAX_BUCKET_MINUTES = 1440;
    private const MAX_WINDOW_DAYS = 30;
    private const ALLOWED_NETWORKS = ['mainnet', 'public', 'testnet', 'test', 'futurenet', 'future'];

    public function __construct(
        private readonly NetworkMetricSeriesReadServiceInterface $metricSeriesReadService,
        private readonly NetworkMetricCatalog $metricCatalog,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $network = strtolower($this->queryString($request, 'network', self::DEFAULT_NETWORK));
        if (!in_array($network, self::ALLOWED_NETWORKS, true)) {
            return $this->error('Invalid network. Use mainnet, testnet, or futurenet.', Response::HTTP_BAD_REQUEST, 'invalid_network');
        }

        $metricKey = strtolower($this->queryString($request, 'metricKey', ''));
        if ($metricKey === '') {
            return $this->error('Provide a metricKey.', Response::HTTP_BAD_REQUEST, 'missing_metric_key');
        }
        if (!$this->metricCatalog->hasMetric($metricKey)) {
            return $this->error('Invalid metricKey.', Response::HTTP_BAD_REQUEST, 'invalid_metric_key');
        }
        if (!$this->metricCatalog->isAvailableMetric($metricKey)) {
            return $this->error(
                sprintf('Metric "%s" is not currently available in the public API.', $metricKey),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'metric_not_available'
            );
        }

        $bucketMinutes = $this->queryPositiveInt($request, 'bucketMinutes', self::DEFAULT_BUCKET_MINUTES);
        if (
            $bucketMinutes === null
            || $bucketMinutes < self::DEFAULT_BUCKET_MINUTES
            || $bucketMinutes > self::MAX_BUCKET_MINUTES
            || $bucketMinutes % self::DEFAULT_BUCKET_MINUTES !== 0
        ) {
            return $this->error(
                'Invalid bucketMinutes. Use a multiple of 5 between 5 and 1440.',
                Response::HTTP_BAD_REQUEST,
                'invalid_bucket_minutes'
            );
        }
        if ($metricKey === 'active-addresses' && $bucketMinutes !== self::DEFAULT_BUCKET_MINUTES) {
            return $this->error(
                'Active addresses are only available as five-minute distinct transaction sources; larger intervals require a historical distinct-account rebuild.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'metric_aggregation_not_available'
            );
        }

        $page = $this->queryPositiveInt($request, 'page', 1);
        if ($page === null) {
            return $this->error('Invalid page.', Response::HTTP_BAD_REQUEST, 'invalid_page');
        }

        $itemsPerPage = $this->queryPositiveInt($request, 'itemsPerPage', self::DEFAULT_ITEMS_PER_PAGE);
        if ($itemsPerPage === null || $itemsPerPage > self::MAX_ITEMS_PER_PAGE) {
            return $this->error(
                sprintf('Invalid itemsPerPage. Use a positive integer up to %d.', self::MAX_ITEMS_PER_PAGE),
                Response::HTTP_BAD_REQUEST,
                'invalid_items_per_page'
            );
        }

        if (!$request->query->has('windowDays')) {
            return $this->error(
                'windowDays is required. Use 1 to 30 UTC days; unbounded metric reads are not available.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'window_required'
            );
        }

        $windowDays = $this->queryPositiveInt($request, 'windowDays', 0);
        if ($windowDays === null || $windowDays < 1 || $windowDays > self::MAX_WINDOW_DAYS || self::MAX_BUCKET_MINUTES % $bucketMinutes !== 0) {
            return $this->error(
                'Invalid windowDays. Use 1 to 30 UTC days with a bucket size that divides one day.',
                Response::HTTP_BAD_REQUEST,
                'invalid_window'
            );
        }

        $before = null;
        if ($request->query->has('before')) {
            $before = $request->query->get('before');
            if (!is_string($before) || !$this->isAlignedUtcBoundary($before, $bucketMinutes)) {
                return $this->error(
                    'Invalid before. Use a bucket-aligned UTC timestamp such as 2026-05-15T00:00:00Z.',
                    Response::HTTP_BAD_REQUEST,
                    'invalid_before'
                );
            }
        }

        try {
            $result = $this->metricSeriesReadService->read(
                $network,
                $metricKey,
                $bucketMinutes,
                $page,
                $itemsPerPage,
                $windowDays,
                $before
            );
        } catch (StatisticsUnavailableException) {
            return $this->error(
                'Network metric statistics are temporarily unavailable.',
                Response::HTTP_SERVICE_UNAVAILABLE,
                'statistics_unavailable'
            );
        }

        $payload = [
            '@context' => '/v1/contexts/NetworkMetricPoint',
            '@id' => '/v1/network-metrics',
            '@type' => 'Collection',
            'totalItems' => $result['totalItems'],
            'member' => $result['items'],
            'view' => $this->buildView($request, $result['page'], $result['itemsPerPage'], $result['totalItems']),
        ];
        $payload['window'] = $result['window'];

        $response = new JsonResponse($payload, Response::HTTP_OK);
        $response->headers->set('Content-Type', 'application/ld+json; charset=utf-8');
        $response->headers->set('Cache-Control', 'public, max-age=60, s-maxage=60, stale-while-revalidate=120');

        return $response;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildView(Request $request, int $page, int $itemsPerPage, int $totalItems): array
    {
        $lastPage = max(1, (int) ceil($totalItems / $itemsPerPage));
        $view = [
            '@id' => $this->pageUrl($request, $page),
            '@type' => 'PartialCollectionView',
            'first' => $this->pageUrl($request, 1),
            'last' => $this->pageUrl($request, $lastPage),
        ];
        if ($page > 1) {
            $view['previous'] = $this->pageUrl($request, $page > $lastPage ? $lastPage : $page - 1);
        }
        if ($page < $lastPage) {
            $view['next'] = $this->pageUrl($request, $page + 1);
        }

        return $view;
    }

    private function pageUrl(Request $request, int $page): string
    {
        $query = $request->query->all();
        $query['page'] = $page;

        return $request->getPathInfo() . '?' . http_build_query($query);
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
            $parsed = filter_var(ltrim(trim($value), '0'), FILTER_VALIDATE_INT);

            return $parsed !== false && $parsed > 0 ? $parsed : null;
        }

        return null;
    }

    private function isAlignedUtcBoundary(string $value, int $bucketMinutes): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) !== 1) {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));

        return $date !== false
            && $date->format('Y-m-d\TH:i:s\Z') === $value
            && $date->getTimestamp() % ($bucketMinutes * 60) === 0;
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
