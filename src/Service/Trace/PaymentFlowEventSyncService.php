<?php

declare(strict_types=1);

namespace App\Service\Trace;

use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class PaymentFlowEventSyncService
{
    private const DEFAULT_BATCH_SIZE = 5000;
    private const UPSERT_BATCH_SIZE = 1000;
    private const SELECT_BATCH_SIZE = 5000;

    /** @var array<int,string> */
    private const OPERATION_TYPE_NAMES = [
        0 => 'create_account',
        1 => 'payment',
        2 => 'path_payment_strict_receive',
        8 => 'account_merge',
        13 => 'path_payment_strict_send',
    ];

    /** @var array<int,array<string,int>> */
    private array $addressIdCache = [];

    /** @var array<int,array<string,int>> */
    private array $assetIdCache = [];

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.statistics_connection')]
        private readonly Connection $statisticsConnection,
        private readonly ManagerRegistry $doctrine,
        private readonly StellarNetworkResolver $networkResolver,
    ) {
    }

    /**
     * @return array{
     *   network:string,
     *   start_ledger:int,
     *   end_ledger:int,
     *   batch_size:int,
     *   operations_scanned:int,
     *   events_written:int,
     *   rows_written:int,
     *   dry_run:bool
     * }
     */
    public function sync(
        string $network,
        int $startLedger,
        int $endLedger,
        int $batchSize = self::DEFAULT_BATCH_SIZE,
        bool $dryRun = false
    ): array {
        if ($batchSize < 1) {
            throw new \InvalidArgumentException('Batch size must be >= 1.');
        }
        if ($startLedger < 1 || $endLedger < 1) {
            throw new \InvalidArgumentException('Start and end ledger must be positive integers.');
        }
        if ($startLedger > $endLedger) {
            [$startLedger, $endLedger] = [$endLedger, $startLedger];
        }

        $normalizedNetwork = $this->networkResolver->normalizeNetwork($network, 'testnet');
        $networkCode = $this->networkResolver->resolveNetworkCode($normalizedNetwork) ?? 1;
        $horizonConnection = $this->resolveHorizonConnection($normalizedNetwork);

        $ledgerColumns = $this->loadTableColumns($horizonConnection, 'history_ledgers');
        $transactionColumns = $this->loadTableColumns($horizonConnection, 'history_transactions');
        $operationColumns = $this->loadTableColumns($horizonConnection, 'history_operations');
        $this->assertRequiredColumns($ledgerColumns, $transactionColumns, $operationColumns);

        $ledgerColumn = $this->resolveTransactionLedgerColumn($transactionColumns);
        $txHashColumn = isset($transactionColumns['transaction_hash']) ? 'transaction_hash' : 'hash';

        $operationsScanned = 0;
        $eventsWritten = 0;
        $rowsWritten = 0;
        $afterId = 0;

        while (true) {
            $rows = $this->loadFlowRows(
                $horizonConnection,
                $startLedger,
                $endLedger,
                $batchSize,
                $afterId,
                $ledgerColumn,
                $txHashColumn,
                $transactionColumns,
                $operationColumns
            );

            if ($rows === []) {
                break;
            }

            $events = [];
            foreach ($rows as $row) {
                $operationId = $this->toInt($row['operation_id'] ?? null);
                if ($operationId !== null) {
                    $afterId = max($afterId, $operationId);
                }

                $operationsScanned++;
                $event = $this->normalizeEventRow($row, $networkCode);
                if ($event === null) {
                    continue;
                }

                $events[] = $event;
            }

            $eventsWritten += count($events);
            if (!$dryRun && $events !== []) {
                $rowsWritten += $this->upsertEvents($events);
            }
        }

        return [
            'network' => $normalizedNetwork,
            'start_ledger' => $startLedger,
            'end_ledger' => $endLedger,
            'batch_size' => $batchSize,
            'operations_scanned' => $operationsScanned,
            'events_written' => $eventsWritten,
            'rows_written' => $dryRun ? 0 : $rowsWritten,
            'dry_run' => $dryRun,
        ];
    }

    /**
     * @param array<string,bool> $ledgerColumns
     * @param array<string,bool> $transactionColumns
     * @param array<string,bool> $operationColumns
     */
    private function assertRequiredColumns(array $ledgerColumns, array $transactionColumns, array $operationColumns): void
    {
        foreach (['sequence', 'closed_at'] as $column) {
            if (!isset($ledgerColumns[$column])) {
                throw new \RuntimeException(sprintf('history_ledgers is missing required column: %s', $column));
            }
        }

        foreach (['id'] as $column) {
            if (!isset($transactionColumns[$column])) {
                throw new \RuntimeException(sprintf('history_transactions is missing required column: %s', $column));
            }
        }
        if (!isset($transactionColumns['transaction_hash']) && !isset($transactionColumns['hash'])) {
            throw new \RuntimeException('history_transactions is missing transaction_hash/hash.');
        }

        foreach (['id', 'type', 'transaction_id', 'details'] as $column) {
            if (!isset($operationColumns[$column])) {
                throw new \RuntimeException(sprintf('history_operations is missing required column: %s', $column));
            }
        }
    }

    /**
     * @param array<string,bool> $transactionColumns
     */
    private function resolveTransactionLedgerColumn(array $transactionColumns): string
    {
        if (isset($transactionColumns['ledger_sequence'])) {
            return 'ledger_sequence';
        }
        if (isset($transactionColumns['ledger_seq'])) {
            return 'ledger_seq';
        }

        throw new \RuntimeException('history_transactions does not expose ledger_sequence or ledger_seq.');
    }

    /**
     * @return array<string,bool>
     */
    private function loadTableColumns(Connection $connection, string $table): array
    {
        $rows = $connection->fetchAllAssociative(
            'SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = :table',
            ['table' => $table]
        );

        $columns = [];
        foreach ($rows as $row) {
            $column = strtolower(trim((string) ($row['column_name'] ?? '')));
            if ($column !== '') {
                $columns[$column] = true;
            }
        }

        return $columns;
    }

    /**
     * @param array<string,bool> $transactionColumns
     * @param array<string,bool> $operationColumns
     * @return list<array<string,mixed>>
     */
    private function loadFlowRows(
        Connection $connection,
        int $startLedger,
        int $endLedger,
        int $batchSize,
        int $afterId,
        string $ledgerColumn,
        string $txHashColumn,
        array $transactionColumns,
        array $operationColumns
    ): array {
        $operationIndexExpr = isset($operationColumns['application_order']) ? 'ho.application_order' : 'NULL';
        $operationSourceExpr = isset($operationColumns['source_account']) ? 'ho.source_account' : 'NULL::text';
        $txAccountExpr = isset($transactionColumns['account']) ? 'ht.account' : 'NULL::text';
        $successfulExpr = isset($transactionColumns['successful']) ? 'COALESCE(ht.successful, TRUE)' : 'TRUE';
        $successfulPredicate = isset($transactionColumns['successful']) ? 'AND COALESCE(ht.successful, TRUE) = TRUE' : '';
        $memoTypeExpr = isset($transactionColumns['memo_type']) ? 'ht.memo_type' : 'NULL::text';
        $memoExpr = isset($transactionColumns['memo']) ? 'ht.memo' : 'NULL::text';

        $sql = <<<SQL
SELECT
    ho.id AS operation_id,
    {$operationIndexExpr} AS operation_index,
    ho.type AS operation_type_id,
    {$operationSourceExpr} AS operation_source_account,
    ht.{$txHashColumn} AS tx_hash,
    ht.{$ledgerColumn} AS ledger,
    {$txAccountExpr} AS tx_source_account,
    {$successfulExpr} AS successful,
    {$memoTypeExpr} AS memo_type,
    {$memoExpr} AS memo,
    hl.closed_at,
    CASE
        WHEN ho.type = 0 THEN COALESCE(NULLIF(ho.details->>'funder', ''), NULLIF({$operationSourceExpr}, ''), NULLIF({$txAccountExpr}, ''))
        WHEN ho.type = 8 THEN COALESCE(NULLIF(ho.details->>'account', ''), NULLIF({$operationSourceExpr}, ''), NULLIF({$txAccountExpr}, ''))
        ELSE COALESCE(NULLIF(ho.details->>'from', ''), NULLIF({$operationSourceExpr}, ''), NULLIF({$txAccountExpr}, ''))
    END AS from_address,
    CASE
        WHEN ho.type = 0 THEN NULLIF(ho.details->>'account', '')
        WHEN ho.type = 8 THEN NULLIF(ho.details->>'into', '')
        ELSE NULLIF(ho.details->>'to', '')
    END AS to_address,
    CASE
        WHEN ho.type = 0 THEN COALESCE(NULLIF(ho.details->>'starting_balance', ''), NULLIF(ho.details->>'amount', ''))
        WHEN ho.type = 8 THEN NULL
        WHEN ho.type = 1 THEN NULLIF(ho.details->>'amount', '')
        ELSE NULLIF(ho.details->>'source_amount', '')
    END AS source_amount_decimal,
    CASE
        WHEN ho.type IN (0, 8) THEN 'native'
        WHEN ho.type = 1 THEN COALESCE(NULLIF(ho.details->>'asset_type', ''), 'native')
        ELSE COALESCE(NULLIF(ho.details->>'source_asset_type', ''), 'native')
    END AS source_asset_type,
    CASE
        WHEN ho.type IN (0, 8) THEN NULL
        WHEN ho.type = 1 THEN NULLIF(ho.details->>'asset_code', '')
        ELSE NULLIF(ho.details->>'source_asset_code', '')
    END AS source_asset_code,
    CASE
        WHEN ho.type IN (0, 8) THEN NULL
        WHEN ho.type = 1 THEN NULLIF(ho.details->>'asset_issuer', '')
        ELSE NULLIF(ho.details->>'source_asset_issuer', '')
    END AS source_asset_issuer,
    CASE
        WHEN ho.type = 0 THEN COALESCE(NULLIF(ho.details->>'starting_balance', ''), NULLIF(ho.details->>'amount', ''))
        WHEN ho.type = 8 THEN NULL
        ELSE NULLIF(ho.details->>'amount', '')
    END AS destination_amount_decimal,
    CASE
        WHEN ho.type IN (0, 8) THEN 'native'
        ELSE COALESCE(NULLIF(ho.details->>'asset_type', ''), 'native')
    END AS destination_asset_type,
    CASE
        WHEN ho.type IN (0, 8) THEN NULL
        ELSE NULLIF(ho.details->>'asset_code', '')
    END AS destination_asset_code,
    CASE
        WHEN ho.type IN (0, 8) THEN NULL
        ELSE NULLIF(ho.details->>'asset_issuer', '')
    END AS destination_asset_issuer
FROM history_operations ho
INNER JOIN history_transactions ht ON ht.id = ho.transaction_id
INNER JOIN history_ledgers hl ON hl.sequence = ht.{$ledgerColumn}
WHERE ht.{$ledgerColumn} BETWEEN :start_ledger AND :end_ledger
  AND ho.id > :after_id
  AND ho.type IN (0, 1, 2, 8, 13)
  {$successfulPredicate}
ORDER BY ho.id ASC
LIMIT :batch_size
SQL;

        return $connection->fetchAllAssociative(
            $sql,
            [
                'start_ledger' => $startLedger,
                'end_ledger' => $endLedger,
                'after_id' => $afterId,
                'batch_size' => $batchSize,
            ],
            [
                'start_ledger' => ParameterType::INTEGER,
                'end_ledger' => ParameterType::INTEGER,
                'after_id' => ParameterType::INTEGER,
                'batch_size' => ParameterType::INTEGER,
            ]
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function normalizeEventRow(array $row, int $networkCode): ?array
    {
        $operationId = $this->toInt($row['operation_id'] ?? null);
        $operationTypeId = $this->toInt($row['operation_type_id'] ?? null);
        $ledger = $this->toInt($row['ledger'] ?? null);
        $closedAt = $this->formatUtcDateTime($row['closed_at'] ?? null);
        $txHash = $this->normalizeString($row['tx_hash'] ?? null, 64);

        if ($operationId === null || $operationTypeId === null || $ledger === null || $closedAt === null || $txHash === '') {
            return null;
        }

        $fromAddress = $this->normalizeString($row['from_address'] ?? null, 64);
        $toAddress = $this->normalizeString($row['to_address'] ?? null, 64);
        if ($fromAddress === '' && $toAddress === '') {
            return null;
        }

        $sourceAccount = $this->normalizeString($row['operation_source_account'] ?? null, 64);
        if ($sourceAccount === '') {
            $sourceAccount = $this->normalizeString($row['tx_source_account'] ?? null, 64);
        }
        $transactionSourceAccount = $this->normalizeString($row['tx_source_account'] ?? null, 64);

        [$sourceAssetType, $sourceAssetCode, $sourceAssetIssuer] = $this->normalizeAssetComponents(
            $this->normalizeString($row['source_asset_type'] ?? null, 32) ?: 'native',
            $this->normalizeString($row['source_asset_code'] ?? null, 32) ?: null,
            $this->normalizeString($row['source_asset_issuer'] ?? null, 64) ?: null
        );
        [$destinationAssetType, $destinationAssetCode, $destinationAssetIssuer] = $this->normalizeAssetComponents(
            $this->normalizeString($row['destination_asset_type'] ?? null, 32) ?: 'native',
            $this->normalizeString($row['destination_asset_code'] ?? null, 32) ?: null,
            $this->normalizeString($row['destination_asset_issuer'] ?? null, 64) ?: null
        );

        return [
            'network' => $networkCode,
            'ledger' => $ledger,
            'closed_at' => $closedAt,
            'tx_hash' => $txHash,
            'transaction_source_account' => $transactionSourceAccount ?: null,
            'operation_id' => $operationId,
            'operation_index' => $this->toInt($row['operation_index'] ?? null),
            'operation_type' => self::OPERATION_TYPE_NAMES[$operationTypeId] ?? sprintf('operation_%d', $operationTypeId),
            'successful' => $this->toBool($row['successful'] ?? true),
            'source_account' => $sourceAccount ?: null,
            'from_address' => $fromAddress ?: null,
            'to_address' => $toAddress ?: null,
            'source_asset_type' => $sourceAssetType,
            'source_asset_code' => $sourceAssetCode,
            'source_asset_issuer' => $sourceAssetIssuer,
            'source_amount_decimal' => $this->normalizeDecimalString($row['source_amount_decimal'] ?? null),
            'destination_asset_type' => $destinationAssetType,
            'destination_asset_code' => $destinationAssetCode,
            'destination_asset_issuer' => $destinationAssetIssuer,
            'destination_amount_decimal' => $this->normalizeDecimalString($row['destination_amount_decimal'] ?? null),
            'memo_type' => $this->normalizeString($row['memo_type'] ?? null, 32) ?: null,
            'memo' => $this->normalizeString($row['memo'] ?? null, 255) ?: null,
        ];
    }

    /**
     * @param list<array<string,mixed>> $events
     */
    private function upsertEvents(array $events): int
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $nowString = $now->format('Y-m-d H:i:s');
        $network = (int) $events[0]['network'];

        $this->statisticsConnection->beginTransaction();
        try {
            $addressIds = $this->ensureAddressIds($network, $events, $nowString);
            $assetIds = $this->ensureAssetIds($network, $events, $nowString);
            $transactionIds = $this->ensureTransactionIds($network, $events, $addressIds, $nowString);

            $rowsWritten = $this->bulkUpsertPaymentEvents(
                $network,
                $events,
                $addressIds,
                $assetIds,
                $transactionIds,
                $nowString
            );

            $this->statisticsConnection->commit();
        } catch (\Throwable $exception) {
            $this->statisticsConnection->rollBack();
            throw $exception;
        }

        return $rowsWritten;
    }

    /**
     * @param list<array<string,mixed>> $events
     * @return array<string,int>
     */
    private function ensureAddressIds(int $network, array $events, string $nowString): array
    {
        $addresses = [];
        foreach ($events as $event) {
            foreach (['transaction_source_account', 'source_account', 'from_address', 'to_address'] as $field) {
                $address = $this->normalizeString($event[$field] ?? null, 64);
                if ($address !== '') {
                    $addresses[$address] = true;
                }
            }
        }

        if ($addresses === []) {
            return $this->addressIdCache[$network] ?? [];
        }

        $this->addressIdCache[$network] ??= [];
        $missing = array_values(array_diff(array_keys($addresses), array_keys($this->addressIdCache[$network])));

        if ($missing !== []) {
            $this->bulkUpsertAddresses($network, $missing, $nowString);
            $this->loadAddressIds($network, $missing);
        }

        return $this->addressIdCache[$network];
    }

    /**
     * @param list<array<string,mixed>> $events
     * @return array<string,int>
     */
    private function ensureAssetIds(int $network, array $events, string $nowString): array
    {
        $assets = [];
        foreach ($events as $event) {
            foreach (['source', 'destination'] as $prefix) {
                $key = $this->buildAssetKey(
                    (string) $event[$prefix . '_asset_type'],
                    (string) $event[$prefix . '_asset_code'],
                    (string) $event[$prefix . '_asset_issuer']
                );
                $assets[$key] = true;
            }
        }

        $this->assetIdCache[$network] ??= [];
        $missing = array_values(array_diff(array_keys($assets), array_keys($this->assetIdCache[$network])));

        if ($missing !== []) {
            $this->bulkUpsertAssets($network, $missing, $nowString);
            $this->loadAllAssetIds($network);
        }

        return $this->assetIdCache[$network];
    }

    /**
     * @param list<array<string,mixed>> $events
     * @param array<string,int> $addressIds
     * @return array<string,int>
     */
    private function ensureTransactionIds(int $network, array $events, array $addressIds, string $nowString): array
    {
        $transactions = [];
        foreach ($events as $event) {
            $txHash = (string) $event['tx_hash'];
            if (isset($transactions[$txHash])) {
                continue;
            }

            $transactions[$txHash] = [
                'ledger' => $event['ledger'],
                'closed_at' => $event['closed_at'],
                'source_account_id' => $this->resolveAddressId($addressIds, $event['transaction_source_account'] ?? null),
                'memo_type' => $event['memo_type'],
                'memo' => $event['memo'],
            ];
        }

        if ($transactions === []) {
            return [];
        }

        $this->bulkUpsertTransactions($network, $transactions, $nowString);

        $ids = [];
        foreach (array_chunk(array_keys($transactions), self::SELECT_BATCH_SIZE) as $txHashBatch) {
            $rows = $this->statisticsConnection->fetchAllAssociative(
                'SELECT id, tx_hash FROM payment_flow_transaction WHERE network = :network AND tx_hash IN (:tx_hashes)',
                [
                    'network' => $network,
                    'tx_hashes' => $txHashBatch,
                ],
                [
                    'network' => ParameterType::INTEGER,
                    'tx_hashes' => ArrayParameterType::STRING,
                ]
            );

            foreach ($rows as $row) {
                $txHash = (string) ($row['tx_hash'] ?? '');
                $id = $this->toInt($row['id'] ?? null);
                if ($txHash !== '' && $id !== null) {
                    $ids[$txHash] = $id;
                }
            }
        }

        return $ids;
    }

    /**
     * @param list<string> $addresses
     */
    private function bulkUpsertAddresses(int $network, array $addresses, string $nowString): void
    {
        foreach (array_chunk($addresses, self::UPSERT_BATCH_SIZE) as $addressBatch) {
            $rows = [];
            foreach ($addressBatch as $address) {
                $rows[] = [
                    'network' => $network,
                    'address' => $address,
                    'created_at' => $nowString,
                ];
            }

            [$valuesSql, $params, $types] = $this->buildBulkValues(
                $rows,
                ['network', 'address', 'created_at'],
                ['network']
            );

            $sql = <<<SQL
INSERT INTO payment_flow_address (network, address, created_at)
VALUES {$valuesSql}
SQL;

            $platform = $this->statisticsConnection->getDatabasePlatform();
            if ($platform instanceof AbstractMySQLPlatform) {
                $sql .= "\nON DUPLICATE KEY UPDATE address = VALUES(address)";
            } elseif ($platform instanceof PostgreSQLPlatform) {
                $sql .= "\nON CONFLICT (network, address) DO NOTHING";
            } else {
                throw new \RuntimeException(sprintf('Unsupported statistics database platform: %s', $platform::class));
            }

            $this->statisticsConnection->executeStatement($sql, $params, $types);
        }
    }

    /**
     * @param list<string> $addresses
     */
    private function loadAddressIds(int $network, array $addresses): void
    {
        foreach (array_chunk($addresses, self::SELECT_BATCH_SIZE) as $addressBatch) {
            $rows = $this->statisticsConnection->fetchAllAssociative(
                'SELECT id, address FROM payment_flow_address WHERE network = :network AND address IN (:addresses)',
                [
                    'network' => $network,
                    'addresses' => $addressBatch,
                ],
                [
                    'network' => ParameterType::INTEGER,
                    'addresses' => ArrayParameterType::STRING,
                ]
            );

            foreach ($rows as $row) {
                $address = (string) ($row['address'] ?? '');
                $id = $this->toInt($row['id'] ?? null);
                if ($address !== '' && $id !== null) {
                    $this->addressIdCache[$network][$address] = $id;
                }
            }
        }
    }

    /**
     * @param list<string> $assetKeys
     */
    private function bulkUpsertAssets(int $network, array $assetKeys, string $nowString): void
    {
        foreach (array_chunk($assetKeys, self::UPSERT_BATCH_SIZE) as $assetKeyBatch) {
            $rows = [];
            foreach ($assetKeyBatch as $key) {
                [$assetType, $assetCode, $assetIssuer] = $this->parseAssetKey($key);
                $rows[] = [
                    'network' => $network,
                    'asset_type' => $assetType,
                    'asset_code' => $assetCode,
                    'asset_issuer' => $assetIssuer,
                    'created_at' => $nowString,
                ];
            }

            [$valuesSql, $params, $types] = $this->buildBulkValues(
                $rows,
                ['network', 'asset_type', 'asset_code', 'asset_issuer', 'created_at'],
                ['network']
            );

            $sql = <<<SQL
INSERT INTO payment_flow_asset (network, asset_type, asset_code, asset_issuer, created_at)
VALUES {$valuesSql}
SQL;

            $platform = $this->statisticsConnection->getDatabasePlatform();
            if ($platform instanceof AbstractMySQLPlatform) {
                $sql .= "\nON DUPLICATE KEY UPDATE asset_type = VALUES(asset_type)";
            } elseif ($platform instanceof PostgreSQLPlatform) {
                $sql .= "\nON CONFLICT (network, asset_type, asset_code, asset_issuer) DO NOTHING";
            } else {
                throw new \RuntimeException(sprintf('Unsupported statistics database platform: %s', $platform::class));
            }

            $this->statisticsConnection->executeStatement($sql, $params, $types);
        }
    }

    private function loadAllAssetIds(int $network): void
    {
        $rows = $this->statisticsConnection->fetchAllAssociative(
            'SELECT id, asset_type, asset_code, asset_issuer FROM payment_flow_asset WHERE network = :network',
            ['network' => $network],
            ['network' => ParameterType::INTEGER]
        );

        foreach ($rows as $row) {
            $assetType = (string) ($row['asset_type'] ?? 'native');
            $assetCode = (string) ($row['asset_code'] ?? '');
            $assetIssuer = (string) ($row['asset_issuer'] ?? '');
            $id = $this->toInt($row['id'] ?? null);
            if ($id !== null) {
                $this->assetIdCache[$network][$this->buildAssetKey($assetType, $assetCode, $assetIssuer)] = $id;
            }
        }
    }

    /**
     * @param array<string,array{ledger:mixed,closed_at:mixed,source_account_id:?int,memo_type:mixed,memo:mixed}> $transactions
     */
    private function bulkUpsertTransactions(int $network, array $transactions, string $nowString): void
    {
        foreach (array_chunk($transactions, self::UPSERT_BATCH_SIZE, true) as $transactionBatch) {
            $rows = [];
            foreach ($transactionBatch as $txHash => $transaction) {
                $rows[] = [
                    'network' => $network,
                    'ledger' => $transaction['ledger'],
                    'closed_at' => $transaction['closed_at'],
                    'tx_hash' => $txHash,
                    'source_account_id' => $transaction['source_account_id'],
                    'memo_type' => $transaction['memo_type'],
                    'memo' => $transaction['memo'],
                    'created_at' => $nowString,
                    'updated_at' => $nowString,
                ];
            }

            [$valuesSql, $params, $types] = $this->buildBulkValues(
                $rows,
                ['network', 'ledger', 'closed_at', 'tx_hash', 'source_account_id', 'memo_type', 'memo', 'created_at', 'updated_at'],
                ['network', 'ledger', 'source_account_id']
            );

            $sql = <<<SQL
INSERT INTO payment_flow_transaction
    (network, ledger, closed_at, tx_hash, source_account_id, memo_type, memo, created_at, updated_at)
VALUES {$valuesSql}
SQL;

            $platform = $this->statisticsConnection->getDatabasePlatform();
            if ($platform instanceof AbstractMySQLPlatform) {
                $sql .= <<<SQL

ON DUPLICATE KEY UPDATE
    ledger = VALUES(ledger),
    closed_at = VALUES(closed_at),
    source_account_id = VALUES(source_account_id),
    memo_type = VALUES(memo_type),
    memo = VALUES(memo),
    updated_at = VALUES(updated_at)
SQL;
            } elseif ($platform instanceof PostgreSQLPlatform) {
                $sql .= <<<SQL

ON CONFLICT (network, tx_hash) DO UPDATE SET
    ledger = EXCLUDED.ledger,
    closed_at = EXCLUDED.closed_at,
    source_account_id = EXCLUDED.source_account_id,
    memo_type = EXCLUDED.memo_type,
    memo = EXCLUDED.memo,
    updated_at = EXCLUDED.updated_at
SQL;
            } else {
                throw new \RuntimeException(sprintf('Unsupported statistics database platform: %s', $platform::class));
            }

            $this->statisticsConnection->executeStatement($sql, $params, $types);
        }
    }

    /**
     * @param list<array<string,mixed>> $events
     * @param array<string,int> $addressIds
     * @param array<string,int> $assetIds
     * @param array<string,int> $transactionIds
     */
    private function bulkUpsertPaymentEvents(
        int $network,
        array $events,
        array $addressIds,
        array $assetIds,
        array $transactionIds,
        string $nowString
    ): int {
        $rows = [];
        $rowsWritten = 0;

        foreach ($events as $event) {
            $txId = $transactionIds[(string) $event['tx_hash']] ?? null;
            if ($txId === null) {
                continue;
            }

            $rows[] = [
                'network' => $network,
                'ledger' => $event['ledger'],
                'tx_id' => $txId,
                'operation_id' => $event['operation_id'],
                'operation_index' => $event['operation_index'],
                'operation_type' => $event['operation_type'],
                'successful' => $event['successful'],
                'source_account_id' => $this->resolveAddressId($addressIds, $event['source_account'] ?? null),
                'from_address_id' => $this->resolveAddressId($addressIds, $event['from_address'] ?? null),
                'to_address_id' => $this->resolveAddressId($addressIds, $event['to_address'] ?? null),
                'source_asset_id' => $this->resolveAssetId($assetIds, $event, 'source'),
                'source_amount_decimal' => $event['source_amount_decimal'],
                'destination_asset_id' => $this->resolveAssetId($assetIds, $event, 'destination'),
                'destination_amount_decimal' => $event['destination_amount_decimal'],
                'created_at' => $nowString,
                'updated_at' => $nowString,
            ];

            if (count($rows) >= self::UPSERT_BATCH_SIZE) {
                $rowsWritten += $this->executePaymentEventBatch($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            $rowsWritten += $this->executePaymentEventBatch($rows);
        }

        return $rowsWritten;
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function executePaymentEventBatch(array $rows): int
    {
        [$valuesSql, $params, $types] = $this->buildBulkValues(
            $rows,
            [
                'network',
                'ledger',
                'tx_id',
                'operation_id',
                'operation_index',
                'operation_type',
                'successful',
                'source_account_id',
                'from_address_id',
                'to_address_id',
                'source_asset_id',
                'source_amount_decimal',
                'destination_asset_id',
                'destination_amount_decimal',
                'created_at',
                'updated_at',
            ],
            [
                'network',
                'ledger',
                'tx_id',
                'operation_id',
                'operation_index',
                'source_account_id',
                'from_address_id',
                'to_address_id',
                'source_asset_id',
                'destination_asset_id',
            ],
            ['successful']
        );

        $sql = <<<SQL
INSERT INTO payment_flow_event
    (network, ledger, tx_id, operation_id, operation_index, operation_type, successful, source_account_id, from_address_id, to_address_id, source_asset_id, source_amount_decimal, destination_asset_id, destination_amount_decimal, created_at, updated_at)
VALUES {$valuesSql}
SQL;

        $platform = $this->statisticsConnection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $sql .= <<<SQL

ON DUPLICATE KEY UPDATE
    ledger = VALUES(ledger),
    tx_id = VALUES(tx_id),
    operation_index = VALUES(operation_index),
    operation_type = VALUES(operation_type),
    successful = VALUES(successful),
    source_account_id = VALUES(source_account_id),
    from_address_id = VALUES(from_address_id),
    to_address_id = VALUES(to_address_id),
    source_asset_id = VALUES(source_asset_id),
    source_amount_decimal = VALUES(source_amount_decimal),
    destination_asset_id = VALUES(destination_asset_id),
    destination_amount_decimal = VALUES(destination_amount_decimal),
    updated_at = VALUES(updated_at)
SQL;
        } elseif ($platform instanceof PostgreSQLPlatform) {
            $sql .= <<<SQL

ON CONFLICT (network, operation_id) DO UPDATE SET
    ledger = EXCLUDED.ledger,
    tx_id = EXCLUDED.tx_id,
    operation_index = EXCLUDED.operation_index,
    operation_type = EXCLUDED.operation_type,
    successful = EXCLUDED.successful,
    source_account_id = EXCLUDED.source_account_id,
    from_address_id = EXCLUDED.from_address_id,
    to_address_id = EXCLUDED.to_address_id,
    source_asset_id = EXCLUDED.source_asset_id,
    source_amount_decimal = EXCLUDED.source_amount_decimal,
    destination_asset_id = EXCLUDED.destination_asset_id,
    destination_amount_decimal = EXCLUDED.destination_amount_decimal,
    updated_at = EXCLUDED.updated_at
SQL;
        } else {
            throw new \RuntimeException(sprintf('Unsupported statistics database platform: %s', $platform::class));
        }

        $this->statisticsConnection->executeStatement($sql, $params, $types);

        return count($rows);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<string> $columns
     * @param list<string> $integerColumns
     * @param list<string> $booleanColumns
     * @return array{0:string,1:array<string,mixed>,2:array<string,ParameterType>}
     */
    private function buildBulkValues(
        array $rows,
        array $columns,
        array $integerColumns = [],
        array $booleanColumns = []
    ): array {
        $values = [];
        $params = [];
        $types = [];
        $integerLookup = array_fill_keys($integerColumns, true);
        $booleanLookup = array_fill_keys($booleanColumns, true);

        foreach ($rows as $rowIndex => $row) {
            $placeholders = [];
            foreach ($columns as $column) {
                $parameter = sprintf('%s_%d', $column, $rowIndex);
                $placeholders[] = ':' . $parameter;
                $params[$parameter] = $row[$column] ?? null;

                if (($row[$column] ?? null) !== null && isset($integerLookup[$column])) {
                    $types[$parameter] = ParameterType::INTEGER;
                } elseif (($row[$column] ?? null) !== null && isset($booleanLookup[$column])) {
                    $types[$parameter] = ParameterType::BOOLEAN;
                }
            }
            $values[] = '(' . implode(', ', $placeholders) . ')';
        }

        return [implode(",\n", $values), $params, $types];
    }

    private function resolveHorizonConnection(string $network): Connection
    {
        $connectionName = match ($network) {
            'mainnet' => 'horizon_mainnet',
            'testnet' => 'horizon_testnet',
            default => 'horizon_testnet',
        };

        return $this->doctrine->getConnection($connectionName);
    }

    private function resolveAddressId(array $addressIds, mixed $address): ?int
    {
        $normalized = $this->normalizeString($address, 64);

        return $normalized === '' ? null : ($addressIds[$normalized] ?? null);
    }

    private function resolveAssetId(array $assetIds, array $event, string $prefix): ?int
    {
        $key = $this->buildAssetKey(
            (string) $event[$prefix . '_asset_type'],
            (string) $event[$prefix . '_asset_code'],
            (string) $event[$prefix . '_asset_issuer']
        );

        return $assetIds[$key] ?? null;
    }

    private function buildAssetKey(string $assetType, ?string $assetCode, ?string $assetIssuer): string
    {
        [$assetType, $assetCode, $assetIssuer] = $this->normalizeAssetComponents($assetType, $assetCode, $assetIssuer);

        return implode("\0", [$assetType, $assetCode, $assetIssuer]);
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function parseAssetKey(string $key): array
    {
        $parts = explode("\0", $key, 3);

        return [
            $parts[0] ?? 'native',
            $parts[1] ?? 'XLM',
            $parts[2] ?? '',
        ];
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function normalizeAssetComponents(string $assetType, ?string $assetCode, ?string $assetIssuer): array
    {
        $assetType = $this->normalizeString($assetType, 32) ?: 'native';
        if ($assetType === 'native') {
            return ['native', 'XLM', ''];
        }

        return [
            $assetType,
            $this->normalizeString($assetCode, 32),
            $this->normalizeString($assetIssuer, 64),
        ];
    }

    private function formatUtcDateTime(mixed $value): ?string
    {
        try {
            if ($value instanceof \DateTimeImmutable) {
                return $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
            if ($value instanceof \DateTimeInterface) {
                return \DateTimeImmutable::createFromInterface($value)
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format('Y-m-d H:i:s');
            }
            if (!is_string($value) || trim($value) === '') {
                return null;
            }

            return (new \DateTimeImmutable(trim($value), new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeString(mixed $value, int $maxLength): string
    {
        if ($value === null) {
            return '';
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return '';
        }

        return substr($normalized, 0, $maxLength);
    }

    private function normalizeDecimalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '' || preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', $normalized) !== 1) {
            return null;
        }

        if (!str_contains($normalized, '.')) {
            return $normalized;
        }

        [$integer, $fraction] = explode('.', $normalized, 2);
        $fraction = substr($fraction, 0, 14);
        $fraction = rtrim($fraction, '0');

        return $fraction === '' ? $integer : sprintf('%s.%s', $integer, $fraction);
    }

    private function toInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) round($value);
        }
        if (is_string($value) && preg_match('/^-?[0-9]+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (!is_string($value)) {
            return (bool) $value;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 't', 'yes', 'y'], true);
    }
}
