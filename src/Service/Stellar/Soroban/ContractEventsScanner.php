<?php

namespace App\Service\Stellar\Soroban;

use Soneso\StellarSDK\Soroban\Requests\EventFilter;
use Soneso\StellarSDK\Soroban\Requests\EventFilters;
use Soneso\StellarSDK\Soroban\Requests\GetEventsRequest;
use Soneso\StellarSDK\Soroban\Requests\PaginationOptions;
use Soneso\StellarSDK\Soroban\Responses\SorobanRpcErrorResponse;
use Soneso\StellarSDK\Soroban\Responses\SorobanRpcResponse;
use Soneso\StellarSDK\Soroban\SorobanServer;

final class ContractEventsScanner
{
    private const DEFAULT_WINDOW_SIZE_LEDGERS = 15000;
    private const EVENTS_PAGE_SIZE = 200;

    public function __construct(
        private readonly SorobanScValMapper $sorobanScValMapper,
    ) {
    }

    /**
     * @return array{
     *     ok:bool,
     *     contractId?:string,
     *     latestLedger?:int,
     *     startLedger?:int,
     *     endLedger?:int,
     *     eventsCollected?:int,
     *     transactionsCollected?:int,
     *     events?:array<int,array<string,mixed>>,
     *     transactions?:array<int,array<string,mixed>>,
     *     error?:string
     * }
     */
    public function scanContractTransactions(
        SorobanServer $server,
        string $normalizedContractId,
        ?int $startLedger = null,
        ?int $endLedger = null,
        ?callable $onProgress = null,
        ?callable $onChunk = null,
    ): array {
        $latest = $this->sorobanCall(fn () => $server->getLatestLedger(), 'getLatestLedger');
        $latestSequence = isset($latest['result']['sequence']) ? (int) $latest['result']['sequence'] : null;
        if ($latestSequence === null) {
            return ['ok' => false, 'error' => 'Could not read latest ledger sequence.'];
        }

        $scanStartLedger = $startLedger ?? 1;
        $scanEndLedger = min($endLedger ?? $latestSequence, $latestSequence);
        $currentEndLedger = $scanEndLedger;
        $windowSize = self::DEFAULT_WINDOW_SIZE_LEDGERS;
        $pageSize = self::EVENTS_PAGE_SIZE;
        $allTxRows = [];
        $allEventRows = [];
        $eventsCollected = 0;
        $transactionsCollected = 0;
        $windowsProcessed = 0;
        $pagesProcessed = 0;
        $initialTotalLedgers = max(0, $scanEndLedger - $scanStartLedger + 1);
        $streamChunks = is_callable($onChunk);

        while ($currentEndLedger >= $scanStartLedger) {
            $windowStartLedger = max($scanStartLedger, $currentEndLedger - $windowSize + 1);
            $windowEndLedgerExclusive = $currentEndLedger + 1;
            $cursor = null;
            $windowTxMap = [];
            $windowEventRows = [];
            $windowTxEventIndex = [];

            while (true) {
                $eventsRequest = $cursor === null
                    ? new GetEventsRequest(
                        startLedger: $windowStartLedger,
                        endLedger: $windowEndLedgerExclusive,
                        filters: new EventFilters(new EventFilter(type: 'contract', contractIds: [$normalizedContractId])),
                        paginationOptions: new PaginationOptions(limit: $pageSize),
                    )
                    : new GetEventsRequest(
                        filters: new EventFilters(new EventFilter(type: 'contract', contractIds: [$normalizedContractId])),
                        paginationOptions: new PaginationOptions(cursor: $cursor, limit: $pageSize),
                    );

                $eventsResponse = $this->sorobanCall(fn () => $server->getEvents($eventsRequest), 'getEvents');
                if (($eventsResponse['ok'] ?? false) !== true) {
                    $errorText = $this->buildSorobanErrorText($eventsResponse['error'] ?? null);
                    $allowedStartLedger = $this->extractAllowedStartLedgerFromError($errorText);
                    if (is_int($allowedStartLedger) && $allowedStartLedger > $scanStartLedger) {
                        $scanStartLedger = $allowedStartLedger;
                        $initialTotalLedgers = max(0, $scanEndLedger - $scanStartLedger + 1);
                        if ($scanStartLedger > $scanEndLedger) {
                            break 2;
                        }
                        if ($currentEndLedger < $scanStartLedger) {
                            $currentEndLedger = $scanStartLedger;
                        }
                        if (is_callable($onProgress)) {
                            $onProgress([
                                'phase' => 'range-adjusted',
                                'contractId' => $normalizedContractId,
                                'scanStartLedger' => $scanStartLedger,
                                'scanEndLedger' => $scanEndLedger,
                                'eventsCollected' => $eventsCollected,
                                'transactionsCollected' => $transactionsCollected + count($windowTxMap),
                            ]);
                        }
                        continue 2;
                    }

                    return ['ok' => false, 'error' => $errorText];
                }

                $events = $eventsResponse['result']['events'] ?? null;
                if (!is_array($events) || $events === []) {
                    break;
                }
                $pagesProcessed++;
                $eventsCollected += count($events);

                foreach ($events as $event) {
                    if (!is_array($event)) {
                        continue;
                    }

                    $txHash = isset($event['txHash']) && is_string($event['txHash']) ? $event['txHash'] : null;
                    if ($txHash === null || $txHash === '') {
                        continue;
                    }

                    if (!isset($windowTxMap[$txHash])) {
                        $windowTxMap[$txHash] = [
                            'txHash' => $txHash,
                            'ledger' => isset($event['ledger']) ? (int) $event['ledger'] : null,
                            'ledgerClosedAt' => $event['ledgerClosedAt'] ?? null,
                            'eventsCount' => 0,
                        ];
                    }

                    $windowTxEventIndex[$txHash] = (int) (($windowTxEventIndex[$txHash] ?? 0) + 1);
                    $parsed = $this->parseEventPayload($event);
                    $eventType = is_string($parsed['eventType'] ?? null)
                        ? (string) $parsed['eventType']
                        : (is_string($event['type'] ?? null) ? (string) $event['type'] : 'unknown');
                    $topicDecoded = is_array($parsed['topicDecoded'] ?? null) ? $parsed['topicDecoded'] : [];
                    $valueDecoded = $parsed['valueDecoded'] ?? null;
                    $addresses = is_array($parsed['addresses'] ?? null) ? $parsed['addresses'] : [];
                    $amountRaw = $this->normalizeAmountRaw($parsed['amount'] ?? null);

                    $eventRow = [
                        'txHash' => $txHash,
                        'eventIndex' => $windowTxEventIndex[$txHash],
                        'ledger' => isset($event['ledger']) ? (int) $event['ledger'] : null,
                        'ledgerClosedAt' => $event['ledgerClosedAt'] ?? null,
                        'eventType' => $eventType,
                        'topicDecoded' => $topicDecoded,
                        'valueDecoded' => $valueDecoded,
                        'topicRaw' => is_array($event['topic'] ?? null) ? array_values($event['topic']) : [],
                        'valueRaw' => is_string($event['value'] ?? null) ? $event['value'] : null,
                        'addresses' => $addresses,
                        'amountRaw' => $amountRaw,
                    ];
                    $windowTxMap[$txHash]['eventsCount'] = (int) (($windowTxMap[$txHash]['eventsCount'] ?? 0) + 1);
                    $windowEventRows[] = $eventRow;

                    $eventLedger = isset($event['ledger']) ? (int) $event['ledger'] : null;
                    if ($eventLedger !== null && ($windowTxMap[$txHash]['ledger'] ?? 0) < $eventLedger) {
                        $windowTxMap[$txHash]['ledger'] = $eventLedger;
                        $windowTxMap[$txHash]['ledgerClosedAt'] = $event['ledgerClosedAt'] ?? null;
                    }
                }

                $nextCursor = $eventsResponse['result']['cursor'] ?? null;
                if (!is_string($nextCursor) || $nextCursor === '') {
                    $lastEvent = $events[array_key_last($events)] ?? null;
                    $nextCursor = (is_array($lastEvent) && isset($lastEvent['id']) && is_string($lastEvent['id']))
                        ? $lastEvent['id']
                        : null;
                }

                if (count($events) < $pageSize) {
                    break;
                }
                if (!is_string($nextCursor) || $nextCursor === '' || $nextCursor === $cursor) {
                    break;
                }

                $cursor = $nextCursor;

                if ($streamChunks && $windowEventRows !== []) {
                    $onChunk([
                        'transactions' => [],
                        'events' => $windowEventRows,
                        'storageEntries' => [],
                    ]);
                    $windowEventRows = [];
                }

                if (is_callable($onProgress)) {
                    $onProgress([
                        'phase' => 'page',
                        'contractId' => $normalizedContractId,
                        'windowStartLedger' => $windowStartLedger,
                        'windowEndLedger' => $windowEndLedgerExclusive - 1,
                        'scanStartLedger' => $scanStartLedger,
                        'scanEndLedger' => $scanEndLedger,
                        'pagesProcessed' => $pagesProcessed,
                        'eventsCollected' => $eventsCollected,
                        'transactionsCollected' => $transactionsCollected + count($windowTxMap),
                        'scannedLedgers' => max(0, $scanEndLedger - $windowStartLedger + 1),
                        'totalLedgers' => $initialTotalLedgers,
                    ]);
                }
            }

            $windowTxRows = [];
            foreach ($windowTxMap as $txHash => $txData) {
                $windowTxRows[] = [
                    'txHash' => $txHash,
                    'sourceAccount' => null,
                    'hostFunctions' => null,
                    'feeCharged' => 0,
                    'maxFee' => 0,
                    'ledger' => $txData['ledger'] ?? null,
                    'totalOperations' => null,
                    'createdAt' => $this->normalizeLedgerClosedAtToAtom($txData['ledgerClosedAt'] ?? null),
                ];
            }

            $transactionsCollected += count($windowTxRows);
            if ($streamChunks) {
                if ($windowTxRows !== [] || $windowEventRows !== []) {
                    $onChunk([
                        'transactions' => $windowTxRows,
                        'events' => $windowEventRows,
                        'storageEntries' => [],
                    ]);
                }
            } else {
                array_push($allTxRows, ...$windowTxRows);
                array_push($allEventRows, ...$windowEventRows);
            }

            $windowsProcessed++;
            if (is_callable($onProgress)) {
                $onProgress([
                    'phase' => 'window',
                    'contractId' => $normalizedContractId,
                    'windowStartLedger' => $windowStartLedger,
                    'windowEndLedger' => $windowEndLedgerExclusive - 1,
                    'scanStartLedger' => $scanStartLedger,
                    'scanEndLedger' => $scanEndLedger,
                    'windowsProcessed' => $windowsProcessed,
                    'pagesProcessed' => $pagesProcessed,
                    'eventsCollected' => $eventsCollected,
                    'transactionsCollected' => $transactionsCollected,
                    'scannedLedgers' => max(0, $scanEndLedger - $windowStartLedger + 1),
                    'totalLedgers' => $initialTotalLedgers,
                ]);
            }
            $currentEndLedger = $windowStartLedger - 1;
        }

        return [
            'ok' => true,
            'contractId' => $normalizedContractId,
            'latestLedger' => $latestSequence,
            'startLedger' => $scanStartLedger,
            'endLedger' => $scanEndLedger,
            'eventsCollected' => $eventsCollected,
            'transactionsCollected' => $transactionsCollected,
            'events' => $streamChunks ? [] : $allEventRows,
            'transactions' => $streamChunks ? [] : $allTxRows,
        ];
    }

