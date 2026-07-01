<?php

declare(strict_types=1);

namespace App\Service\Stellar\Soroban;

final class LedgerJsonContractExtractor
{
    public function __construct(
        private readonly SorobanContractInspector $contractInspector,
    ) {
    }

    /**
     * @return array{
     *     sequence:int,
     *     closedAt:?string,
     *     transactions:list<array<string,mixed>>
     * }
     */
    public function extract(array $ledger, bool $includeDiagnosticEvents = true): array
    {
        $sequence = (int) ($ledger['sequence'] ?? 0);
        $closedAt = $this->normalizeLedgerCloseTime($ledger['ledgerCloseTime'] ?? null);
        $metadata = is_array($ledger['metadataJson'] ?? null) ? $ledger['metadataJson'] : [];
        $v2 = is_array($metadata['v2'] ?? null) ? $metadata['v2'] : $metadata;

        $envelopes = [];
        $this->collectTransactionEnvelopes($v2['tx_set'] ?? null, $envelopes);

        $txProcessingRows = is_array($v2['tx_processing'] ?? null) ? array_values($v2['tx_processing']) : [];
        $transactions = [];
        foreach ($txProcessingRows as $index => $txProcessing) {
            if (!is_array($txProcessing)) {
                continue;
            }

            $envelope = is_array($envelopes[$index] ?? null) ? $envelopes[$index] : [];
            $txHash = $this->extractTransactionHash($txProcessing);
            if ($txHash === null) {
                continue;
            }

            $operations = is_array($envelope['operations'] ?? null) ? $envelope['operations'] : [];
            $invokeCalls = $this->extractInvokeCallsFromOperations($operations);
            $operationTypes = $this->extractOperationTypes($operations);
            $events = $this->extractEvents($txProcessing, $txHash, $sequence, $closedAt, $includeDiagnosticEvents);
            $storage = $this->extractStorageEntries($txProcessing, $sequence);
            $contractMeta = $this->extractContractMetaFromStorage($storage);

            $contractIds = [];
            foreach ($invokeCalls as $call) {
                $this->addContractId($contractIds, $call['contractId'] ?? null);
                foreach ($this->extractContractIdsFromMixed($call['args'] ?? []) as $contractId) {
                    $contractIds[$contractId] = true;
                }
            }
            foreach ($events as $event) {
                $this->addContractId($contractIds, $event['contractId'] ?? null);
            }
            foreach ($storage as $entry) {
                $this->addContractId($contractIds, $entry['contractId'] ?? null);
            }

            if ($contractIds === []) {
                continue;
            }

            $eventsByContract = [];
            foreach ($events as $event) {
                $contractId = (string) ($event['contractId'] ?? '');
                if ($contractId === '') {
                    continue;
                }
                $eventsByContract[$contractId] ??= [];
                $event['eventIndex'] = count($eventsByContract[$contractId]) + 1;
                $eventsByContract[$contractId][] = $event;
            }

            $storageByContract = [];
            foreach ($storage as $entry) {
                $contractId = (string) ($entry['contractId'] ?? '');
                if ($contractId === '') {
                    continue;
                }
                $storageByContract[$contractId] ??= [];
                $storageByContract[$contractId][] = $entry;
            }

            $transactions[] = [
                'txHash' => $txHash,
                'sourceAccount' => $this->normalizeNullableString($envelope['sourceAccount'] ?? null),
                'feeCharged' => $this->extractFeeCharged($txProcessing),
                'maxFee' => isset($envelope['maxFee']) ? (int) $envelope['maxFee'] : 0,
                'ledger' => $sequence,
                'createdAt' => $closedAt,
                'totalOperations' => count($operations),
                'operationTypes' => $operationTypes,
                'invokeCalls' => $invokeCalls,
                'effectsCount' => count($events),
                'contractIds' => array_keys($contractIds),
                'eventsByContract' => $eventsByContract,
                'storageByContract' => $storageByContract,
                'contractMetaByContract' => $contractMeta,
                'returnValue' => $this->extractReturnValue($txProcessing),
                'resourceFeeCharged' => $this->extractResourceFeeCharged($txProcessing),
                'envelopeDecoded' => $envelope['raw'] ?? null,
                'metaDecoded' => $txProcessing,
            ];
        }

        return [
            'sequence' => $sequence,
            'closedAt' => $closedAt,
            'transactions' => $transactions,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $result
     */
    private function collectTransactionEnvelopes(mixed $node, array &$result): void
    {
        if (!is_array($node)) {
            return;
        }

        if (isset($node['tx_fee_bump']) && is_array($node['tx_fee_bump'])) {
            $result[] = $this->extractFeeBumpEnvelope($node['tx_fee_bump']);
            return;
        }

        if (
            isset($node['tx'])
            && is_array($node['tx'])
            && isset($node['tx']['tx'], $node['tx']['signatures'])
        ) {
            $normal = $this->extractNormalEnvelope($node);
            if ($normal !== null) {
                $result[] = $normal;
                return;
            }
        }

        foreach ($node as $child) {
            $this->collectTransactionEnvelopes($child, $result);
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function extractNormalEnvelope(array $envelope): ?array
    {
        $tx = $envelope['tx']['tx'] ?? null;
        if (!is_array($tx) || !is_array($tx['operations'] ?? null)) {
            return null;
        }

        return [
            'sourceAccount' => $this->normalizeNullableString($tx['source_account'] ?? null),
            'maxFee' => isset($tx['fee']) ? (int) $tx['fee'] : 0,
            'operations' => array_values($tx['operations']),
            'raw' => $envelope,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function extractFeeBumpEnvelope(array $feeBump): array
    {
        $innerTx = $feeBump['tx']['inner_tx']['tx']['tx'] ?? null;
        if (!is_array($innerTx)) {
            $innerTx = [];
        }

        return [
            'sourceAccount' => $this->normalizeNullableString($innerTx['source_account'] ?? null),
            'maxFee' => isset($feeBump['tx']['fee']) ? (int) $feeBump['tx']['fee'] : 0,
            'operations' => is_array($innerTx['operations'] ?? null) ? array_values($innerTx['operations']) : [],
            'raw' => ['tx_fee_bump' => $feeBump],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function extractInvokeCallsFromOperations(array $operations): array
    {
        $calls = [];
        foreach ($operations as $operation) {
            if (!is_array($operation)) {
                continue;
            }

            $hostFunction = $operation['body']['invoke_host_function']['host_function'] ?? null;
            if (!is_array($hostFunction)) {
                continue;
            }

            if (is_array($hostFunction['invoke_contract'] ?? null)) {
                $invoke = $hostFunction['invoke_contract'];
                $contractId = $this->normalizeContractId($invoke['contract_address'] ?? null);
                if ($contractId === null) {
                    continue;
                }

                $calls[] = [
                    'type' => 'invoke_contract',
                    'contractId' => $contractId,
                    'functionName' => $this->normalizeNullableString($invoke['function_name'] ?? null) ?? 'unknown',
                    'args' => $this->normalizeScValList($invoke['args'] ?? []),
                ];
                continue;
            }

            if (is_array($hostFunction['create_contract'] ?? null)) {
                $calls[] = [
                    'type' => 'create_contract',
                    'contractId' => null,
                    'functionName' => 'create_contract',
                    'args' => $this->normalizeScValJson($hostFunction['create_contract']),
                ];
                continue;
            }

            if (is_array($hostFunction['create_contract_v2'] ?? null)) {
                $calls[] = [
                    'type' => 'create_contract_v2',
                    'contractId' => null,
                    'functionName' => 'create_contract_v2',
                    'args' => $this->normalizeScValJson($hostFunction['create_contract_v2']),
                ];
                continue;
            }

            if (array_key_exists('upload_contract_wasm', $hostFunction)) {
                $calls[] = [
                    'type' => 'upload_contract_wasm',
                    'contractId' => null,
                    'functionName' => 'upload_contract_wasm',
                    'args' => null,
                ];
            }
        }

        return $calls;
    }

    /**
     * @return list<string>
     */
    private function extractOperationTypes(array $operations): array
    {
        $types = [];
        foreach ($operations as $operation) {
            if (!is_array($operation)) {
                continue;
            }
            $body = is_array($operation['body'] ?? null) ? $operation['body'] : [];
            foreach (array_keys($body) as $type) {
                $types[$type] = true;
            }
        }

        return array_keys($types);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function extractEvents(
        array $txProcessing,
        string $txHash,
        int $ledger,
        ?string $closedAt,
        bool $includeDiagnosticEvents
    ): array {
        $events = [];
        $this->collectEvents($txProcessing, $events, false, $includeDiagnosticEvents);

        $normalized = [];
        foreach ($events as $eventRow) {
            $event = is_array($eventRow['event'] ?? null) ? $eventRow['event'] : $eventRow;
            $contractId = $this->normalizeContractId($event['contract_id'] ?? null);
            if ($contractId === null) {
                continue;
            }

            $body = is_array($event['body']['v0'] ?? null) ? $event['body']['v0'] : [];
            $topicDecoded = $this->normalizeScValList($body['topics'] ?? []);
            $valueDecoded = $this->normalizeScValJson($body['data'] ?? null);
            $eventType = $this->detectEventType($topicDecoded, $event['type_'] ?? null);
            $addresses = $this->extractAddressesFromMixed([$topicDecoded, $valueDecoded]);

            $normalized[] = [
                'contractId' => $contractId,
                'txHash' => $txHash,
                'ledger' => $ledger,
                'ledgerClosedAt' => $closedAt,
                'eventType' => $eventType,
                'topicDecoded' => $topicDecoded,
                'valueDecoded' => $valueDecoded,
                'addresses' => $addresses,
                'amountRaw' => $this->normalizeAmountRaw($valueDecoded),
                'isDiagnostic' => (bool) ($eventRow['isDiagnostic'] ?? false),
                'raw' => $eventRow,
            ];
        }

        return $normalized;
    }

    /**
     * @param list<array<string,mixed>> $events
     */
    private function collectEvents(mixed $node, array &$events, bool $diagnostic, bool $includeDiagnosticEvents): void
    {
        if (!is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            if ($key === 'diagnostic_events') {
                if ($includeDiagnosticEvents && is_array($value)) {
                    foreach ($value as $eventRow) {
                        if (is_array($eventRow)) {
                            $eventRow['isDiagnostic'] = true;
                            $events[] = $eventRow;
                        }
                    }
                }
                continue;
            }

            if ($key === 'events' && is_array($value)) {
                foreach ($value as $eventRow) {
                    if (is_array($eventRow)) {
                        $eventRow['isDiagnostic'] = $diagnostic;
                        $events[] = $eventRow;
                    }
                }
                continue;
            }

            $this->collectEvents($value, $events, $diagnostic, $includeDiagnosticEvents);
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function extractStorageEntries(array $txProcessing, int $ledger): array
    {
        $entries = [];
        $this->collectStorageEntries($txProcessing, $entries, $ledger);

        return $entries;
    }

    /**
     * @param list<array<string,mixed>> $entries
     */
    private function collectStorageEntries(mixed $node, array &$entries, int $ledger): void
    {
        if (!is_array($node)) {
            return;
        }

        foreach (['created', 'updated', 'state'] as $changeType) {
            $change = $node[$changeType] ?? null;
            if (!is_array($change)) {
                continue;
            }

            $contractData = $change['data']['contract_data'] ?? null;
            if (is_array($contractData)) {
                $contractId = $this->normalizeContractId($contractData['contract'] ?? null);
                if ($contractId !== null) {
                    $entries[] = [
                        'contractId' => $contractId,
                        'key' => $this->buildStorageKey($contractData['key'] ?? null),
                        'xdr' => null,
                        'lastModifiedLedgerSeq' => isset($change['last_modified_ledger_seq'])
                            ? (int) $change['last_modified_ledger_seq']
                            : $ledger,
                        'liveUntilLedgerSeq' => null,
                        'changeType' => $changeType,
                        'contractData' => $contractData,
                    ];
                }
            }
        }

        foreach ($node as $child) {
            $this->collectStorageEntries($child, $entries, $ledger);
        }
    }

    /**
     * @param list<array<string,mixed>> $storage
     * @return array<string,array<string,mixed>>
     */
    private function extractContractMetaFromStorage(array $storage): array
    {
        $meta = [];
        foreach ($storage as $entry) {
            $contractId = (string) ($entry['contractId'] ?? '');
            if ($contractId === '') {
                continue;
            }

            $contractData = is_array($entry['contractData'] ?? null) ? $entry['contractData'] : [];
            $wasmId = $contractData['val']['contract_instance']['executable']['wasm'] ?? null;
            if (is_string($wasmId) && preg_match('/^[0-9a-fA-F]{64}$/', $wasmId) === 1) {
                $meta[$contractId]['wasmId'] = strtolower($wasmId);
                $meta[$contractId]['executableType'] = 1;
            }

            if (
                ($entry['changeType'] ?? null) === 'created'
                && ($contractData['key'] ?? null) === 'ledger_key_contract_instance'
                && is_array($contractData['val']['contract_instance'] ?? null)
            ) {
                $meta[$contractId]['deployed'] = true;
                $meta[$contractId]['deploymentKind'] = 'contract_instance_created';
            }
        }

        return $meta;
    }

    /**
     * @return list<mixed>
     */
    private function normalizeScValList(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        $result = [];
        foreach ($values as $value) {
            $result[] = $this->normalizeScValJson($value);
        }

        return $result;
    }

    private function normalizeScValJson(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        foreach (['address', 'symbol', 'string', 'bytes', 'u32', 'i32', 'u64', 'i64', 'u128', 'i128', 'u256', 'i256', 'timepoint', 'duration', 'bool'] as $key) {
            if (array_key_exists($key, $value) && count($value) === 1) {
                return $value[$key];
            }
        }

        if (array_key_exists('void', $value) && count($value) === 1) {
            return null;
        }

        if (isset($value['vec']) && is_array($value['vec'])) {
            return $this->normalizeScValList($value['vec']);
        }

        if (isset($value['map']) && is_array($value['map'])) {
            $mapped = [];
            $list = [];
            $associative = true;
            foreach ($value['map'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $key = $this->normalizeScValJson($item['key'] ?? null);
                $val = $this->normalizeScValJson($item['val'] ?? null);
                if (!is_string($key) && !is_int($key)) {
                    $associative = false;
                }
                $list[] = ['key' => $key, 'val' => $val];
                if (is_string($key) || is_int($key)) {
                    $mapped[(string) $key] = $val;
                }
            }

            return $associative ? $mapped : $list;
        }

        $normalized = [];
        foreach ($value as $key => $child) {
            $normalized[$key] = $this->normalizeScValJson($child);
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function extractAddressesFromMixed(mixed $value): array
    {
        $addresses = [];
        $this->collectAddresses($value, $addresses);

        return array_values(array_unique($addresses));
    }

    /**
     * @param list<string> $addresses
     */
    private function collectAddresses(mixed $value, array &$addresses): void
    {
        if (is_string($value)) {
            if (preg_match('/^[GC][A-Z2-7]{55}$/', $value) === 1) {
                $addresses[] = $value;
            }
            return;
        }

        if (!is_array($value)) {
            return;
        }

        foreach ($value as $child) {
            $this->collectAddresses($child, $addresses);
        }
    }

    /**
     * @return list<string>
     */
    private function extractContractIdsFromMixed(mixed $value): array
    {
        $ids = [];
        $this->collectContractIds($value, $ids);

        return array_keys($ids);
    }

    /**
     * @param array<string,bool> $ids
     */
    private function collectContractIds(mixed $value, array &$ids): void
    {
        if (is_string($value)) {
            $contractId = $this->normalizeContractId($value);
            if ($contractId !== null) {
                $ids[$contractId] = true;
            }
            return;
        }

        if (!is_array($value)) {
            return;
        }

        foreach ($value as $child) {
            $this->collectContractIds($child, $ids);
        }
    }

    private function detectEventType(array $topicDecoded, mixed $fallback): string
    {
        foreach ($topicDecoded as $item) {
            if (!is_string($item)) {
                continue;
            }
            $lower = strtolower(trim($item));
            if (in_array($lower, ['transfer', 'mint', 'burn', 'approve'], true)) {
                return $lower;
            }
        }

        foreach ($topicDecoded as $item) {
            if (is_string($item) && trim($item) !== '') {
                return strtolower(trim($item));
            }
        }

        return is_string($fallback) && trim($fallback) !== '' ? strtolower(trim($fallback)) : 'unknown';
    }

    private function normalizeAmountRaw(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_string($value) && preg_match('/^-?[0-9]+$/', $value) === 1) {
            return $value;
        }
        if (is_array($value)) {
            if (array_key_exists('amount', $value)) {
                return $this->normalizeAmountRaw($value['amount']);
            }
            foreach ($value as $child) {
                $nested = $this->normalizeAmountRaw($child);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    private function extractTransactionHash(array $txProcessing): ?string
    {
        $hash = $txProcessing['result']['transaction_hash'] ?? null;
        return is_string($hash) && preg_match('/^[0-9a-fA-F]{64}$/', $hash) === 1 ? strtolower($hash) : null;
    }

    private function extractFeeCharged(array $txProcessing): int
    {
        $fee = $txProcessing['result']['result']['fee_charged'] ?? null;
        return is_numeric($fee) ? (int) $fee : 0;
    }

    private function extractReturnValue(array $txProcessing): mixed
    {
        $sorobanMeta = $this->findFirstKeyRecursive($txProcessing, 'soroban_meta');
        if (!is_array($sorobanMeta)) {
            return null;
        }

        return $this->normalizeScValJson($sorobanMeta['return_value'] ?? null);
    }

    private function extractResourceFeeCharged(array $txProcessing): ?int
    {
        $sorobanMeta = $this->findFirstKeyRecursive($txProcessing, 'soroban_meta');
        if (!is_array($sorobanMeta)) {
            return null;
        }

        $ext = $sorobanMeta['ext']['v1'] ?? null;
        if (!is_array($ext)) {
            return null;
        }

        $nonRefundable = is_numeric($ext['total_non_refundable_resource_fee_charged'] ?? null)
            ? (int) $ext['total_non_refundable_resource_fee_charged']
            : 0;
        $refundable = is_numeric($ext['total_refundable_resource_fee_charged'] ?? null)
            ? (int) $ext['total_refundable_resource_fee_charged']
            : 0;

        return $nonRefundable + $refundable;
    }

    private function findFirstKeyRecursive(mixed $node, string $targetKey): mixed
    {
        if (!is_array($node)) {
            return null;
        }
        if (array_key_exists($targetKey, $node)) {
            return $node[$targetKey];
        }
        foreach ($node as $child) {
            $found = $this->findFirstKeyRecursive($child, $targetKey);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function buildStorageKey(mixed $key): string
    {
        $encoded = json_encode($this->normalizeScValJson($key), JSON_UNESCAPED_SLASHES);
        $encoded = is_string($encoded) ? $encoded : 'null';
        if (strlen($encoded) <= 480) {
            return $encoded;
        }

        return 'sha256:' . hash('sha256', $encoded);
    }

    private function normalizeLedgerCloseTime(mixed $value): ?string
    {
        if (!is_numeric($value)) {
            return null;
        }

        return (new \DateTimeImmutable('@' . (int) $value))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    private function normalizeContractId(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $normalized = $this->contractInspector->normalizeContractId(trim($value));
        return is_string($normalized) && $normalized !== '' ? strtoupper($normalized) : null;
    }

    /**
     * @param array<string,bool> $contractIds
     */
    private function addContractId(array &$contractIds, mixed $value): void
    {
        $contractId = $this->normalizeContractId($value);
        if ($contractId !== null) {
            $contractIds[$contractId] = true;
        }
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed !== '' ? $trimmed : null;
    }
}
