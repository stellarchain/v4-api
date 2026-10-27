<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AccountRead\AccountReadGateway;
use App\Service\Stellar\StellarNetworkResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class AccountHistoryController
{
    public function __construct(
        private readonly AccountReadGateway $gateway,
        private readonly StellarNetworkResolver $networkResolver,
    ) {
    }

    #[Route('/v1/accounts/{address}/{kind}', name: 'account_history', requirements: ['kind' => 'transactions|operations|effects'], methods: ['GET'])]
    public function __invoke(string $address, string $kind, Request $request): JsonResponse
    {
        $limit = $request->query->get('limit', '50');
        if ((!is_string($limit) && !is_int($limit)) || !ctype_digit((string) $limit) || (int) $limit < 1 || (int) $limit > 200) {
            throw new BadRequestHttpException('Limit must be between 1 and 200.');
        }
        $includeFailed = null;
        if ($request->query->has('include_failed')) {
            $includeFailed = filter_var($request->query->get('include_failed'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($includeFailed === null) {
                throw new BadRequestHttpException('Invalid include_failed flag.');
            }
        }
        $payload = $this->gateway->history($address,
            $this->networkResolver->normalizeNetwork($request->query->get('network')),
            $kind, (int) $limit, (string) $request->query->get('order', 'desc'),
            $request->query->get('cursor'), $includeFailed);

        return new JsonResponse($payload, headers: ['Cache-Control' => 'public, max-age=5']);
    }
}