    /**
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    private function parseEventPayload(array $event): array
    {
        try {
            $parsed = $this->sorobanScValMapper->parseEventsPayload([$event]);
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($parsed) || $parsed === [] || !is_array($parsed[0] ?? null)) {
            return [];
        }

        return $parsed[0];
    }

    private function normalizeAmountRaw(mixed $amount): ?string
    {
        if (is_int($amount)) {
            return (string) $amount;
        }

        if (is_string($amount) && preg_match('/^-?[0-9]+$/', $amount) === 1) {
            return $amount;
        }

        if (
            is_array($amount)
            && array_key_exists('hi', $amount)
            && array_key_exists('lo', $amount)
            && (is_int($amount['hi']) || is_string($amount['hi']))
            && (is_int($amount['lo']) || is_string($amount['lo']))
        ) {
            $hi = (string) (int) $amount['hi'];
            $lo = (string) (int) $amount['lo'];
            if ($hi === '0') {
                return $lo;
            }

            if (function_exists('gmp_init')) {
                $result = gmp_add(gmp_mul(gmp_init($hi, 10), gmp_pow(2, 64)), gmp_init($lo, 10));
                return gmp_strval($result, 10);
            }

            return null;
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function sorobanCall(callable $callback, string $method): array
    {
        $payload = ['method' => $method];

        try {
            /** @var SorobanRpcResponse $response */
            $response = $callback();
            $result = $response->getJsonResponse()['result'] ?? null;
            $error = $response->getError();

