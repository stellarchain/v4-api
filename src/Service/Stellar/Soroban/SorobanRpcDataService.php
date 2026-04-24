<?php

namespace App\Service\Stellar\Soroban;

use App\Service\Stellar\Horizon\HorizonTxEnricher;
use Soneso\StellarSDK\Soroban\Requests\EventFilter;
use Soneso\StellarSDK\Soroban\Requests\EventFilters;
use Soneso\StellarSDK\Soroban\Requests\GetEventsRequest;
use Soneso\StellarSDK\Soroban\Requests\PaginationOptions;
use Soneso\StellarSDK\Soroban\Responses\SorobanRpcErrorResponse;
use Soneso\StellarSDK\Soroban\Responses\SorobanRpcResponse;
use Soneso\StellarSDK\Soroban\SorobanServer;

final class SorobanRpcDataService
{
    private const LEDGER_ENTRIES_BATCH_SIZE = 100;

    public function __construct(
        private readonly SorobanServerFactory $sorobanServerFactory,
        private readonly ContractEventsScanner $contractEventsScanner,
        private readonly ContractReadMethodsProbe $contractReadMethodsProbe,
        private readonly SorobanScValMapper $scValMapper,
        private readonly HorizonTxEnricher $horizonTxEnricher,
        private readonly SorobanContractInspector $sorobanContractInspector,
    ) {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function fetchContractRpcData(
        string $contractId,
        ?string $network,
        string $instanceLedgerKeyBase64,
        bool $includeRawXdr = false,
    ): ?array {
        $server = $this->sorobanServerFactory->create($network);
        $result = [];
        $latest = $this->sorobanCall(fn () => $server->getLatestLedger(), 'getLatestLedger');
        $latest = $this->sanitizeLatestLedgerPayload($latest, $includeRawXdr);
        $latestSequence = isset($latest['result']['sequence']) ? (int) $latest['result']['sequence'] : null;
        $startLedger = $latestSequence !== null ? max(1, $latestSequence - 17000) : null;
        $result['latestLedger'] = $latest;

        $eventsRequest = $startLedger !== null
            ? new GetEventsRequest(
                startLedger: $startLedger,
                filters: new EventFilters(new EventFilter(type: 'contract', contractIds: [$contractId])),
                paginationOptions: new PaginationOptions(limit: 50),
            )
            : null;
        $eventsRaw = $eventsRequest !== null
            ? $this->sorobanCall(fn () => $server->getEvents($eventsRequest), 'getEvents')
            : ['ok' => false, 'error' => ['message' => 'Missing latest ledger sequence']];
        $result['events'] = $this->sanitizeEventsPayload($eventsRaw, $includeRawXdr);

        $ledgerEntries = $this->sorobanCall(
            fn () => $server->getLedgerEntries([$instanceLedgerKeyBase64]),
            'getLedgerEntries'
        );
        $ledgerEntries = $this->sanitizeLedgerEntriesPayload($ledgerEntries, $includeRawXdr);
        $result['contractStorage'] = $ledgerEntries;

        $parsedEvents = $this->scValMapper->parseEventsPayload($eventsRaw['result']['events'] ?? null);
        $result['contractMethods'] = $this->contractReadMethodsProbe->probe($contractId, $network);

        $txHashes = $this->scValMapper->extractTransactionHashes($parsedEvents);
        $result['horizon'] = $this->horizonTxEnricher->fetchByHashes(
            $network,
            $txHashes,
        );

        $result['parsedData'] = [
            'events' => $parsedEvents,
            'contractStorage' => $this->scValMapper->parseLedgerEntriesPayload($ledgerEntries['result']['entries'] ?? null, $latestSequence),
        ];

        $result['requestMeta'] = [
            'contractId' => $contractId,
            'instanceLedgerKeyBase64' => $instanceLedgerKeyBase64,
            'eventsStartLedger' => $startLedger,
            'eventsCount' => is_array($eventsRaw['result']['events'] ?? null) ? count($eventsRaw['result']['events']) : 0,
            'parsedEventsCount' => count($parsedEvents),
            'contractStorageCount' => is_array($ledgerEntries['result']['entries'] ?? null) ? count($ledgerEntries['result']['entries']) : 0,
        ];

        return $result;
    }

    /**
     * @return array{
     *     ok:bool,
     *     contractId?:string,
     *     latestLedger?:int,
     *     startLedger?:int,
     *     eventsCollected?:int,
     *     transactionsCollected?:int,
     *     events?:array<int,array<string,mixed>>,
     *     storageEntries?:array<int,array<string,mixed>>,
     *     transactions?:array<int,array<string,mixed>>,
     *     error?:string
     * }
     */
    public function collectContractTransactions(
        string $normalizedContractId,
        ?string $network = null,
        ?int $startLedger = null,
        ?int $endLedger = null,
        ?callable $onProgress = null,
        ?callable $onChunk = null,
    ): array {
        $server = $this->sorobanServerFactory->create($network);
        $scan = $this->contractEventsScanner->scanContractTransactions(
            $server,
            $normalizedContractId,
            $startLedger,
            $endLedger,
            $onProgress,
            $onChunk,
        );
        if (($scan['ok'] ?? false) !== true) {
            return $scan;
        }

        $latestSequence = isset($scan['latestLedger']) ? (int) $scan['latestLedger'] : null;
        if ($latestSequence === null) {
            return ['ok' => false, 'error' => 'Could not read latest ledger sequence.'];
        }

        $streamChunks = is_callable($onChunk);
        $effectiveStartLedger = isset($scan['startLedger']) ? (int) $scan['startLedger'] : ($startLedger ?? 1);
        $effectiveEndLedger = $endLedger ?? $latestSequence;
        $horizonTransactions = [];
        $storageLedgerKeys = [];
        if (!$this->shouldSkipHorizonFallback()) {
            $horizonFallback = $this->horizonTxEnricher->collectInvokeTransactionsForContract(
                $network,
                $normalizedContractId,
                $effectiveStartLedger,
                $effectiveEndLedger,
                $onProgress,
                function (array $transactions) use ($streamChunks, $onChunk, &$horizonTransactions): void {
                    if ($transactions === []) {
                        return;
                    }

                    if ($streamChunks && is_callable($onChunk)) {
                        $onChunk([
                            'transactions' => $transactions,
                            'events' => [],
                            'storageEntries' => [],
                        ]);
                        return;
                    }

                    array_push($horizonTransactions, ...$transactions);
                }
            );
            if (!$streamChunks) {
                $horizonTransactions = is_array($horizonFallback['transactions'] ?? null) ? $horizonFallback['transactions'] : [];
            }

            $storageLedgerKeys = is_array($horizonFallback['storageLedgerKeys'] ?? null)
                ? $horizonFallback['storageLedgerKeys']
                : [];
        }
        $storageRows = $this->fetchContractStorageEntries($server, $normalizedContractId, $latestSequence, $storageLedgerKeys);

        // stream mode: Horizon transactions were already flushed chunk-by-chunk via callback above.

        if ($streamChunks && $storageRows !== [] && is_callable($onChunk)) {
            $onChunk([
                'transactions' => [],
                'events' => [],
                'storageEntries' => $storageRows,
            ]);
        }

        $scanTransactions = is_array($scan['transactions'] ?? null) ? $scan['transactions'] : [];
        $mergedTransactions = $this->mergeTransactionsByHash($scanTransactions, $horizonTransactions);
        $mergedTxCount = count($mergedTransactions);

        return [
            'ok' => true,
            'contractId' => $normalizedContractId,
            'latestLedger' => $latestSequence,
            'startLedger' => $scan['startLedger'] ?? null,
            'eventsCollected' => $scan['eventsCollected'] ?? 0,
            'transactionsCollected' => $mergedTxCount,
            'events' => $streamChunks ? [] : ($scan['events'] ?? []),
            'storageEntries' => $streamChunks ? [] : $storageRows,
            'transactions' => $streamChunks ? [] : $mergedTransactions,
        ];
    }

    private function shouldSkipHorizonFallback(): bool
    {
        $raw = trim((string) (getenv('SOROBAN_SYNC_SKIP_HORIZON_FALLBACK') ?: ''));
        if ($raw === '') {
            return false;
        }

        return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param array<int,array<string,mixed>> $base
     * @param array<int,array<string,mixed>> $overlay
     * @return array<int,array<string,mixed>>
     */
    private function mergeTransactionsByHash(array $base, array $overlay): array
    {
        $byHash = [];
        foreach ($base as $row) {
            if (!is_array($row) || !is_string($row['txHash'] ?? null) || trim((string) $row['txHash']) === '') {
                continue;
            }
            $byHash[trim((string) $row['txHash'])] = $row;
        }

        foreach ($overlay as $row) {
            if (!is_array($row) || !is_string($row['txHash'] ?? null) || trim((string) $row['txHash']) === '') {
                continue;
            }
            $byHash[trim((string) $row['txHash'])] = $row;
        }

        return array_values($byHash);
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

    /**
     * @param array<string,mixed> $latest
     * @return array<string,mixed>
     */
    private function sanitizeLatestLedgerPayload(array $latest, bool $includeRawXdr): array
    {
        if ($includeRawXdr || !isset($latest['result']) || !is_array($latest['result'])) {
            return $latest;
        }

        unset($latest['result']['headerXdr'], $latest['result']['metadataXdr']);

        return $latest;
    }

    /**
     * @param array<string,mixed> $ledgerEntries
     * @return array<string,mixed>
     */
    private function sanitizeLedgerEntriesPayload(array $ledgerEntries, bool $includeRawXdr): array
    {
        if ($includeRawXdr || !isset($ledgerEntries['result']['entries']) || !is_array($ledgerEntries['result']['entries'])) {
            return $ledgerEntries;
        }

        foreach ($ledgerEntries['result']['entries'] as $idx => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            unset($ledgerEntries['result']['entries'][$idx]['xdr'], $ledgerEntries['result']['entries'][$idx]['extXdr']);
        }

        return $ledgerEntries;
    }

    /**
     * @param array<string,mixed> $events
     * @return array<string,mixed>
     */
    private function sanitizeEventsPayload(array $events, bool $includeRawXdr): array
    {
        if ($includeRawXdr || !isset($events['result']['events']) || !is_array($events['result']['events'])) {
            return $events;
        }

        foreach ($events['result']['events'] as $idx => $event) {
            if (!is_array($event)) {
                continue;
            }

            $events['result']['events'][$idx] = [
                'type' => $event['type'] ?? null,
                'ledger' => $event['ledger'] ?? null,
                'ledgerClosedAt' => $event['ledgerClosedAt'] ?? null,
                'contractId' => $event['contractId'] ?? null,
                'id' => $event['id'] ?? null,
                'operationIndex' => $event['operationIndex'] ?? null,
                'transactionIndex' => $event['transactionIndex'] ?? null,
                'txHash' => $event['txHash'] ?? null,
                'inSuccessfulContractCall' => $event['inSuccessfulContractCall'] ?? null,
            ];
        }

        return $events;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchContractStorageEntries(
        SorobanServer $server,
        string $contractId,
        int $latestSequence,
        array $additionalLedgerKeys = [],
    ): array
    {
        try {
            $ledgerKeys = [$this->sorobanContractInspector->buildContractInstanceLedgerKeyBase64($contractId)];
            foreach ($additionalLedgerKeys as $candidate) {
                if (is_string($candidate) && trim($candidate) !== '') {
                    $ledgerKeys[] = trim($candidate);
                }
            }
            $ledgerKeys = array_values(array_unique($ledgerKeys));
            if ($ledgerKeys === []) {
                return [];
            }

            $entries = [];
            foreach (array_chunk($ledgerKeys, self::LEDGER_ENTRIES_BATCH_SIZE) as $keysBatch) {
                $ledgerEntries = $this->sorobanCall(
                    fn () => $server->getLedgerEntries($keysBatch),
                    'getLedgerEntries'
                );
                if (($ledgerEntries['ok'] ?? false) !== true) {
                    continue;
                }
                $batchEntries = is_array($ledgerEntries['result']['entries'] ?? null)
                    ? $ledgerEntries['result']['entries']
                    : [];
                if ($batchEntries !== []) {
                    array_push($entries, ...$batchEntries);
                }
            }
            if ($entries === []) {
                return [];
            }

            $parsed = $this->scValMapper->parseLedgerEntriesPayload($entries, $latestSequence);
            $byKey = [];
            foreach ($parsed as $row) {
                $key = is_string($row['key'] ?? null) ? trim($row['key']) : '';
                if ($key === '') {
                    continue;
                }
                $byKey[$key] = $row;
            }

            return array_values($byKey);
        } catch (\Throwable) {
            return [];
        }
    }

}
