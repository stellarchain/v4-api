<?php

namespace App\Service;

use App\Service\Stellar\Soroban\SorobanContractInspector;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ContractTxUpsertService
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private readonly Connection $connection,
        private readonly SorobanContractInspector $sorobanContractInspector,
    ) {
    }

    /**
     * @param array<int,array<string,mixed>> $transactions
     * @return array{int,int,int}
     */
    public function upsertTransactionsForContract(int $contractId, array $transactions): array
    {
        if ($transactions === []) {
            return [0, 0, 0];
        }

        $txHashes = [];
        foreach ($transactions as $tx) {
            $txHash = isset($tx['txHash']) && is_string($tx['txHash']) ? trim($tx['txHash']) : '';
            if ($txHash !== '') {
                $txHashes[$txHash] = true;
            }
        }

        if ($txHashes === []) {
            return [0, 0, 0];
        }

        $existingRows = $this->connection->fetchAllAssociative(
            'SELECT id, tx_hash, source_account, host_functions, fee_charged, max_fee, ledger, total_operations, created_at
             FROM contract_transactions
             WHERE contract_id = :contract_id AND tx_hash IN (:tx_hashes)',
            [
                'contract_id' => $contractId,
                'tx_hashes' => array_keys($txHashes),
            ],
            [
                'contract_id' => ParameterType::INTEGER,
                'tx_hashes' => ArrayParameterType::STRING,
            ],
        );
        $existingByHash = [];
        foreach ($existingRows as $row) {
            $existingByHash[(string) $row['tx_hash']] = $row;
        }

        $inserted = 0;
        $updated = 0;
        $unchanged = 0;
        $processedHashes = [];

        foreach ($transactions as $tx) {
            $txHash = isset($tx['txHash']) && is_string($tx['txHash']) ? trim($tx['txHash']) : '';
            if ($txHash === '') {
                continue;
            }
            $processedHashes[$txHash] = true;

            $rowPayload = [
                'source_account' => $this->normalizeNullableString($tx['sourceAccount'] ?? null),
                'host_functions' => $this->normalizeNullableString($tx['hostFunctions'] ?? null),
                'fee_charged' => (int) ($tx['feeCharged'] ?? 0),
                'max_fee' => (int) ($tx['maxFee'] ?? 0),
                'ledger' => isset($tx['ledger']) ? (int) $tx['ledger'] : null,
                'total_operations' => isset($tx['totalOperations']) ? (int) $tx['totalOperations'] : null,
                'created_at' => $this->normalizeDateTime($tx['createdAt'] ?? null),
            ];

            $existing = $existingByHash[$txHash] ?? null;
            if (!is_array($existing)) {
                $this->connection->insert('contract_transactions', [
                    'contract_id' => $contractId,
                    'tx_hash' => $txHash,
                    ...$rowPayload,
                ], [
                    'contract_id' => ParameterType::INTEGER,
                    'fee_charged' => ParameterType::INTEGER,
                    'max_fee' => ParameterType::INTEGER,
                    'ledger' => $rowPayload['ledger'] !== null ? ParameterType::INTEGER : ParameterType::NULL,
                    'total_operations' => $rowPayload['total_operations'] !== null ? ParameterType::INTEGER : ParameterType::NULL,
                ]);
                $inserted++;
                continue;
            }

            if ($this->isSameTransactionRow($existing, $rowPayload)) {
                $unchanged++;
                continue;
            }

            $this->connection->update(
                'contract_transactions',
                $rowPayload,
                ['id' => (int) $existing['id']],
                [
                    'fee_charged' => ParameterType::INTEGER,
                    'max_fee' => ParameterType::INTEGER,
                    'ledger' => $rowPayload['ledger'] !== null ? ParameterType::INTEGER : ParameterType::NULL,
                    'total_operations' => $rowPayload['total_operations'] !== null ? ParameterType::INTEGER : ParameterType::NULL,
                    'id' => ParameterType::INTEGER,
                ]
            );
            $updated++;
        }

        $this->syncDerivedArgumentUsageIndex($contractId, array_keys($processedHashes));

        return [$inserted, $updated, $unchanged];
    }

    /**
     * @param array<int,array<string,mixed>> $events
     */
    public function upsertEventsForContract(int $contractId, array $events): void
    {
        if ($events === []) {
            return;
        }

        foreach ($events as $event) {
            $txHash = isset($event['txHash']) && is_string($event['txHash']) ? trim($event['txHash']) : '';
            $eventIndex = isset($event['eventIndex']) ? (int) $event['eventIndex'] : 0;
            if ($txHash === '' || $eventIndex < 1) {
                continue;
            }

            $rowPayload = [
                'ledger' => isset($event['ledger']) ? (int) $event['ledger'] : null,
                'ledger_closed_at' => $this->normalizeDateTime($event['ledgerClosedAt'] ?? null),
                'event_type' => $this->normalizeNullableString($event['eventType'] ?? null) ?? 'unknown',
                'topic_decoded' => $this->encodeJson($event['topicDecoded'] ?? null),
                'value_decoded' => $this->encodeJson($event['valueDecoded'] ?? null),
                'addresses' => $this->encodeJson($event['addresses'] ?? null),
                'amount_raw' => $this->normalizeNullableString($event['amountRaw'] ?? null),
                'created_at' => $this->normalizeDateTime($event['ledgerClosedAt'] ?? null),
            ];

            $this->connection->executeStatement(
                'INSERT INTO contract_events
                (contract_id, tx_hash, event_idx, ledger, ledger_closed_at, event_type, topic_decoded, value_decoded, addresses, amount_raw, created_at)
                VALUES
                (:contract_id, :tx_hash, :event_idx, :ledger, :ledger_closed_at, :event_type, :topic_decoded, :value_decoded, :addresses, :amount_raw, :created_at)
                ON DUPLICATE KEY UPDATE
                    ledger = VALUES(ledger),
                    ledger_closed_at = VALUES(ledger_closed_at),
                    event_type = VALUES(event_type),
                    topic_decoded = VALUES(topic_decoded),
                    value_decoded = VALUES(value_decoded),
                    addresses = VALUES(addresses),
                    amount_raw = VALUES(amount_raw),
                    created_at = VALUES(created_at)',
                [
                    'contract_id' => $contractId,
                    'tx_hash' => $txHash,
                    'event_idx' => $eventIndex,
                    'ledger' => $rowPayload['ledger'],
                    'ledger_closed_at' => $rowPayload['ledger_closed_at'],
                    'event_type' => $rowPayload['event_type'],
                    'topic_decoded' => $rowPayload['topic_decoded'],
                    'value_decoded' => $rowPayload['value_decoded'],
                    'addresses' => $rowPayload['addresses'],
                    'amount_raw' => $rowPayload['amount_raw'],
                    'created_at' => $rowPayload['created_at'],
                ],
                [
                    'contract_id' => ParameterType::INTEGER,
                    'event_idx' => ParameterType::INTEGER,
                    'ledger' => $rowPayload['ledger'] !== null ? ParameterType::INTEGER : ParameterType::NULL,
                ]
            );
        }

        $this->refreshContractHolderBalances($contractId);
    }

    public function rebuildArgumentUsageIndexForContract(int $contractId, int $batchSize = 2000): void
    {
        $offset = 0;
        while (true) {
            $hashes = $this->connection->fetchFirstColumn(
                'SELECT tx_hash
                 FROM contract_transactions
                 WHERE contract_id = :contract_id
                 ORDER BY id ASC
                 LIMIT :limit_rows OFFSET :offset_rows',
                [
                    'contract_id' => $contractId,
                    'limit_rows' => max(1, $batchSize),
                    'offset_rows' => max(0, $offset),
                ],
                [
                    'contract_id' => ParameterType::INTEGER,
                    'limit_rows' => ParameterType::INTEGER,
                    'offset_rows' => ParameterType::INTEGER,
                ]
            );
            if (!is_array($hashes) || $hashes === []) {
                break;
            }

            $normalized = [];
            foreach ($hashes as $hash) {
                if (!is_string($hash) || trim($hash) === '') {
                    continue;
                }
                $normalized[] = trim($hash);
            }
            if ($normalized !== []) {
                $this->syncDerivedArgumentUsageIndex($contractId, $normalized);
            }

            if (count($hashes) < $batchSize) {
                break;
            }
            $offset += $batchSize;
        }
    }

    public function rebuildHolderBalancesForContract(int $contractId): void
    {
        $this->refreshContractHolderBalances($contractId);
    }

    /**
     * @param array<int,array<string,mixed>> $storageEntries
     */
    public function upsertStorageEntriesForContract(int $contractId, array $storageEntries): void
    {
        if ($storageEntries === []) {
            return;
        }

        $existingRows = $this->connection->fetchAllAssociative(
            'SELECT id, storage_key, entry_xdr, entry_decoded, last_modified_ledger_seq, live_until_ledger_seq
             FROM contract_storage_entries
             WHERE contract_id = :contract_id',
            ['contract_id' => $contractId],
            ['contract_id' => ParameterType::INTEGER],
        );
        $existingByKey = [];
        foreach ($existingRows as $row) {
            $existingByKey[(string) $row['storage_key']] = $row;
        }

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        foreach ($storageEntries as $entry) {
            $storageKey = isset($entry['key']) && is_string($entry['key']) ? trim($entry['key']) : '';
            if ($storageKey === '') {
                continue;
            }

            $rowPayload = [
                'entry_xdr' => $this->normalizeNullableString($entry['xdr'] ?? null),
                'entry_decoded' => $this->encodeJson($entry),
                'last_modified_ledger_seq' => isset($entry['lastModifiedLedgerSeq']) ? (int) $entry['lastModifiedLedgerSeq'] : null,
                'live_until_ledger_seq' => isset($entry['liveUntilLedgerSeq']) ? (int) $entry['liveUntilLedgerSeq'] : null,
                'updated_at' => $now,
            ];

            $existing = $existingByKey[$storageKey] ?? null;
            if (!is_array($existing)) {
                $this->connection->insert('contract_storage_entries', [
                    'contract_id' => $contractId,
                    'storage_key' => $storageKey,
                    ...$rowPayload,
                ], [
                    'contract_id' => ParameterType::INTEGER,
                    'last_modified_ledger_seq' => $rowPayload['last_modified_ledger_seq'] !== null ? ParameterType::INTEGER : ParameterType::NULL,
                    'live_until_ledger_seq' => $rowPayload['live_until_ledger_seq'] !== null ? ParameterType::INTEGER : ParameterType::NULL,
                ]);
                continue;
            }

            if ($this->isSameStorageRow($existing, $rowPayload)) {
                continue;
            }

            $this->connection->update(
                'contract_storage_entries',
                $rowPayload,
                ['id' => (int) $existing['id']],
                [
                    'last_modified_ledger_seq' => $rowPayload['last_modified_ledger_seq'] !== null ? ParameterType::INTEGER : ParameterType::NULL,
                    'live_until_ledger_seq' => $rowPayload['live_until_ledger_seq'] !== null ? ParameterType::INTEGER : ParameterType::NULL,
                    'id' => ParameterType::INTEGER,
                ]
            );
        }
    }

    /**
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $payload
     */
    private function isSameTransactionRow(array $existing, array $payload): bool
    {
        return $this->normalizeNullableString($existing['source_account'] ?? null) === $payload['source_account']
            && $this->normalizeNullableString($existing['host_functions'] ?? null) === $payload['host_functions']
            && (int) ($existing['fee_charged'] ?? 0) === (int) $payload['fee_charged']
            && (int) ($existing['max_fee'] ?? 0) === (int) $payload['max_fee']
            && $this->normalizeNullableInt($existing['ledger'] ?? null) === $payload['ledger']
            && $this->normalizeNullableInt($existing['total_operations'] ?? null) === $payload['total_operations']
            && $this->normalizeDateTime($existing['created_at'] ?? null) === $payload['created_at'];
    }

    /**
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $payload
     */
    private function isSameStorageRow(array $existing, array $payload): bool
    {
        return $this->normalizeNullableString($existing['entry_xdr'] ?? null) === $payload['entry_xdr']
            && $this->normalizeNullableString($existing['entry_decoded'] ?? null) === $payload['entry_decoded']
            && $this->normalizeNullableInt($existing['last_modified_ledger_seq'] ?? null) === $payload['last_modified_ledger_seq']
            && $this->normalizeNullableInt($existing['live_until_ledger_seq'] ?? null) === $payload['live_until_ledger_seq'];
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed !== '' ? $trimmed : null;
    }

    private function normalizeNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function encodeJson(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            return null;
        }

        return $encoded;
    }

    /**
     * @param list<string> $txHashes
     */
    private function syncDerivedArgumentUsageIndex(int $contractId, array $txHashes): void
    {
        if ($txHashes === []) {
            return;
        }

        try {
            $target = $this->connection->fetchAssociative(
                'SELECT contract_id, network FROM contracts WHERE id = :id LIMIT 1',
                ['id' => $contractId],
                ['id' => ParameterType::INTEGER]
            );
            if (!is_array($target)) {
                return;
            }
            $targetContractAddress = trim((string) ($target['contract_id'] ?? ''));
            $networkCode = isset($target['network']) ? (int) $target['network'] : 1;
            if ($targetContractAddress === '') {
                return;
            }

            $txRows = $this->connection->fetchAllAssociative(
                'SELECT id, tx_hash, source_account, ledger, created_at, host_functions
                 FROM contract_transactions
                 WHERE contract_id = :contract_id
                   AND tx_hash IN (:hashes)',
                [
                    'contract_id' => $contractId,
                    'hashes' => $txHashes,
                ],
                [
                    'contract_id' => ParameterType::INTEGER,
                    'hashes' => ArrayParameterType::STRING,
                ]
            );
            if ($txRows === []) {
                return;
            }

            $txIds = [];
            foreach ($txRows as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id > 0) {
                    $txIds[] = $id;
                }
            }
            if ($txIds !== []) {
                $this->connection->executeStatement(
                    'DELETE FROM contract_argument_usages WHERE contract_transaction_id IN (:ids)',
                    ['ids' => $txIds],
                    ['ids' => ArrayParameterType::INTEGER]
                );
            }

            $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            foreach ($txRows as $row) {
                $contractTxId = (int) ($row['id'] ?? 0);
                if ($contractTxId <= 0) {
                    continue;
                }

                $hostFunctionsRaw = is_string($row['host_functions'] ?? null) ? trim((string) $row['host_functions']) : '';
                if ($hostFunctionsRaw === '') {
                    continue;
                }
                $decoded = json_decode($hostFunctionsRaw, true);
                if (!is_array($decoded)) {
                    continue;
                }
                $invokeContracts = is_array($decoded['invokeContracts'] ?? null) ? $decoded['invokeContracts'] : [];
                if ($invokeContracts === []) {
                    continue;
                }

                foreach ($invokeContracts as $call) {
                    if (!is_array($call)) {
                        continue;
                    }
                    $functionName = trim((string) ($call['functionName'] ?? ''));
                    if ($functionName === '') {
                        $functionName = 'unknown';
                    }
                    $args = is_array($call['args'] ?? null) ? $call['args'] : [];
                    $matchesByContract = [];
                    $this->collectContractArgumentPaths($args, '$.args', $matchesByContract);
                    if ($matchesByContract === []) {
                        continue;
                    }

                    foreach ($matchesByContract as $referencedContractId => $paths) {
                        $paths = array_values(array_unique($paths));
                        $matchedPathsJson = json_encode($paths, JSON_UNESCAPED_SLASHES);
                        $this->connection->executeStatement(
                            'INSERT INTO contract_argument_usages (
                                contract_transaction_id,
                                target_contract_id,
                                target_contract_address,
                                referenced_contract_id,
                                network,
                                tx_hash,
                                source_account,
                                ledger,
                                function_name,
                                matched_paths,
                                matches_count,
                                created_at,
                                updated_at
                            ) VALUES (
                                :contract_transaction_id,
                                :target_contract_id,
                                :target_contract_address,
                                :referenced_contract_id,
                                :network,
                                :tx_hash,
                                :source_account,
                                :ledger,
                                :function_name,
                                :matched_paths,
                                :matches_count,
                                :created_at,
                                :updated_at
                            )',
                            [
                                'contract_transaction_id' => $contractTxId,
                                'target_contract_id' => $contractId,
                                'target_contract_address' => $targetContractAddress,
                                'referenced_contract_id' => $referencedContractId,
                                'network' => $networkCode,
                                'tx_hash' => (string) ($row['tx_hash'] ?? ''),
                                'source_account' => $this->normalizeNullableString($row['source_account'] ?? null),
                                'ledger' => isset($row['ledger']) ? (int) $row['ledger'] : null,
                                'function_name' => $functionName,
                                'matched_paths' => is_string($matchedPathsJson) ? $matchedPathsJson : '[]',
                                'matches_count' => count($paths),
                                'created_at' => $this->normalizeDateTime($row['created_at'] ?? null),
                                'updated_at' => $now,
                            ],
                            [
                                'contract_transaction_id' => ParameterType::INTEGER,
                                'target_contract_id' => ParameterType::INTEGER,
                                'network' => ParameterType::INTEGER,
                                'ledger' => isset($row['ledger']) ? ParameterType::INTEGER : ParameterType::NULL,
                                'matches_count' => ParameterType::INTEGER,
                            ]
                        );
                    }
                }
            }
        } catch (\Throwable) {
            // Derived index is best-effort and must not block core tx ingestion.
        }
    }

    private function refreshContractHolderBalances(int $contractId): void
    {
        try {
            $networkCode = $this->connection->fetchOne(
                'SELECT network FROM contracts WHERE id = :id LIMIT 1',
                ['id' => $contractId],
                ['id' => ParameterType::INTEGER]
            );
            if ($networkCode === false) {
                return;
            }
            $networkCode = (int) $networkCode;

            $this->connection->executeStatement(
                'DELETE FROM contract_holder_balances WHERE contract_id = :contract_id',
                ['contract_id' => $contractId],
                ['contract_id' => ParameterType::INTEGER]
            );

            $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $rows = $this->connection->fetchAllAssociative(
                <<<'SQL'
SELECT
  t.address AS holder_address,
  CAST(SUM(t.delta_amount) AS CHAR) AS balance_raw,
  CAST(SUM(CASE WHEN t.delta_amount > 0 THEN t.delta_amount ELSE 0 END) AS CHAR) AS inflow_raw,
  CAST(ABS(SUM(CASE WHEN t.delta_amount < 0 THEN t.delta_amount ELSE 0 END)) AS CHAR) AS outflow_raw
FROM (
  SELECT JSON_UNQUOTE(JSON_EXTRACT(ce.addresses, '$[0]')) AS address, -CAST(ce.amount_raw AS DECIMAL(65, 0)) AS delta_amount
  FROM contract_events ce
  WHERE ce.contract_id = :contract_id AND ce.event_type = 'transfer' AND ce.amount_raw IS NOT NULL AND ce.amount_raw REGEXP '^-?[0-9]+$'
    AND JSON_UNQUOTE(JSON_EXTRACT(ce.addresses, '$[0]')) IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(ce.addresses, '$[0]')) <> ''
  UNION ALL
  SELECT JSON_UNQUOTE(JSON_EXTRACT(ce.addresses, '$[1]')) AS address, CAST(ce.amount_raw AS DECIMAL(65, 0)) AS delta_amount
  FROM contract_events ce
  WHERE ce.contract_id = :contract_id AND ce.event_type = 'transfer' AND ce.amount_raw IS NOT NULL AND ce.amount_raw REGEXP '^-?[0-9]+$'
    AND JSON_UNQUOTE(JSON_EXTRACT(ce.addresses, '$[1]')) IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(ce.addresses, '$[1]')) <> ''
  UNION ALL
  SELECT JSON_UNQUOTE(JSON_EXTRACT(ce.addresses, '$[0]')) AS address, CAST(ce.amount_raw AS DECIMAL(65, 0)) AS delta_amount
  FROM contract_events ce
  WHERE ce.contract_id = :contract_id AND ce.event_type = 'mint' AND ce.amount_raw IS NOT NULL AND ce.amount_raw REGEXP '^-?[0-9]+$'
    AND JSON_UNQUOTE(JSON_EXTRACT(ce.addresses, '$[0]')) IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(ce.addresses, '$[0]')) <> ''
  UNION ALL
  SELECT JSON_UNQUOTE(JSON_EXTRACT(ce.addresses, '$[0]')) AS address, -CAST(ce.amount_raw AS DECIMAL(65, 0)) AS delta_amount
  FROM contract_events ce
  WHERE ce.contract_id = :contract_id AND ce.event_type = 'burn' AND ce.amount_raw IS NOT NULL AND ce.amount_raw REGEXP '^-?[0-9]+$'
    AND JSON_UNQUOTE(JSON_EXTRACT(ce.addresses, '$[0]')) IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(ce.addresses, '$[0]')) <> ''
) t
GROUP BY t.address
HAVING SUM(t.delta_amount) <> 0
SQL,
                ['contract_id' => $contractId],
                ['contract_id' => ParameterType::INTEGER]
            );

            foreach ($rows as $row) {
                $holder = $this->normalizeNullableString($row['holder_address'] ?? null);
                if ($holder === null) {
                    continue;
                }

                $this->connection->insert('contract_holder_balances', [
                    'contract_id' => $contractId,
                    'network' => $networkCode,
                    'holder_address' => $holder,
                    'balance_raw' => (string) ($row['balance_raw'] ?? '0'),
                    'inflow_raw' => (string) ($row['inflow_raw'] ?? '0'),
                    'outflow_raw' => (string) ($row['outflow_raw'] ?? '0'),
                    'updated_at' => $now,
                ], [
                    'contract_id' => ParameterType::INTEGER,
                    'network' => ParameterType::INTEGER,
                    'updated_at' => ParameterType::STRING,
                ]);
            }
        } catch (\Throwable) {
            // Derived index is best-effort and must not block core events ingestion.
        }
    }

    /**
     * @param array<string,list<string>> $matchesByContract
     */
    private function collectContractArgumentPaths(mixed $value, string $path, array &$matchesByContract): void
    {
        if (is_string($value)) {
            $normalized = $this->sorobanContractInspector->normalizeContractId(trim($value));
            if (is_string($normalized) && $normalized !== '') {
                $normalized = strtoupper($normalized);
                $matchesByContract[$normalized] ??= [];
                $matchesByContract[$normalized][] = $path;
            }
            return;
        }

        if (!is_array($value)) {
            return;
        }

        foreach ($value as $key => $nested) {
            $keyPath = is_int($key) ? sprintf('%s[%d]', $path, $key) : sprintf('%s.%s', $path, (string) $key);
            $this->collectContractArgumentPaths($nested, $keyPath, $matchesByContract);
        }
    }
}
