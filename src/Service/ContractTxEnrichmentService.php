<?php

namespace App\Service;

use App\Service\Stellar\Horizon\HorizonTxEnricher;
use App\Service\Stellar\Soroban\InvokeContractCallExtractor;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ContractTxEnrichmentService
{
    private const CANDIDATE_PAGE_SIZE = 1000;
    private const MAX_TX_BATCH_SIZE = 200;
    private const RECHECK_UNCHANGED_AFTER_HOURS = 24;
    private const RECHECK_FAILED_AFTER_MINUTES = 30;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.contracts_connection')]
        private readonly Connection $connection,
        private readonly HorizonTxEnricher $horizonTxEnricher,
        private readonly InvokeContractCallExtractor $invokeContractCallExtractor,
        private readonly ?ContractMetricsRefreshService $contractMetricsRefreshService = null,
    ) {
    }

    /**
     * @return array{
     *   ok:bool,
     *   transactionCandidates:int,
     *   processed:int,
     *   rowsUpdated:int,
     *   rowsUnchanged:int,
     *   rowsFailed:int,
     *   rateLimitRetries:int,
     *   rateLimitWaitSeconds:int,
     *   remoteErrors:int,
     *   error?:string
     * }
     */
    public function enrichForContract(
        int $localContractId,
        ?string $network = null,
        bool $dryRun = false,
        int $txBatchSize = 50,
        ?int $maxTransactions = null,
        ?callable $onProgress = null,
    ): array {
        $contractId = $this->loadContractId($localContractId);
        if ($contractId === null) {
            return $this->buildErrorResult(sprintf('Contract #%d not found.', $localContractId));
        }

        $candidateLoad = $this->loadCandidateTransactions($localContractId, $maxTransactions);
        if (($candidateLoad['ok'] ?? false) !== true) {
            return $candidateLoad;
        }

        $existingByHash = is_array($candidateLoad['existingByHash'] ?? null) ? $candidateLoad['existingByHash'] : [];
        $txHashes = is_array($candidateLoad['txHashes'] ?? null) ? $candidateLoad['txHashes'] : [];
        if ($txHashes === []) {
            $this->emitProgress($onProgress, ['phase' => 'no_candidates']);
            return $this->buildSuccessResult(0, 0, 0, 0, 0, 0, 0, 0);
        }

        $txBatchSize = max(1, min(self::MAX_TX_BATCH_SIZE, $txBatchSize));
        $txBatches = array_chunk($txHashes, $txBatchSize);
        $batchTotal = count($txBatches);
        $processed = 0;
        $updated = 0;
        $unchanged = 0;
        $failed = 0;
        $rateLimitRetriesTotal = 0;
        $rateLimitWaitSecondsTotal = 0;
        $remoteErrorsTotal = 0;

        foreach ($txBatches as $batchIndex => $batch) {
            $batchResult = $this->processBatch(
                network: $network,
                batch: $batch,
                contractId: $contractId,
                existingByHash: $existingByHash,
                dryRun: $dryRun,
                onProgress: $onProgress,
                batchIndex: $batchIndex + 1,
                batchTotal: $batchTotal,
                processed: $processed,
                updated: $updated,
                unchanged: $unchanged,
                failed: $failed,
            );

            $processed += $batchResult['processed'];
            $updated += $batchResult['rowsUpdated'];
            $unchanged += $batchResult['rowsUnchanged'];
            $failed += $batchResult['rowsFailed'];
            $rateLimitRetriesTotal += $batchResult['rateLimitRetries'];
            $rateLimitWaitSecondsTotal += $batchResult['rateLimitWaitSeconds'];
            $remoteErrorsTotal += $batchResult['remoteErrors'];

            $this->emitProgress($onProgress, [
                'phase' => 'batch_done',
                'batchIndex' => $batchIndex + 1,
                'batchTotal' => $batchTotal,
                'batchSize' => count($batch),
                'batchRateLimitRetries' => $batchResult['rateLimitRetries'],
                'batchRateLimitWaitSeconds' => $batchResult['rateLimitWaitSeconds'],
                'batchRemoteErrors' => $batchResult['remoteErrors'],
                'processed' => $processed,
                'rowsUpdated' => $updated,
                'rowsUnchanged' => $unchanged,
                'rowsFailed' => $failed,
                'rateLimitRetries' => $rateLimitRetriesTotal,
                'rateLimitWaitSeconds' => $rateLimitWaitSecondsTotal,
                'remoteErrors' => $remoteErrorsTotal,
                'remoteErrorSample' => $batchResult['remoteErrorSamples'][0] ?? null,
            ]);
        }

        if (!$dryRun && $this->contractMetricsRefreshService !== null) {
            $this->contractMetricsRefreshService->refreshForContractIds([$localContractId]);
        }

        return $this->buildSuccessResult(
            count($txHashes),
            $processed,
            $updated,
            $unchanged,
            $failed,
            $rateLimitRetriesTotal,
            $rateLimitWaitSecondsTotal,
            $remoteErrorsTotal,
        );
    }

    /**
     * @param array<int,string> $batch
     * @param non-empty-string $contractId
     * @param array<string,array<string,mixed>> $existingByHash
     * @return array{
     *   processed:int,
     *   rowsUpdated:int,
     *   rowsUnchanged:int,
     *   rowsFailed:int,
     *   rateLimitRetries:int,
     *   rateLimitWaitSeconds:int,
     *   remoteErrors:int,
     *   remoteErrorSamples:array<int,array<string,mixed>>
     * }
     */
    private function processBatch(
        ?string $network,
        array $batch,
        string $contractId,
        array $existingByHash,
        bool $dryRun,
        ?callable $onProgress,
        int $batchIndex,
        int $batchTotal,
        int $processed,
        int $updated,
        int $unchanged,
        int $failed,
    ): array {
        $this->emitProgress($onProgress, [
            'phase' => 'batch_start',
            'batchIndex' => $batchIndex,
            'batchTotal' => $batchTotal,
            'batchSize' => count($batch),
            'processed' => $processed,
            'rowsUpdated' => $updated,
            'rowsUnchanged' => $unchanged,
            'rowsFailed' => $failed,
        ]);

        $enriched = $this->horizonTxEnricher->fetchByHashes(
            $network,
            $batch,
            function (array $event) use ($onProgress, $batchIndex, $batchTotal, $processed, $updated, $unchanged, $failed): void {
                $this->emitProgress($onProgress, [
                    'phase' => (string) ($event['phase'] ?? ''),
                    'batchIndex' => $batchIndex,
                    'batchTotal' => $batchTotal,
                    'processed' => $processed,
                    'rowsUpdated' => $updated,
                    'rowsUnchanged' => $unchanged,
                    'rowsFailed' => $failed,
                    'waitSeconds' => (int) ($event['waitSeconds'] ?? 0),
                    'attempt' => (int) ($event['attempt'] ?? 0),
                    'maxRetries' => (int) ($event['maxRetries'] ?? 0),
                    'scope' => (string) ($event['scope'] ?? ''),
                    'txHash' => (string) ($event['txHash'] ?? ''),
                    'httpCode' => $event['httpCode'] ?? null,
                    'rateLimitLimit' => $event['rateLimitLimit'] ?? null,
                    'rateLimitRemaining' => $event['rateLimitRemaining'] ?? null,
                    'rateLimitReset' => $event['rateLimitReset'] ?? null,
                ]);
            }
        );

        $batchProcessed = 0;
        $batchUpdated = 0;
        $batchUnchanged = 0;
        $batchFailed = 0;
        $batchRemoteErrorSamples = is_array($enriched['requestErrorSamples'] ?? null)
            ? array_slice($enriched['requestErrorSamples'], 0, 3)
            : [];
        $items = is_array($enriched['items'] ?? null) ? $enriched['items'] : [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $txHash = isset($item['txHash']) && is_string($item['txHash']) ? trim($item['txHash']) : '';
            if ($txHash === '') {
                continue;
            }
            $batchProcessed++;

            $existing = $existingByHash[$txHash] ?? null;
            if (!is_array($existing)) {
                continue;
            }

            $rowPayload = $this->buildRowPayload($item, $existing, $contractId);
            if ($this->isSameTxEnrichment($existing, $rowPayload)) {
                if (!$dryRun) {
                    $this->markEnrichmentStatus((int) $existing['id'], 'unchanged');
                }
                $batchUnchanged++;
                continue;
            }

            if ($dryRun) {
                $batchUpdated++;
                continue;
            }

            if ($this->persistRowPayload((int) $existing['id'], $rowPayload)) {
                $this->markEnrichmentStatus((int) $existing['id'], 'updated');
                $batchUpdated++;
            } else {
                $this->markEnrichmentStatus((int) $existing['id'], 'failed');
                $batchFailed++;
            }
        }

        return [
            'processed' => $batchProcessed,
            'rowsUpdated' => $batchUpdated,
            'rowsUnchanged' => $batchUnchanged,
            'rowsFailed' => $batchFailed,
            'rateLimitRetries' => (int) ($enriched['rateLimitRetries'] ?? 0),
            'rateLimitWaitSeconds' => (int) ($enriched['rateLimitWaitSeconds'] ?? 0),
            'remoteErrors' => (int) ($enriched['requestErrors'] ?? 0),
            'remoteErrorSamples' => $batchRemoteErrorSamples,
        ];
    }

    /**
     * @return array{
     *   ok:bool,
     *   existingByHash?:array<string,array<string,mixed>>,
     *   txHashes?:array<int,string>,
     *   transactionCandidates?:int,
     *   processed?:int,
     *   rowsUpdated?:int,
     *   rowsUnchanged?:int,
     *   rowsFailed?:int,
     *   rateLimitRetries?:int,
     *   rateLimitWaitSeconds?:int,
     *   remoteErrors?:int,
     *   error?:string
     * }
     */
    private function loadCandidateTransactions(int $localContractId, ?int $maxTransactions): array
    {
        $existingByHash = [];
        $txHashes = [];
        $cursorId = null;
        $remaining = $maxTransactions !== null && $maxTransactions > 0 ? $maxTransactions : null;

        try {
            while (true) {
                $pageSize = $remaining !== null
                    ? max(1, min(self::CANDIDATE_PAGE_SIZE, $remaining))
                    : self::CANDIDATE_PAGE_SIZE;
                $rows = $this->fetchCandidatePage($localContractId, $cursorId, $pageSize);
                if ($rows === []) {
                    break;
                }

                foreach ($rows as $row) {
                    $txHash = isset($row['tx_hash']) && is_string($row['tx_hash']) ? trim($row['tx_hash']) : '';
                    if ($txHash === '' || isset($existingByHash[$txHash])) {
                        continue;
                    }

                    $existingByHash[$txHash] = $row;
                    $txHashes[] = $txHash;
                }

                $lastRow = $rows[count($rows) - 1] ?? null;
                if (!is_array($lastRow)) {
                    break;
                }

                $lastId = isset($lastRow['id']) ? (int) $lastRow['id'] : 0;
                if ($lastId <= 0) {
                    break;
                }
                $cursorId = $lastId;

                if ($remaining !== null) {
                    $remaining -= count($rows);
                    if ($remaining <= 0) {
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            return $this->buildErrorResult($e->getMessage());
        }

        return [
            'ok' => true,
            'existingByHash' => $existingByHash,
            'txHashes' => $txHashes,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchCandidatePage(int $localContractId, ?int $cursorId, int $limit): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $staleUnchanged = $now->sub(new \DateInterval('PT' . self::RECHECK_UNCHANGED_AFTER_HOURS . 'H'));
        $staleFailed = $now->sub(new \DateInterval('PT' . self::RECHECK_FAILED_AFTER_MINUTES . 'M'));

        $params = [
            'contract_id' => $localContractId,
            'limit_rows' => $limit,
            'stale_unchanged_before' => $staleUnchanged->format('Y-m-d H:i:s'),
            'stale_failed_before' => $staleFailed->format('Y-m-d H:i:s'),
        ];
        $types = [
            'contract_id' => ParameterType::INTEGER,
            'limit_rows' => ParameterType::INTEGER,
            'stale_unchanged_before' => ParameterType::STRING,
            'stale_failed_before' => ParameterType::STRING,
        ];

        $cursorSql = '';
        if ($cursorId !== null) {
            $cursorSql = ' AND id < :cursor_id';
            $params['cursor_id'] = $cursorId;
            $types['cursor_id'] = ParameterType::INTEGER;
        }

        return $this->connection->fetchAllAssociative(
            'SELECT id, tx_hash, source_account, host_functions, fee_charged, max_fee, total_operations, created_at
             FROM contract_transactions
             WHERE contract_id = :contract_id
               AND (
                   source_account IS NULL
                   OR host_functions IS NULL
                   OR host_functions LIKE \'%"functionName":"transfer","args":[null,%\'
                   OR host_functions LIKE \'%"invokeContracts":[]%\'
                   OR fee_charged = 0
                   OR max_fee = 0
                   OR total_operations IS NULL
                   OR created_at IS NULL
               )
               AND (
                   enrichment_checked_at IS NULL
                   OR (
                       enrichment_status = \'failed\'
                       AND enrichment_checked_at < :stale_failed_before
                   )
                   OR (
                       (enrichment_status IS NULL OR enrichment_status <> \'failed\')
                       AND enrichment_checked_at < :stale_unchanged_before
                   )
               )' . $cursorSql . '
             ORDER BY id DESC
             LIMIT :limit_rows',
            $params,
            $types,
        );
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $existing
     * @param non-empty-string $contractId
     * @return array<string,mixed>
     */
    private function buildRowPayload(array $item, array $existing, string $contractId): array
    {
        $txResult = is_array($item['transaction']['result'] ?? null) ? $item['transaction']['result'] : [];
        $operations = is_array($item['operations']['result']['_embedded']['records'] ?? null)
            ? $item['operations']['result']['_embedded']['records']
            : [];
        $effects = is_array($item['effects']['result']['_embedded']['records'] ?? null)
            ? $item['effects']['result']['_embedded']['records']
            : [];
        $invokeCalls = $this->invokeContractCallExtractor->fromEnvelopeXdrForContract(
            is_string($txResult['envelope_xdr'] ?? null) ? $txResult['envelope_xdr'] : null
            ,
            $contractId
        );

        $hostFunctions = $this->buildHostFunctionsPayload($operations, $effects, $invokeCalls);
        $existingHostFunctions = $this->nullableString($existing['host_functions'] ?? null);
        // Contract-centric payload: if tx has no invokes for this contract, clear noisy payment-only payload.
        if ($hostFunctions === null && !$this->hasInvokeContracts($existingHostFunctions)) {
            $existingHostFunctions = null;
        }

        return [
            'source_account' => $this->firstNonEmptyString(
                $txResult['source_account'] ?? null,
                $existing['source_account'] ?? null
            ),
            'host_functions' => $hostFunctions ?? $existingHostFunctions,
            'fee_charged' => $this->resolveInt(
                $txResult['fee_charged'] ?? null,
                (int) ($existing['fee_charged'] ?? 0),
            ),
            'max_fee' => $this->resolveInt(
                $txResult['max_fee'] ?? null,
                (int) ($existing['max_fee'] ?? 0),
            ),
            'total_operations' => $this->resolveNullableInt(
                $txResult['operation_count'] ?? null,
                $this->nullableInt($existing['total_operations'] ?? null),
            ),
            'created_at' => $this->resolveDateTime(
                $txResult['created_at'] ?? null,
                $existing['created_at'] ?? null,
            ),
        ];
    }

    /**
     * @param array<string,mixed> $rowPayload
     */
    private function persistRowPayload(int $rowId, array $rowPayload): bool
    {
        try {
            $this->connection->update(
                'contract_transactions',
                $rowPayload,
                ['id' => $rowId],
                [
                    'fee_charged' => ParameterType::INTEGER,
                    'max_fee' => ParameterType::INTEGER,
                    'total_operations' => $rowPayload['total_operations'] !== null ? ParameterType::INTEGER : ParameterType::NULL,
                    'id' => ParameterType::INTEGER,
                ]
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function markEnrichmentStatus(int $rowId, string $status): void
    {
        try {
            $this->connection->executeStatement(
                'UPDATE contract_transactions
                 SET enrichment_status = :status,
                     enrichment_checked_at = :checked_at,
                     enrichment_attempts = COALESCE(enrichment_attempts, 0) + 1
                 WHERE id = :id',
                [
                    'status' => $status,
                    'checked_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                    'id' => $rowId,
                ],
                [
                    'status' => ParameterType::STRING,
                    'checked_at' => ParameterType::STRING,
                    'id' => ParameterType::INTEGER,
                ]
            );
        } catch (\Throwable) {
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function emitProgress(?callable $onProgress, array $payload): void
    {
        if (is_callable($onProgress)) {
            $onProgress($payload);
        }
    }

    private function buildErrorResult(string $message): array
    {
        return [
            'ok' => false,
            'transactionCandidates' => 0,
            'processed' => 0,
            'rowsUpdated' => 0,
            'rowsUnchanged' => 0,
            'rowsFailed' => 0,
            'rateLimitRetries' => 0,
            'rateLimitWaitSeconds' => 0,
            'remoteErrors' => 0,
            'error' => $message,
        ];
    }

    private function buildSuccessResult(
        int $transactionCandidates,
        int $processed,
        int $rowsUpdated,
        int $rowsUnchanged,
        int $rowsFailed,
        int $rateLimitRetries,
        int $rateLimitWaitSeconds,
        int $remoteErrors,
    ): array {
        return [
            'ok' => true,
            'transactionCandidates' => $transactionCandidates,
            'processed' => $processed,
            'rowsUpdated' => $rowsUpdated,
            'rowsUnchanged' => $rowsUnchanged,
            'rowsFailed' => $rowsFailed,
            'rateLimitRetries' => $rateLimitRetries,
            'rateLimitWaitSeconds' => $rateLimitWaitSeconds,
            'remoteErrors' => $remoteErrors,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $operations
     * @param array<int,array<string,mixed>> $effects
     * @param array<int,array<string,mixed>> $invokeCalls
     */
    private function buildHostFunctionsPayload(array $operations, array $effects, array $invokeCalls): ?string
    {
        if ($invokeCalls === []) {
            return null;
        }

        $operationTypes = [];
        $payments = [];
        foreach ($operations as $op) {
            if (!is_array($op)) {
                continue;
            }
            $type = $this->nullableString($op['type'] ?? null);
            if ($type !== null) {
                $operationTypes[] = $type;
            }
            $payment = $this->extractPaymentOperation($op, $type);
            if ($payment !== null) {
                $payments[] = $payment;
            }
        }
        $operationTypes = array_values(array_unique($operationTypes));

        $payload = [
            'operationTypes' => $operationTypes,
            'operationsCount' => count($operations),
            'effectsCount' => count($effects),
            'invokeContracts' => $invokeCalls,
        ];
        if ($payments !== []) {
            $payload['payments'] = $payments;
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
        return is_string($encoded) ? $encoded : null;
    }

    private function hasInvokeContracts(?string $hostFunctions): bool
    {
        if ($hostFunctions === null || $hostFunctions === '') {
            return false;
        }

        $decoded = json_decode($hostFunctions, true);
        if (!is_array($decoded)) {
            return false;
        }

        $invokeContracts = is_array($decoded['invokeContracts'] ?? null) ? $decoded['invokeContracts'] : [];
        return $invokeContracts !== [];
    }

    private function loadContractId(int $localContractId): ?string
    {
        $value = $this->connection->fetchOne(
            'SELECT contract_id FROM contracts WHERE id = :id LIMIT 1',
            ['id' => $localContractId],
            ['id' => ParameterType::INTEGER]
        );

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param array<string,mixed> $operation
     * @return array<string,mixed>|null
     */
    private function extractPaymentOperation(array $operation, ?string $type): ?array
    {
        $normalizedType = strtolower((string) $type);
        if (!in_array($normalizedType, ['payment', 'path_payment_strict_receive', 'path_payment_strict_send'], true)) {
            return null;
        }

        $payment = ['type' => $normalizedType];
        foreach ([
            'from',
            'to',
            'amount',
            'asset_code',
            'source_amount',
            'source_asset_type',
            'source_asset_code',
            'source_asset_issuer',
        ] as $field) {
            $value = $operation[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $payment[$field] = trim($value);
            }
        }

        return $payment;
    }

    private function resolveInt(mixed $candidate, int $fallback): int
    {
        if (is_int($candidate)) {
            return $candidate;
        }
        if (is_string($candidate) && preg_match('/^-?[0-9]+$/', $candidate) === 1) {
            return (int) $candidate;
        }

        return $fallback;
    }

    private function resolveNullableInt(mixed $candidate, ?int $fallback): ?int
    {
        if (is_int($candidate)) {
            return $candidate;
        }
        if (is_string($candidate) && preg_match('/^-?[0-9]+$/', $candidate) === 1) {
            return (int) $candidate;
        }

        return $fallback;
    }

    private function resolveDateTime(mixed $candidate, mixed $fallback): ?string
    {
        $normalized = $this->normalizeDateTime($candidate);
        if ($normalized !== null) {
            return $normalized;
        }

        return $this->normalizeDateTime($fallback);
    }

    private function firstNonEmptyString(mixed $candidate, mixed $fallback): ?string
    {
        $first = $this->nullableString($candidate);
        if ($first !== null) {
            return $first;
        }

        return $this->nullableString($fallback);
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed !== '' ? $trimmed : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $payload
     */
    private function isSameTxEnrichment(array $existing, array $payload): bool
    {
        return $this->nullableString($existing['source_account'] ?? null) === $payload['source_account']
            && $this->nullableString($existing['host_functions'] ?? null) === $payload['host_functions']
            && (int) ($existing['fee_charged'] ?? 0) === (int) $payload['fee_charged']
            && (int) ($existing['max_fee'] ?? 0) === (int) $payload['max_fee']
            && $this->nullableInt($existing['total_operations'] ?? null) === $payload['total_operations']
            && $this->normalizeDateTime($existing['created_at'] ?? null) === $payload['created_at'];
    }
}
