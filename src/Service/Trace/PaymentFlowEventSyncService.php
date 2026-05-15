<?php

declare(strict_types=1);

namespace App\Service\Trace;

use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class PaymentFlowEventSyncService
{
    private const DEFAULT_BATCH_SIZE = 5000;

    /** @var array<int,string> */
    private const OPERATION_TYPE_NAMES = [
        0 => 'create_account',
        1 => 'payment',
        2 => 'path_payment_strict_receive',
        8 => 'account_merge',
        13 => 'path_payment_strict_send',
    ];

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
    END AS destination_asset_issuer,
    CASE
        WHEN ho.type = 0 THEN COALESCE(NULLIF(ho.details->>'starting_balance', ''), NULLIF(ho.details->>'amount', ''))
        WHEN ho.type = 8 THEN NULL
        ELSE NULLIF(ho.details->>'amount', '')
    END AS amount_decimal,
    CASE
        WHEN ho.type IN (0, 8) THEN 'native'
        ELSE COALESCE(NULLIF(ho.details->>'asset_type', ''), 'native')
    END AS asset_type,
    CASE
        WHEN ho.type IN (0, 8) THEN NULL
        ELSE NULLIF(ho.details->>'asset_code', '')
    END AS asset_code,
    CASE
        WHEN ho.type IN (0, 8) THEN NULL
        ELSE NULLIF(ho.details->>'asset_issuer', '')
    END AS asset_issuer
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
        $sourceAssetType = $this->normalizeString($row['source_asset_type'] ?? null, 32) ?: 'native';
        $destinationAssetType = $this->normalizeString($row['destination_asset_type'] ?? null, 32) ?: 'native';

        return [
            'network' => $networkCode,
            'ledger' => $ledger,
            'closed_at' => $closedAt,
            'tx_hash' => $txHash,
            'operation_id' => $operationId,
            'operation_index' => $this->toInt($row['operation_index'] ?? null),
            'operation_type' => self::OPERATION_TYPE_NAMES[$operationTypeId] ?? sprintf('operation_%d', $operationTypeId),
            'successful' => $this->toBool($row['successful'] ?? true),
            'source_account' => $sourceAccount ?: null,
            'from_address' => $fromAddress ?: null,
            'to_address' => $toAddress ?: null,
            'source_asset_type' => $sourceAssetType,
            'source_asset_code' => $this->normalizeString($row['source_asset_code'] ?? null, 32) ?: null,
            'source_asset_issuer' => $this->normalizeString($row['source_asset_issuer'] ?? null, 64) ?: null,
            'source_amount_decimal' => $this->normalizeDecimalString($row['source_amount_decimal'] ?? null),
            'destination_asset_type' => $destinationAssetType,
            'destination_asset_code' => $this->normalizeString($row['destination_asset_code'] ?? null, 32) ?: null,
            'destination_asset_issuer' => $this->normalizeString($row['destination_asset_issuer'] ?? null, 64) ?: null,
            'destination_amount_decimal' => $this->normalizeDecimalString($row['destination_amount_decimal'] ?? null),
            'asset_type' => $destinationAssetType,
            'asset_code' => $this->normalizeString($row['asset_code'] ?? $row['destination_asset_code'] ?? null, 32) ?: null,
            'asset_issuer' => $this->normalizeString($row['asset_issuer'] ?? $row['destination_asset_issuer'] ?? null, 64) ?: null,
            'amount_decimal' => $this->normalizeDecimalString($row['amount_decimal'] ?? $row['destination_amount_decimal'] ?? null),
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
        $rowsWritten = 0;
        $sql = $this->buildUpsertSql();

        $this->statisticsConnection->beginTransaction();
        try {
            foreach ($events as $event) {
                $this->statisticsConnection->executeStatement(
                    $sql,
                    [
                        'network' => $event['network'],
                        'ledger' => $event['ledger'],
                        'closed_at' => $event['closed_at'],
                        'tx_hash' => $event['tx_hash'],
                        'operation_id' => $event['operation_id'],
                        'operation_index' => $event['operation_index'],
                        'operation_type' => $event['operation_type'],
                        'successful' => $event['successful'],
                        'source_account' => $event['source_account'],
                        'from_address' => $event['from_address'],
                        'to_address' => $event['to_address'],
                        'source_asset_type' => $event['source_asset_type'],
                        'source_asset_code' => $event['source_asset_code'],
                        'source_asset_issuer' => $event['source_asset_issuer'],
                        'source_amount_decimal' => $event['source_amount_decimal'],
                        'destination_asset_type' => $event['destination_asset_type'],
                        'destination_asset_code' => $event['destination_asset_code'],
                        'destination_asset_issuer' => $event['destination_asset_issuer'],
                        'destination_amount_decimal' => $event['destination_amount_decimal'],
                        'asset_type' => $event['asset_type'],
                        'asset_code' => $event['asset_code'],
                        'asset_issuer' => $event['asset_issuer'],
                        'amount_decimal' => $event['amount_decimal'],
                        'memo_type' => $event['memo_type'],
                        'memo' => $event['memo'],
                        'created_at' => $nowString,
                        'updated_at' => $nowString,
                    ],
                    [
                        'network' => ParameterType::INTEGER,
                        'ledger' => ParameterType::INTEGER,
                        'operation_id' => ParameterType::INTEGER,
                        'operation_index' => ParameterType::INTEGER,
                        'successful' => ParameterType::BOOLEAN,
                    ]
                );
                $rowsWritten++;
            }

            $this->statisticsConnection->commit();
        } catch (\Throwable $exception) {
            $this->statisticsConnection->rollBack();
            throw $exception;
        }

        return $rowsWritten;
    }

    private function buildUpsertSql(): string
    {
        $platform = $this->statisticsConnection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            return <<<SQL
INSERT INTO payment_flow_event
    (network, ledger, closed_at, tx_hash, operation_id, operation_index, operation_type, successful, source_account, from_address, to_address, source_asset_type, source_asset_code, source_asset_issuer, source_amount_decimal, destination_asset_type, destination_asset_code, destination_asset_issuer, destination_amount_decimal, asset_type, asset_code, asset_issuer, amount_decimal, memo_type, memo, created_at, updated_at)
VALUES
    (:network, :ledger, :closed_at, :tx_hash, :operation_id, :operation_index, :operation_type, :successful, :source_account, :from_address, :to_address, :source_asset_type, :source_asset_code, :source_asset_issuer, :source_amount_decimal, :destination_asset_type, :destination_asset_code, :destination_asset_issuer, :destination_amount_decimal, :asset_type, :asset_code, :asset_issuer, :amount_decimal, :memo_type, :memo, :created_at, :updated_at)
ON DUPLICATE KEY UPDATE
    ledger = VALUES(ledger),
    closed_at = VALUES(closed_at),
    tx_hash = VALUES(tx_hash),
    operation_index = VALUES(operation_index),
    operation_type = VALUES(operation_type),
    successful = VALUES(successful),
    source_account = VALUES(source_account),
    from_address = VALUES(from_address),
    to_address = VALUES(to_address),
    source_asset_type = VALUES(source_asset_type),
    source_asset_code = VALUES(source_asset_code),
    source_asset_issuer = VALUES(source_asset_issuer),
    source_amount_decimal = VALUES(source_amount_decimal),
    destination_asset_type = VALUES(destination_asset_type),
    destination_asset_code = VALUES(destination_asset_code),
    destination_asset_issuer = VALUES(destination_asset_issuer),
    destination_amount_decimal = VALUES(destination_amount_decimal),
    asset_type = VALUES(asset_type),
    asset_code = VALUES(asset_code),
    asset_issuer = VALUES(asset_issuer),
    amount_decimal = VALUES(amount_decimal),
    memo_type = VALUES(memo_type),
    memo = VALUES(memo),
    updated_at = VALUES(updated_at)
SQL;
        }

        if ($platform instanceof PostgreSQLPlatform) {
            return <<<SQL
INSERT INTO payment_flow_event
    (network, ledger, closed_at, tx_hash, operation_id, operation_index, operation_type, successful, source_account, from_address, to_address, source_asset_type, source_asset_code, source_asset_issuer, source_amount_decimal, destination_asset_type, destination_asset_code, destination_asset_issuer, destination_amount_decimal, asset_type, asset_code, asset_issuer, amount_decimal, memo_type, memo, created_at, updated_at)
VALUES
    (:network, :ledger, :closed_at, :tx_hash, :operation_id, :operation_index, :operation_type, :successful, :source_account, :from_address, :to_address, :source_asset_type, :source_asset_code, :source_asset_issuer, :source_amount_decimal, :destination_asset_type, :destination_asset_code, :destination_asset_issuer, :destination_amount_decimal, :asset_type, :asset_code, :asset_issuer, :amount_decimal, :memo_type, :memo, :created_at, :updated_at)
ON CONFLICT (network, operation_id) DO UPDATE SET
    ledger = EXCLUDED.ledger,
    closed_at = EXCLUDED.closed_at,
    tx_hash = EXCLUDED.tx_hash,
    operation_index = EXCLUDED.operation_index,
    operation_type = EXCLUDED.operation_type,
    successful = EXCLUDED.successful,
    source_account = EXCLUDED.source_account,
    from_address = EXCLUDED.from_address,
    to_address = EXCLUDED.to_address,
    source_asset_type = EXCLUDED.source_asset_type,
    source_asset_code = EXCLUDED.source_asset_code,
    source_asset_issuer = EXCLUDED.source_asset_issuer,
    source_amount_decimal = EXCLUDED.source_amount_decimal,
    destination_asset_type = EXCLUDED.destination_asset_type,
    destination_asset_code = EXCLUDED.destination_asset_code,
    destination_asset_issuer = EXCLUDED.destination_asset_issuer,
    destination_amount_decimal = EXCLUDED.destination_amount_decimal,
    asset_type = EXCLUDED.asset_type,
    asset_code = EXCLUDED.asset_code,
    asset_issuer = EXCLUDED.asset_issuer,
    amount_decimal = EXCLUDED.amount_decimal,
    memo_type = EXCLUDED.memo_type,
    memo = EXCLUDED.memo,
    updated_at = EXCLUDED.updated_at
SQL;
        }

        throw new \RuntimeException(sprintf(
            'Unsupported statistics database platform: %s',
            $platform::class
        ));
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
