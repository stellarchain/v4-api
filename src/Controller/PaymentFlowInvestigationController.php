<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\StatisticsUnavailableException;
use App\Service\Trace\PaymentFlowInvestigationReadServiceInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PaymentFlowInvestigationController
{
    private const DEFAULT_NETWORK = 'mainnet';
    private const DEFAULT_DIRECTION = 'both';
    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT = 200;
    private const ALLOWED_DIRECTIONS = ['both', 'incoming', 'outgoing'];
    private const ALLOWED_NETWORKS = ['mainnet', 'public', 'testnet', 'test', 'futurenet', 'future'];

    public function __construct(
        private readonly PaymentFlowInvestigationReadServiceInterface $paymentFlowInvestigationReadService,
    ) {
    }

    #[Route('/v1/payment-flow/investigation', name: 'payment_flow_investigation', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $network = $this->queryString($request, 'network', self::DEFAULT_NETWORK);
        if (!in_array(strtolower($network), self::ALLOWED_NETWORKS, true)) {
            return $this->error('Invalid network. Use mainnet, testnet, or futurenet.', Response::HTTP_BAD_REQUEST, 'invalid_network');
        }

        [$address, $txHash] = $this->resolveSearchTarget($request);
        if ($address === null && $txHash === null) {
            return $this->error('Provide an address or txHash.', Response::HTTP_BAD_REQUEST, 'missing_target');
        }

        if ($address !== null && !$this->isValidAddress($address)) {
            return $this->error('Invalid Stellar address.', Response::HTTP_BAD_REQUEST, 'invalid_address');
        }

        if ($txHash !== null && !$this->isValidTxHash($txHash)) {
            return $this->error('Invalid transaction hash.', Response::HTTP_BAD_REQUEST, 'invalid_tx_hash');
        }

        $direction = strtolower($this->queryString($request, 'direction', self::DEFAULT_DIRECTION));
        if (!in_array($direction, self::ALLOWED_DIRECTIONS, true)) {
            return $this->error('Invalid direction. Use both, incoming, or outgoing.', Response::HTTP_BAD_REQUEST, 'invalid_direction');
        }

        $limit = $this->queryPositiveInt($request, 'limit', self::DEFAULT_LIMIT);
        if ($limit === null || $limit > self::MAX_LIMIT) {
            return $this->error(sprintf('Invalid limit. Use a positive integer up to %d.', self::MAX_LIMIT), Response::HTTP_BAD_REQUEST, 'invalid_limit');
        }

        $ledgerFrom = $this->queryPositiveInt($request, 'ledgerFrom', null);
        $ledgerTo = $this->queryPositiveInt($request, 'ledgerTo', null);
        if ($ledgerFrom !== null && $ledgerTo !== null && $ledgerFrom > $ledgerTo) {
            return $this->error('ledgerFrom must be lower than or equal to ledgerTo.', Response::HTTP_BAD_REQUEST, 'invalid_ledger_range');
        }

        try {
            $payload = $this->paymentFlowInvestigationReadService->read(
                $network,
                $address,
                $txHash,
                $ledgerFrom,
                $ledgerTo,
                $direction,
                $limit
            );
        } catch (StatisticsUnavailableException) {
            return $this->error('Payment flow statistics are temporarily unavailable.', Response::HTTP_SERVICE_UNAVAILABLE, 'statistics_unavailable');
        }

        $response = new JsonResponse($payload, Response::HTTP_OK);
        $response->headers->set('Cache-Control', 'public, max-age=30, s-maxage=30, stale-while-revalidate=120');

        return $response;
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function resolveSearchTarget(Request $request): array
    {
        $address = $this->queryString($request, 'address', '');
        $txHash = $this->queryString($request, 'txHash', '');
        $query = $this->queryString($request, 'q', '');

        if ($address === '' && $txHash === '' && $query !== '') {
            if ($this->isValidTxHash($query)) {
                $txHash = strtolower($query);
            } else {
                $address = strtoupper($query);
            }
        }

        return [
            $address === '' ? null : strtoupper($address),
            $txHash === '' ? null : strtolower($txHash),
        ];
    }

    private function isValidAddress(string $address): bool
    {
        return preg_match('/^G[A-Z2-7]{55}$/', strtoupper($address)) === 1;
    }

    private function isValidTxHash(string $txHash): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', strtolower($txHash)) === 1;
    }

    private function queryString(Request $request, string $key, string $default): string
    {
        $value = $request->query->get($key);
        if (!is_string($value) || trim($value) === '') {
            return $default;
        }

        return trim($value);
    }

    private function queryPositiveInt(Request $request, string $key, ?int $default): ?int
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
