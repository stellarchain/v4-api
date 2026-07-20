<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ContractTransparency\ContractActivityReadService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ContractActivityController
{
    private const DEFAULT_LIMIT = 30;
    private const MAX_LIMIT = 200;

    public function __construct(
        private readonly ContractActivityReadService $activityReadService,
    ) {
    }

    #[Route('/v1/contracts/{contractId}/activity', name: 'contract_activity', methods: ['GET'])]
    public function __invoke(string $contractId, Request $request): JsonResponse
    {
        $limit = $this->queryPositiveInt($request, 'limit', self::DEFAULT_LIMIT);
        if ($limit === null || $limit < 1 || $limit > self::MAX_LIMIT) {
            return $this->error(
                sprintf('Invalid limit. Use a value between 1 and %d.', self::MAX_LIMIT),
                Response::HTTP_BAD_REQUEST,
                'invalid_limit'
            );
        }

        $ledgerStart = $this->queryPositiveInt($request, 'ledgerStart');
        if ($ledgerStart === null) {
            $ledgerStart = $this->queryPositiveInt($request, 'startLedger');
        }
        $ledgerEnd = $this->queryPositiveInt($request, 'ledgerEnd');
        if ($ledgerEnd === null) {
            $ledgerEnd = $this->queryPositiveInt($request, 'endLedger');
        }

        $cursor = $request->query->get('cursor');
        $network = $request->query->get('network');
        $payload = $this->activityReadService->read(
            $contractId,
            is_string($network) ? $network : null,
            $ledgerStart,
            $ledgerEnd,
            is_string($cursor) ? $cursor : null,
            $limit,
        );

        if ($payload === null) {
            return $this->error('Contract not found.', Response::HTTP_NOT_FOUND, 'not_found');
        }

        $response = new JsonResponse($payload, Response::HTTP_OK);
        $response->headers->set('Cache-Control', 'public, max-age=30, s-maxage=30, stale-while-revalidate=60');

        return $response;
    }

    private function queryPositiveInt(Request $request, string $key, ?int $default = null): ?int
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
