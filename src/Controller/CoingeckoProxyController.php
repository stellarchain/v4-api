<?php

namespace App\Controller;

use App\Service\CoingeckoSnapshotCache;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CoingeckoProxyController
{
    public function __construct(
        private readonly CoingeckoSnapshotCache $snapshotCache,
    ) {
    }

    #[Route('/api/coins/stellar', name: 'api_coingecko_stellar', methods: ['GET'])]
    public function stellar(): JsonResponse
    {
        $payload = $this->snapshotCache->getCachedPayload();
        if ($payload === null) {
            try {
                $payload = $this->snapshotCache->fetchLivePayload(false);
                $this->snapshotCache->savePayloadToCache($payload);
            } catch (\Throwable $exception) {
                return new JsonResponse(
                    ['error' => 'Upstream data temporarily unavailable.'],
                    Response::HTTP_SERVICE_UNAVAILABLE
                );
            }
        }

        $response = new JsonResponse($payload, Response::HTTP_OK);
        $response->headers->set('Cache-Control', 'public, max-age=60, s-maxage=60, stale-while-revalidate=120, stale-if-error=300');

        return $response;
    }
}
