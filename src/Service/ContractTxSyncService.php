<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ContractTxSyncService
{
    private const TX_UPSERT_CHUNK_SIZE = 500;
    private const EVENTS_UPSERT_CHUNK_SIZE = 1000;
    private const STORAGE_UPSERT_CHUNK_SIZE = 200;
    private const DEFAULT_LEDGER_OVERLAP = 64;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.contracts_connection')]
        private readonly Connection $connection,
        private readonly SorobanRpcService $sorobanRpcService,
        private readonly ContractTxUpsertService $txUpsertService,
        private readonly ?ContractMetricsRefreshService $contractMetricsRefreshService = null,
    ) {
    }

    /**
     * @return array{
     *   ok:bool,
     *   contractId:string,
     *   eventsCollected:int,
     *   transactionsCollected:int,
     *   rowsInserted:int,
     *   rowsUpdated:int,
     *   rowsUnchanged:int,
     *   error?:string
     * }
     */
    public function syncForContract(
        int $localContractId,
        string $contractId,
        ?string $network = null,
        bool $dryRun = false,
        ?int $startLedger = null,
        ?int $endLedger = null,
        ?callable $onProgress = null,
    ): array {
        $inserted = 0;
        $updated = 0;
        $unchanged = 0;
        $transactionsCollected = 0;
        $eventsCollectedByChunks = 0;
        $effectiveStartLedger = $this->resolveEffectiveStartLedger($localContractId, $startLedger, $endLedger);
        if (
            is_callable($onProgress)
            && $startLedger !== null
            && $effectiveStartLedger !== null
            && $effectiveStartLedger > $startLedger
        ) {
            $onProgress([
                'phase' => 'resume-ledger-adjusted',
                'contractId' => $contractId,
                'requestedStartLedger' => $startLedger,
                'effectiveStartLedger' => $effectiveStartLedger,
                'endLedger' => $endLedger,
            ]);
        }

        $onChunk = function (array $chunk) use ($dryRun, $localContractId, &$inserted, &$updated, &$unchanged, &$transactionsCollected, &$eventsCollectedByChunks): void {
            $rawTransactions = is_array($chunk['transactions'] ?? null) ? $chunk['transactions'] : [];
            $transactions = array_values(array_filter(
                $rawTransactions,
                fn (mixed $tx): bool => is_array($tx) && $this->isInvokeContractTransaction($tx)
            ));
            $events = is_array($chunk['events'] ?? null) ? $chunk['events'] : [];
            $storageEntries = is_array($chunk['storageEntries'] ?? null) ? $chunk['storageEntries'] : [];

            $transactionsCollected += count($transactions);
            $eventsCollectedByChunks += count($events);

            if ($dryRun) {
                return;
            }

            if ($transactions !== []) {
                foreach (array_chunk($transactions, self::TX_UPSERT_CHUNK_SIZE) as $txChunk) {
                    [$chunkInserted, $chunkUpdated, $chunkUnchanged] = $this->txUpsertService
                        ->upsertTransactionsForContract($localContractId, $txChunk);
                    $inserted += $chunkInserted;
                    $updated += $chunkUpdated;
                    $unchanged += $chunkUnchanged;
                }
            }

            if ($events !== []) {
                foreach (array_chunk($events, self::EVENTS_UPSERT_CHUNK_SIZE) as $eventChunk) {
                    $this->txUpsertService->upsertEventsForContract($localContractId, $eventChunk);
                }
            }

            if ($storageEntries !== []) {
                foreach (array_chunk($storageEntries, self::STORAGE_UPSERT_CHUNK_SIZE) as $storageChunk) {
                    $this->txUpsertService->upsertStorageEntriesForContract($localContractId, $storageChunk);
                }
            }
        };

        $payload = $this->sorobanRpcService->collectContractTransactions(
            $contractId,
            $network,
            $effectiveStartLedger,
            $endLedger,
            $onProgress,
            $onChunk,
        );

        if (($payload['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'contractId' => $contractId,
                'eventsCollected' => 0,
                'transactionsCollected' => 0,
                'rowsInserted' => 0,
                'rowsUpdated' => 0,
                'rowsUnchanged' => 0,
                'error' => (string) ($payload['error'] ?? 'unknown error'),
            ];
        }

        if (!$dryRun && $this->contractMetricsRefreshService !== null) {
            $this->contractMetricsRefreshService->refreshForContractIds([$localContractId]);
        }

        return [
            'ok' => true,
            'contractId' => $contractId,
            'eventsCollected' => (int) ($payload['eventsCollected'] ?? $eventsCollectedByChunks),
            'transactionsCollected' => max(
                (int) ($payload['transactionsCollected'] ?? 0),
                $transactionsCollected
            ),
            'rowsInserted' => $inserted,
            'rowsUpdated' => $updated,
            'rowsUnchanged' => $unchanged,
        ];
    }

    /**
     * Keep only real contract call transactions (invoke_host_function) in contract_transactions.
     *
     * @param array<string,mixed> $transaction
     */
    private function isInvokeContractTransaction(array $transaction): bool
    {
        $hostFunctionsRaw = $transaction['hostFunctions'] ?? null;
        if (!is_string($hostFunctionsRaw) || trim($hostFunctionsRaw) === '') {
            return false;
        }

        $decoded = json_decode($hostFunctionsRaw, true);
        if (!is_array($decoded)) {
            return false;
        }

        $invokeContracts = is_array($decoded['invokeContracts'] ?? null) ? $decoded['invokeContracts'] : [];
        if ($invokeContracts !== []) {
            return true;
        }

        $operationTypes = is_array($decoded['operationTypes'] ?? null) ? $decoded['operationTypes'] : [];
        foreach ($operationTypes as $type) {
            if (is_string($type) && strtolower(trim($type)) === 'invoke_host_function') {
                return true;
            }
        }

        return false;
    }

    private function resolveEffectiveStartLedger(int $localContractId, ?int $startLedger, ?int $endLedger): ?int
    {
        if ($startLedger === null || $localContractId <= 0) {
            return $startLedger;
        }
        if ($this->shouldForceStartLedger()) {
            return $startLedger;
        }

        $maxSeenLedger = $this->fetchLocalResumeLedger($localContractId);
        if ($maxSeenLedger === null || $maxSeenLedger < 1) {
            return $startLedger;
        }

        $overlap = $this->resolveLedgerOverlap();
        $resumeStart = max(1, $maxSeenLedger - $overlap + 1);
        $effectiveStart = max($startLedger, $resumeStart);
        if ($endLedger !== null) {
            $effectiveStart = min($effectiveStart, $endLedger);
        }

        return $effectiveStart;
    }

    private function fetchLocalResumeLedger(int $localContractId): ?int
    {
        try {
            $value = $this->connection->fetchOne(
                'SELECT GREATEST(
                    COALESCE((SELECT MAX(ledger) FROM contract_transactions WHERE contract_id = :contract_id), 0),
                    COALESCE((SELECT MAX(ledger) FROM contract_events WHERE contract_id = :contract_id), 0),
                    COALESCE((SELECT MAX(last_modified_ledger_seq) FROM contract_storage_entries WHERE contract_id = :contract_id), 0)
                ) AS max_ledger',
                ['contract_id' => $localContractId],
                ['contract_id' => ParameterType::INTEGER]
            );
        } catch (\Throwable) {
            return null;
        }

        if ($value === null) {
            return null;
        }

        return max(0, (int) $value);
    }

    private function resolveLedgerOverlap(): int
    {
        $raw = trim((string) (getenv('SOROBAN_SYNC_LEDGER_OVERLAP') ?: ''));
        if ($raw !== '' && preg_match('/^[0-9]+$/', $raw) === 1) {
            return max(0, (int) $raw);
        }

        return self::DEFAULT_LEDGER_OVERLAP;
    }

    private function shouldForceStartLedger(): bool
    {
        $raw = trim((string) (getenv('SOROBAN_SYNC_FORCE_START') ?: ''));
        if ($raw === '') {
            return false;
        }

        return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
    }
}
