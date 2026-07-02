<?php

declare(strict_types=1);

namespace App\Service\ContractTransparency;

use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ContractActivityReadService
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.contracts_connection')]
        private readonly Connection $connection,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
        private readonly ContractTransparencyCursor $cursorCodec,
    ) {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function read(
        string $contractId,
        ?string $networkInput,
        ?int $ledgerStart,
        ?int $ledgerEnd,
        ?string $cursor,
        int $limit,
    ): ?array {
        $normalizedContractId = strtoupper(trim($contractId));
        if ($normalizedContractId === '') {
            return null;
        }

        $network = $this->stellarNetworkResolver->normalizeNetwork($networkInput, 'mainnet');
        $networkCode = $this->stellarNetworkResolver->resolveNetworkCode($network);
        if ($networkCode === null) {
            return null;
        }

        if ($ledgerStart !== null && $ledgerEnd !== null && $ledgerStart > $ledgerEnd) {
            [$ledgerStart, $ledgerEnd] = [$ledgerEnd, $ledgerStart];
        }

        $contract = $this->loadContract($normalizedContractId, $networkCode);
        if ($contract === null) {
            return null;
        }

        $limit = max(1, min(200, $limit));
        $activityCursor = $this->cursorCodec->decodeActivity($cursor);
        $rows = $this->loadActivityRows(
            (int) $contract['id'],
            $ledgerStart,
            $ledgerEnd,
            $activityCursor,
            $limit + 1,
        );

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->formatActivityRow($normalizedContractId, $row);
        }

        $lastRow = $rows !== [] ? $rows[array_key_last($rows)] : null;
        $nextCursor = $hasMore && is_array($lastRow)
            ? $this->cursorCodec->encodeActivity([
                'ledger' => (int) ($lastRow['sort_ledger'] ?? 0),
                'rank' => (int) ($lastRow['sort_rank'] ?? 0),
                'id' => (int) ($lastRow['row_id'] ?? 0),
            ])
            : null;

        return [
            '@context' => '/v1/contexts/ContractActivity',
            '@id' => sprintf('/v1/contracts/%s/activity', $normalizedContractId),
            '@type' => 'ContractActivityCollection',
            'contractId' => $normalizedContractId,
            'network' => $network,
            'verification' => $this->formatVerification($contract),
            'activity' => $items,
            'meta' => [
                'limit' => $limit,
                'cursor' => $cursor !== null && trim($cursor) !== '' ? trim($cursor) : null,
                'nextCursor' => $nextCursor,
                'hasMore' => $hasMore,
                'ledgerStart' => $ledgerStart,
                'ledgerEnd' => $ledgerEnd,
            ],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadContract(string $contractId, int $networkCode): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT
                c.id,
                c.contract_id,
                c.wasm_id,
                c.source_code_verified,
                c.sep55_verified,
                c.github_address,
                c.sep55_commit_hash,
                c.sep55_attestation_url,
                c.sep55_error,
                c.sep55_last_checked_at,
                cs.source_code_sha256,
                cs.wasm_blob_sha256
             FROM contracts c
             LEFT JOIN contract_sources cs ON cs.wasm_id = c.wasm_id
             WHERE c.contract_id = :contract_id
               AND c.network = :network
               AND '.ContractVisibilitySql::confirmedPredicate('c').'
             LIMIT 1',
            [
                'contract_id' => $contractId,
                'network' => $networkCode,
            ],
            [
                'network' => ParameterType::INTEGER,
            ],
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @param array{ledger:int,rank:int,id:int}|null $cursor
     * @return list<array<string,mixed>>
     */
    private function loadActivityRows(
        int $contractDbId,
        ?int $ledgerStart,
        ?int $ledgerEnd,
        ?array $cursor,
        int $limit,
    ): array {
        $params = [
            'contract_id' => $contractDbId,
            'limit_rows' => $limit,
        ];
        $types = [
            'contract_id' => ParameterType::INTEGER,
            'limit_rows' => ParameterType::INTEGER,
        ];

        $txLedgerSql = '';
        $eventLedgerSql = '';
        $storageLedgerSql = '';
        if ($ledgerStart !== null) {
            $txLedgerSql .= ' AND ct.ledger >= :ledger_start';
            $eventLedgerSql .= ' AND ce.ledger >= :ledger_start';
            $storageLedgerSql .= ' AND cse.last_modified_ledger_seq >= :ledger_start';
            $params['ledger_start'] = $ledgerStart;
            $types['ledger_start'] = ParameterType::INTEGER;
        }
        if ($ledgerEnd !== null) {
            $txLedgerSql .= ' AND ct.ledger <= :ledger_end';
            $eventLedgerSql .= ' AND ce.ledger <= :ledger_end';
            $storageLedgerSql .= ' AND cse.last_modified_ledger_seq <= :ledger_end';
            $params['ledger_end'] = $ledgerEnd;
            $types['ledger_end'] = ParameterType::INTEGER;
        }

        $cursorSql = '';
        if ($cursor !== null) {
            $cursorSql = ' WHERE (
                activity.sort_ledger < :cursor_ledger
                OR (activity.sort_ledger = :cursor_ledger AND activity.sort_rank < :cursor_rank)
                OR (activity.sort_ledger = :cursor_ledger AND activity.sort_rank = :cursor_rank AND activity.row_id < :cursor_id)
            )';
            $params['cursor_ledger'] = $cursor['ledger'];
            $params['cursor_rank'] = $cursor['rank'];
            $params['cursor_id'] = $cursor['id'];
            $types['cursor_ledger'] = ParameterType::INTEGER;
            $types['cursor_rank'] = ParameterType::INTEGER;
            $types['cursor_id'] = ParameterType::INTEGER;
        }

        return $this->connection->fetchAllAssociative(
            'SELECT *
             FROM (
                SELECT
                    \'transaction\' AS item_type,
                    3 AS sort_rank,
                    ct.id AS row_id,
                    COALESCE(ct.ledger, 0) AS sort_ledger,
                    ct.ledger,
                    ct.created_at AS occurred_at,
                    ct.tx_hash,
                    NULL AS event_idx,
                    NULL AS event_type,
                    NULL AS topic_decoded,
                    NULL AS value_decoded,
                    NULL AS addresses,
                    NULL AS amount_raw,
                    ct.source_account,
                    ct.host_functions,
                    ct.fee_charged,
                    ct.max_fee,
                    ct.total_operations,
                    NULL AS storage_key,
                    NULL AS entry_xdr,
                    NULL AS entry_decoded,
                    NULL AS last_modified_ledger_seq,
                    NULL AS live_until_ledger_seq,
                    (
                        SELECT COUNT(*)
                        FROM contract_events ce2
                        WHERE ce2.contract_id = ct.contract_id
                          AND ce2.tx_hash = ct.tx_hash
                    ) AS linked_events_count
                FROM contract_transactions ct
                WHERE ct.contract_id = :contract_id' . $txLedgerSql . '
                UNION ALL
                SELECT
                    \'event\' AS item_type,
                    2 AS sort_rank,
                    ce.id AS row_id,
                    COALESCE(ce.ledger, 0) AS sort_ledger,
                    ce.ledger,
                    COALESCE(ce.ledger_closed_at, ce.created_at) AS occurred_at,
                    ce.tx_hash,
                    ce.event_idx,
                    ce.event_type,
                    ce.topic_decoded,
                    ce.value_decoded,
                    ce.addresses,
                    ce.amount_raw,
                    NULL AS source_account,
                    NULL AS host_functions,
                    NULL AS fee_charged,
                    NULL AS max_fee,
                    NULL AS total_operations,
                    NULL AS storage_key,
                    NULL AS entry_xdr,
                    NULL AS entry_decoded,
                    NULL AS last_modified_ledger_seq,
                    NULL AS live_until_ledger_seq,
                    NULL AS linked_events_count
                FROM contract_events ce
                WHERE ce.contract_id = :contract_id' . $eventLedgerSql . '
                UNION ALL
                SELECT
                    \'storage_delta\' AS item_type,
                    1 AS sort_rank,
                    cse.id AS row_id,
                    COALESCE(cse.last_modified_ledger_seq, 0) AS sort_ledger,
                    cse.last_modified_ledger_seq AS ledger,
                    cse.updated_at AS occurred_at,
                    NULL AS tx_hash,
                    NULL AS event_idx,
                    NULL AS event_type,
                    NULL AS topic_decoded,
                    NULL AS value_decoded,
                    NULL AS addresses,
                    NULL AS amount_raw,
                    NULL AS source_account,
                    NULL AS host_functions,
                    NULL AS fee_charged,
                    NULL AS max_fee,
                    NULL AS total_operations,
                    cse.storage_key,
                    cse.entry_xdr,
                    cse.entry_decoded,
                    cse.last_modified_ledger_seq,
                    cse.live_until_ledger_seq,
                    NULL AS linked_events_count
                FROM contract_storage_entries cse
                WHERE cse.contract_id = :contract_id' . $storageLedgerSql . '
             ) activity' . $cursorSql . '
             ORDER BY activity.sort_ledger DESC, activity.sort_rank DESC, activity.row_id DESC
             LIMIT :limit_rows',
            $params,
            $types,
        );
    }

    /**
     * @param array<string,mixed> $contract
     * @return array<string,mixed>
     */
    private function formatVerification(array $contract): array
    {
        $sep55Verified = $this->databaseBool($contract['sep55_verified'] ?? false);
        $sourceCodeVerified = $this->databaseBool($contract['source_code_verified'] ?? false);
        $sep55Error = $this->nullableString($contract['sep55_error'] ?? null);
        $status = match (true) {
            $sep55Verified => 'verified',
            $sourceCodeVerified => 'source_available',
            $sep55Error !== null => 'failed',
            $this->nullableString($contract['wasm_id'] ?? null) !== null => 'unverified',
            default => 'unknown',
        };

        return [
            'wasmHash' => $this->nullableString($contract['wasm_id'] ?? null) ?? $this->nullableString($contract['wasm_blob_sha256'] ?? null),
            'source' => [
                'type' => $sep55Verified ? 'sep55' : ($sourceCodeVerified ? 'decompiled' : null),
                'githubAddress' => $this->nullableString($contract['github_address'] ?? null),
                'commitHash' => $this->nullableString($contract['sep55_commit_hash'] ?? null),
                'attestationUrl' => $this->nullableString($contract['sep55_attestation_url'] ?? null),
                'sourceCodeSha256' => $this->nullableString($contract['source_code_sha256'] ?? null),
                'lastCheckedAt' => $this->toAtom($contract['sep55_last_checked_at'] ?? null),
                'error' => $sep55Error,
            ],
            'verificationStatus' => $status,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function formatActivityRow(string $contractId, array $row): array
    {
        $type = (string) ($row['item_type'] ?? 'unknown');
        $rowId = (int) ($row['row_id'] ?? 0);
        $ledger = $this->nullableInt($row['ledger'] ?? null);
        $txHash = $this->nullableString($row['tx_hash'] ?? null);
        $cursor = $this->cursorCodec->encodeActivity([
            'ledger' => (int) ($row['sort_ledger'] ?? 0),
            'rank' => (int) ($row['sort_rank'] ?? 0),
            'id' => $rowId,
        ]);

        $base = [
            'id' => sprintf('%s:%d', $type, $rowId),
            'type' => $type,
            'cursor' => $cursor,
            'ledger' => $ledger,
            'occurredAt' => $this->toAtom($row['occurred_at'] ?? null),
            'txHash' => $txHash,
        ];

        if ($type === 'transaction') {
            $hostFunctions = $this->decodeJsonValue($row['host_functions'] ?? null);
            $effectsCount = is_array($hostFunctions) && isset($hostFunctions['effectsCount'])
                ? (int) $hostFunctions['effectsCount']
                : null;

            return $base + [
                'transaction' => [
                    'sourceAccount' => $this->nullableString($row['source_account'] ?? null),
                    'feeCharged' => $this->nullableInt($row['fee_charged'] ?? null),
                    'maxFee' => $this->nullableInt($row['max_fee'] ?? null),
                    'totalOperations' => $this->nullableInt($row['total_operations'] ?? null),
                    'hostFunctions' => $hostFunctions,
                ],
                'effects' => [
                    'count' => $effectsCount,
                ],
                'links' => [
                    'transaction' => $txHash !== null ? sprintf('/v1/transactions/%s', $txHash) : null,
                    'events' => $txHash !== null ? sprintf('/v1/contracts/%s/events?txHash=%s', $contractId, rawurlencode($txHash)) : null,
                ],
                'linked' => [
                    'eventsCount' => $this->nullableInt($row['linked_events_count'] ?? null) ?? 0,
                ],
            ];
        }

        if ($type === 'event') {
            return $base + [
                'event' => [
                    'eventIndex' => $this->nullableInt($row['event_idx'] ?? null),
                    'eventType' => (string) ($row['event_type'] ?? 'unknown'),
                    'topicDecoded' => $this->decodeJsonValue($row['topic_decoded'] ?? null),
                    'valueDecoded' => $this->decodeJsonValue($row['value_decoded'] ?? null),
                    'addresses' => $this->decodeJsonValue($row['addresses'] ?? null),
                    'amountRaw' => $this->nullableString($row['amount_raw'] ?? null),
                ],
                'links' => [
                    'event' => sprintf('/v1/contracts/%s/events/%d', $contractId, $rowId),
                    'transaction' => $txHash !== null ? sprintf('/v1/transactions/%s', $txHash) : null,
                ],
            ];
        }

        return $base + [
            'storageDelta' => [
                'storageKey' => $this->nullableString($row['storage_key'] ?? null),
                'entryDecoded' => $this->decodeJsonValue($row['entry_decoded'] ?? null),
                'entryXdr' => $this->nullableString($row['entry_xdr'] ?? null),
                'lastModifiedLedgerSeq' => $this->nullableInt($row['last_modified_ledger_seq'] ?? null),
                'liveUntilLedgerSeq' => $this->nullableInt($row['live_until_ledger_seq'] ?? null),
            ],
            'links' => [
                'storage' => sprintf('/v1/contracts/%s/storage', $contractId),
            ],
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function databaseBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                '1', 't', 'true', 'yes', 'on' => true,
                default => false,
            };
        }

        return false;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?[0-9]+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
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
