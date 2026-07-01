<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ContractEvents\ContractEventPayloadFallbackResolver;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ContractEventDetailsController
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.contracts_connection')]
        private readonly Connection $connection,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
        private readonly ContractEventPayloadFallbackResolver $payloadFallbackResolver,
    ) {
    }

    #[Route('/v1/contracts/{contractId}/events/{id}', name: 'contract_event_details', methods: ['GET'])]
    public function show(string $contractId, int $id, Request $request): JsonResponse
    {
        $networkInput = $request->query->get('network');
        $network = $this->stellarNetworkResolver->normalizeNetwork(is_string($networkInput) ? $networkInput : null, 'mainnet');
        $networkCode = $this->stellarNetworkResolver->resolveNetworkCode($network);
        if ($networkCode === null) {
            return $this->error('Invalid network.', Response::HTTP_BAD_REQUEST);
        }

        $row = $this->connection->fetchAssociative(
            'SELECT
                ce.id,
                ce.tx_hash,
                ce.event_idx,
                ce.ledger,
                ce.ledger_closed_at,
                ce.event_type,
                ce.topic_decoded,
                ce.value_decoded,
                ce.addresses,
                ce.amount_raw,
                ce.created_at,
                c.contract_id
             FROM contract_events ce
             INNER JOIN contracts c ON c.id = ce.contract_id
             WHERE ce.id = :id
               AND c.contract_id = :contract_id
               AND c.network = :network
             LIMIT 1',
            [
                'id' => $id,
                'contract_id' => strtoupper(trim($contractId)),
                'network' => $networkCode,
            ],
            [
                'id' => ParameterType::INTEGER,
                'network' => ParameterType::INTEGER,
            ]
        );

        if (!is_array($row)) {
            return $this->error('Event not found.', Response::HTTP_NOT_FOUND);
        }

        $topicDecoded = $this->decodeJsonValue($row['topic_decoded'] ?? null);
        $valueDecoded = $this->decodeJsonValue($row['value_decoded'] ?? null);
        $topicRaw = null;
        $valueRaw = null;
        $addresses = $this->decodeJsonValue($row['addresses'] ?? null);
        $amountRaw = $row['amount_raw'] !== null ? (string) $row['amount_raw'] : null;
        $eventType = (string) ($row['event_type'] ?? 'unknown');

        if ($topicDecoded === null && $valueDecoded === null && $addresses === null) {
            $fallback = $this->payloadFallbackResolver->resolve(
                (string) ($row['contract_id'] ?? ''),
                $network,
                (string) ($row['tx_hash'] ?? ''),
                (int) ($row['event_idx'] ?? 0),
                $row['ledger'] !== null ? (int) $row['ledger'] : null,
            );
            if (is_array($fallback)) {
                $topicDecoded = $fallback['topicDecoded'] ?? $topicDecoded;
                $valueDecoded = $fallback['valueDecoded'] ?? $valueDecoded;
                $topicRaw = $fallback['topicRaw'] ?? $topicRaw;
                $valueRaw = (is_string($fallback['valueRaw'] ?? null) && $fallback['valueRaw'] !== '')
                    ? $fallback['valueRaw']
                    : $valueRaw;
                $addresses = $fallback['addresses'] ?? $addresses;
                $amountRaw = (is_string($fallback['amountRaw'] ?? null) && $fallback['amountRaw'] !== '')
                    ? $fallback['amountRaw']
                    : $amountRaw;
                if (is_string($fallback['eventType'] ?? null) && trim((string) $fallback['eventType']) !== '') {
                    $eventType = (string) $fallback['eventType'];
                }
            }
        }

        return new JsonResponse([
            '@context' => '/v1/contexts/ContractEvent',
            '@id' => sprintf('/v1/contracts/%s/events/%d', (string) $row['contract_id'], (int) $row['id']),
            '@type' => 'ContractEvent',
            'id' => (int) $row['id'],
            'contractId' => (string) $row['contract_id'],
            'txHash' => (string) ($row['tx_hash'] ?? ''),
            'eventIndex' => (int) ($row['event_idx'] ?? 0),
            'ledger' => $row['ledger'] !== null ? (int) $row['ledger'] : null,
            'ledgerClosedAt' => $this->toAtom($row['ledger_closed_at'] ?? null),
            'eventType' => $eventType,
            'topicDecoded' => $topicDecoded,
            'valueDecoded' => $valueDecoded,
            'raw' => [
                'topic' => $topicRaw,
                'value' => $valueRaw,
            ],
            'addresses' => $addresses,
            'amountRaw' => $amountRaw,
            'createdAt' => $this->toAtom($row['created_at'] ?? null),
        ]);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'message' => $message,
            'status' => $status,
        ], $status);
    }

    private function toAtom(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->format(\DateTimeInterface::ATOM);
        } catch (\Throwable) {
            return null;
        }
    }

    private function decodeJsonValue(mixed $value): mixed
    {
        if ($value === null || is_array($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return $value;
        }
        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $value;
        }

        return $decoded;
    }
}