            if ($error instanceof SorobanRpcErrorResponse) {
                $payload['ok'] = false;
                $payload['error'] = $this->sorobanErrorToArray($error);
            } else {
                $payload['ok'] = true;
                $payload['result'] = $result;
            }
        } catch (\Throwable $e) {
            $payload['ok'] = false;
            $payload['error'] = ['message' => $e->getMessage()];
        }

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function sorobanErrorToArray(SorobanRpcErrorResponse $error): array
    {
        return [
            'code' => $error->getCode(),
            'message' => $error->getMessage(),
            'data' => $error->getData(),
        ];
    }

    private function normalizeLedgerClosedAtToAtom(mixed $value): ?string
    {
        if (is_int($value) || (is_string($value) && preg_match('/^[0-9]+$/', $value) === 1)) {
            try {
                return (new \DateTimeImmutable('@' . (int) $value))
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format(\DateTimeInterface::ATOM);
            } catch (\Throwable) {
                return null;
            }
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return (new \DateTimeImmutable($value))
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format(\DateTimeInterface::ATOM);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function extractAllowedStartLedgerFromError(string $error): ?int
    {
        if (preg_match('/ledger range:\s*([0-9]+)\s*-\s*([0-9]+)/i', $error, $matches) !== 1) {
            return null;
        }

        return isset($matches[1]) ? (int) $matches[1] : null;
    }

    private function buildSorobanErrorText(mixed $error): string
    {
        $message = is_array($error) && is_string($error['message'] ?? null)
            ? trim($error['message'])
            : '';
        $data = is_array($error) ? ($error['data'] ?? null) : null;

        if (is_string($data) && trim($data) !== '') {
            return trim($message . ' ' . $data);
        }

        if (is_array($data) && $data !== []) {
            $encoded = json_encode($data, JSON_UNESCAPED_SLASHES);
            if (is_string($encoded) && $encoded !== '') {
                return trim($message . ' ' . $encoded);
            }
        }

        return $message !== '' ? $message : 'Failed to query getEvents.';
    }
}
