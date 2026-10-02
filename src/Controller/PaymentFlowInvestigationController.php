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
    private const OPERATION_TYPES = ['create_account', 'payment', 'path_payment_strict_receive', 'path_payment_strict_send', 'account_merge'];

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
        foreach (['ledgerFrom' => $ledgerFrom, 'ledgerTo' => $ledgerTo] as $key => $value) {
            if ($request->query->has($key) && $value === null) {
                return $this->error($key . ' must be a positive 32-bit integer.', Response::HTTP_BAD_REQUEST, 'invalid_ledger_range');
            }
        }
        if ($ledgerFrom !== null && $ledgerTo !== null && $ledgerFrom > $ledgerTo) {
            return $this->error('ledgerFrom must be lower than or equal to ledgerTo.', Response::HTTP_BAD_REQUEST, 'invalid_ledger_range');
        }

        $operationType = $this->queryString($request, 'operationType', '');
        if ($operationType !== '' && !in_array($operationType, self::OPERATION_TYPES, true)) {
            return $this->error('Invalid payment-flow operation type.', Response::HTTP_BAD_REQUEST, 'invalid_operation_type');
        }
        $asset = $this->queryString($request, 'asset', '');
        if ($asset !== '' && !$this->isValidAsset($asset)) {
            return $this->error('Invalid asset. Use native:XLM or credit_alphanum4/12:CODE:ISSUER.', Response::HTTP_BAD_REQUEST, 'invalid_asset');
        }
        $minAssetAmount = $this->queryString($request, 'minAssetAmount', '');
        if ($minAssetAmount !== '' && ($asset === '' || !$this->isValidPositiveAmount($minAssetAmount))) {
            return $this->error('minAssetAmount requires an asset and a positive decimal with up to 20 integer and 14 fractional digits.', Response::HTTP_BAD_REQUEST, 'invalid_min_asset_amount');
        }
        $dateFrom = $this->queryString($request, 'dateFrom', '');
        $dateTo = $this->queryString($request, 'dateTo', '');
        if (($dateFrom !== '' && !$this->isValidUtcDate($dateFrom)) || ($dateTo !== '' && !$this->isValidUtcDate($dateTo))
            || ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo)) {
            return $this->error('Invalid UTC date range. Use YYYY-MM-DD with dateFrom <= dateTo.', Response::HTTP_BAD_REQUEST, 'invalid_date_range');
        }
        $cursor = $this->queryString($request, 'cursor', '');

        try {
            $payload = $this->paymentFlowInvestigationReadService->read(
                $network,
                $address,
                $txHash,
                $ledgerFrom,
                $ledgerTo,
                $direction,
                $limit,
                $cursor === '' ? null : $cursor,
                $operationType === '' ? null : $operationType,
                $asset === '' ? null : $asset,
                $dateFrom === '' ? null : $dateFrom,
                $dateTo === '' ? null : $dateTo,
                $minAssetAmount === '' ? null : $minAssetAmount
            );
        } catch (\InvalidArgumentException) {
            return $this->error('Invalid cursor. Restart pagination after changing filters.', Response::HTTP_BAD_REQUEST, 'invalid_cursor');
        } catch (StatisticsUnavailableException) {
            return $this->error('Payment flow statistics are temporarily unavailable.', Response::HTTP_SERVICE_UNAVAILABLE, 'statistics_unavailable');
        }

        $response = new JsonResponse($payload, Response::HTTP_OK);
        $response->headers->set('Cache-Control', 'public, max-age=30, s-maxage=30, stale-while-revalidate=120');

        return $response;
    }

    #[Route('/v1/trace/address/{id}', name: 'payment_flow_trace_address', methods: ['GET'])]
    public function traceAddress(Request $request, string $id): JsonResponse
    {
        if ($this->queryPositiveInt($request, 'depth', 1) !== 1) {
            return $this->error('Only one-hop classic payment-flow tracing is currently supported.', Response::HTTP_UNPROCESSABLE_ENTITY, 'depth_not_available');
        }
        $request->query->remove('q');
        $request->query->remove('txHash');
        $request->query->set('address', $id);

        return $this->__invoke($request);
    }

    #[Route('/v1/trace/tx/{hash}', name: 'payment_flow_trace_tx', methods: ['GET'])]
    public function traceTransaction(Request $request, string $hash): JsonResponse
    {
        if ($this->queryPositiveInt($request, 'depth', 1) !== 1) {
            return $this->error('Only one-hop classic payment-flow tracing is currently supported.', Response::HTTP_UNPROCESSABLE_ENTITY, 'depth_not_available');
        }
        $request->query->remove('q');
        $request->query->remove('address');
        $request->query->set('txHash', $hash);

        return $this->__invoke($request);
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

    private function isValidAsset(string $asset): bool
    {
        if ($asset === 'native:XLM') {
            return true;
        }

        $parts = explode(':', $asset);
        if (count($parts) !== 3 || !in_array($parts[0], ['credit_alphanum4', 'credit_alphanum12'], true)) {
            return false;
        }

        $maxLength = $parts[0] === 'credit_alphanum4' ? 4 : 12;

        return preg_match('/^[A-Za-z0-9]{1,' . $maxLength . '}$/D', $parts[1]) === 1
            && ($parts[0] !== 'credit_alphanum12' || strlen($parts[1]) > 4)
            && preg_match('/^G[A-Z2-7]{55}$/D', $parts[2]) === 1;
    }

    private function isValidUtcDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    private function isValidPositiveAmount(string $amount): bool
    {
        return preg_match('/^(?:[0-9]{1,20})(?:\.[0-9]{1,14})?$/D', $amount) === 1
            && preg_match('/[1-9]/', $amount) === 1;
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
            return $value > 0 && $value <= 2147483647 ? $value : null;
        }
        if (is_string($value) && preg_match('/^[0-9]+$/', trim($value)) === 1) {
            $parsed = (int) trim($value);

            return $parsed > 0 && $parsed <= 2147483647 ? $parsed : null;
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
