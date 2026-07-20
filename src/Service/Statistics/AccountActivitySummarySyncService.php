<?php

declare(strict_types=1);

namespace App\Service\Statistics;

use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class AccountActivitySummarySyncService
{
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
     *   accounts:int,
     *   rows_written:int,
     *   dry_run:bool
     * }
     */
    public function sync(string $network, int $startLedger, int $endLedger, bool $dryRun = false): array
    {
        if ($startLedger < 1 || $endLedger < 1) {
            throw new \InvalidArgumentException('Start and end ledger must be positive integers.');
        }
        if ($startLedger > $endLedger) {
            [$startLedger, $endLedger] = [$endLedger, $startLedger];
        }

        $normalizedNetwork = $this->networkResolver->normalizeNetwork($network, 'testnet');
        $networkCode = $this->networkResolver->resolveNetworkCode($normalizedNetwork) ?? 1;
        $horizonConnection = $this->resolveHorizonConnection($normalizedNetwork);
        $ledgerColumn = $this->resolveTransactionLedgerColumn($horizonConnection);

        $rows = $this->loadAccountRows($horizonConnection, $networkCode, $startLedger, $endLedger, $ledgerColumn);
        $rowsWritten = 0;
        if (!$dryRun && $rows !== []) {
            $rowsWritten = $this->upsertRows($rows);
        }

        return [
            'network' => $normalizedNetwork,
            'start_ledger' => $startLedger,
            'end_ledger' => $endLedger,
            'accounts' => count($rows),
            'rows_written' => $dryRun ? 0 : $rowsWritten,
            'dry_run' => $dryRun,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadAccountRows(
        Connection $connection,
        int $networkCode,
        int $startLedger,
        int $endLedger,
        string $ledgerColumn
    ): array {
        $rows = $connection->fetchAllAssociative(
            <<<SQL
WITH tx_base AS (
    SELECT
        ht.id AS tx_id,
        ht.{$ledgerColumn} AS ledger,
        ht.account AS tx_account,
        ht.created_at,
        COALESCE(ht.successful, TRUE) AS successful,
        COALESCE(ht.operation_count, 0) AS operation_count,
        COALESCE(ht.fee_charged, 0) AS fee_charged,
        COALESCE(ht.max_fee, 0) AS max_fee
    FROM history_transactions ht
    WHERE ht.{$ledgerColumn} BETWEEN :start_ledger AND :end_ledger
),
tx_involvement_raw AS (
    SELECT tx_account AS account_address, tx_id, ledger, created_at, successful, operation_count, fee_charged, max_fee
    FROM tx_base
    WHERE tx_account IS NOT NULL AND tx_account <> ''

    UNION ALL

    SELECT ho.source_account AS account_address, tb.tx_id, tb.ledger, tb.created_at, tb.successful, tb.operation_count, tb.fee_charged, tb.max_fee
    FROM history_operations ho
    INNER JOIN tx_base tb ON tb.tx_id = ho.transaction_id
    WHERE ho.source_account IS NOT NULL AND ho.source_account <> ''

    UNION ALL

    SELECT
        CASE
            WHEN ho.type = 0 THEN NULLIF(ho.details->>'account', '')
            WHEN ho.type = 8 THEN NULLIF(ho.details->>'into', '')
            ELSE NULLIF(ho.details->>'to', '')
        END AS account_address,
        tb.tx_id,
        tb.ledger,
        tb.created_at,
        tb.successful,
        tb.operation_count,
        tb.fee_charged,
        tb.max_fee
    FROM history_operations ho
    INNER JOIN tx_base tb ON tb.tx_id = ho.transaction_id
    WHERE ho.type IN (0, 1, 2, 8, 13)

    UNION ALL

    SELECT
        CASE
            WHEN ho.type = 0 THEN COALESCE(NULLIF(ho.details->>'funder', ''), NULLIF(ho.source_account, ''), NULLIF(tb.tx_account, ''))
            WHEN ho.type = 8 THEN COALESCE(NULLIF(ho.details->>'account', ''), NULLIF(ho.source_account, ''), NULLIF(tb.tx_account, ''))
            ELSE COALESCE(NULLIF(ho.details->>'from', ''), NULLIF(ho.source_account, ''), NULLIF(tb.tx_account, ''))
        END AS account_address,
        tb.tx_id,
        tb.ledger,
        tb.created_at,
        tb.successful,
        tb.operation_count,
        tb.fee_charged,
        tb.max_fee
    FROM history_operations ho
    INNER JOIN tx_base tb ON tb.tx_id = ho.transaction_id
    WHERE ho.type IN (0, 1, 2, 8, 13)
),
tx_involvement AS (
    SELECT DISTINCT ON (account_address, tx_id)
        account_address,
        tx_id,
        ledger,
        created_at,
        successful,
        operation_count,
        fee_charged,
        max_fee
    FROM tx_involvement_raw
    WHERE account_address IS NOT NULL AND account_address <> ''
    ORDER BY account_address, tx_id
),
tx_summary AS (
    SELECT
        account_address,
        MIN(ledger) AS first_ledger,
        MAX(ledger) AS last_ledger,
        MIN(created_at) AS first_activity_at,
        MAX(created_at) AS last_activity_at,
        COUNT(*) AS total_transactions,
        SUM(CASE WHEN successful THEN 1 ELSE 0 END) AS successful_transactions,
        SUM(CASE WHEN successful THEN 0 ELSE 1 END) AS failed_transactions,
        SUM(operation_count) AS operation_count,
        SUM(fee_charged) AS fee_charged_sum,
        SUM(max_fee) AS max_fee_sum
    FROM tx_involvement
    GROUP BY account_address
),
op_roles AS (
    SELECT
        COALESCE(NULLIF(ho.source_account, ''), NULLIF(tb.tx_account, '')) AS account_address,
        ho.type,
        'source' AS role,
        NULL::numeric AS native_amount
    FROM history_operations ho
    INNER JOIN tx_base tb ON tb.tx_id = ho.transaction_id
    WHERE COALESCE(tb.successful, TRUE) = TRUE

    UNION ALL

    SELECT
        CASE
            WHEN ho.type = 0 THEN NULLIF(ho.details->>'account', '')
            WHEN ho.type = 8 THEN NULLIF(ho.details->>'into', '')
            ELSE NULLIF(ho.details->>'to', '')
        END AS account_address,
        ho.type,
        'incoming' AS role,
        CASE
            WHEN ho.type = 0 AND COALESCE(ho.details->>'starting_balance', '') ~ '^-?[0-9]+(\\.[0-9]+)?$' THEN CAST(ho.details->>'starting_balance' AS NUMERIC(36, 14))
            WHEN ho.type IN (1, 2, 13)
             AND COALESCE(ho.details->>'asset_type', 'native') = 'native'
             AND COALESCE(ho.details->>'amount', '') ~ '^-?[0-9]+(\\.[0-9]+)?$' THEN CAST(ho.details->>'amount' AS NUMERIC(36, 14))
            ELSE NULL
        END AS native_amount
    FROM history_operations ho
    INNER JOIN tx_base tb ON tb.tx_id = ho.transaction_id
    WHERE ho.type IN (0, 1, 2, 8, 13)
      AND COALESCE(tb.successful, TRUE) = TRUE

    UNION ALL

    SELECT
        CASE
            WHEN ho.type = 0 THEN COALESCE(NULLIF(ho.details->>'funder', ''), NULLIF(ho.source_account, ''), NULLIF(tb.tx_account, ''))
            WHEN ho.type = 8 THEN COALESCE(NULLIF(ho.details->>'account', ''), NULLIF(ho.source_account, ''), NULLIF(tb.tx_account, ''))
            ELSE COALESCE(NULLIF(ho.details->>'from', ''), NULLIF(ho.source_account, ''), NULLIF(tb.tx_account, ''))
        END AS account_address,
        ho.type,
        'outgoing' AS role,
        CASE
            WHEN ho.type = 0 AND COALESCE(ho.details->>'starting_balance', '') ~ '^-?[0-9]+(\\.[0-9]+)?$' THEN CAST(ho.details->>'starting_balance' AS NUMERIC(36, 14))
            WHEN ho.type = 1
             AND COALESCE(ho.details->>'asset_type', 'native') = 'native'
             AND COALESCE(ho.details->>'amount', '') ~ '^-?[0-9]+(\\.[0-9]+)?$' THEN CAST(ho.details->>'amount' AS NUMERIC(36, 14))
            WHEN ho.type IN (2, 13)
             AND COALESCE(ho.details->>'source_asset_type', '') = 'native'
             AND COALESCE(ho.details->>'source_amount', '') ~ '^-?[0-9]+(\\.[0-9]+)?$' THEN CAST(ho.details->>'source_amount' AS NUMERIC(36, 14))
            ELSE NULL
        END AS native_amount
    FROM history_operations ho
    INNER JOIN tx_base tb ON tb.tx_id = ho.transaction_id
    WHERE ho.type IN (0, 1, 2, 8, 13)
      AND COALESCE(tb.successful, TRUE) = TRUE
),
op_summary AS (
    SELECT
        account_address,
        SUM(CASE WHEN role = 'source' THEN 1 ELSE 0 END) AS operation_source_count,
        SUM(CASE WHEN role = 'outgoing' AND type IN (0, 1, 2, 13) THEN 1 ELSE 0 END) AS payment_sent_count,
        SUM(CASE WHEN role = 'incoming' AND type IN (0, 1, 2, 13) THEN 1 ELSE 0 END) AS payment_received_count,
        SUM(CASE WHEN role = 'outgoing' THEN COALESCE(native_amount, 0) ELSE 0 END) AS native_sent,
        SUM(CASE WHEN role = 'incoming' THEN COALESCE(native_amount, 0) ELSE 0 END) AS native_received,
        SUM(CASE WHEN role = 'source' AND type IN (3, 4, 12, 22, 23) THEN 1 ELSE 0 END) AS trade_operation_count,
        SUM(CASE WHEN role = 'source' AND type IN (1, 2, 3, 4, 6, 7, 12, 13, 14, 15, 19, 20, 21, 22, 23) THEN 1 ELSE 0 END) AS asset_operation_count,
        SUM(CASE WHEN role = 'source' AND type IN (24, 25, 26) THEN 1 ELSE 0 END) AS contract_operation_count,
        SUM(CASE WHEN role = 'incoming' AND type = 0 THEN 1 ELSE 0 END) AS account_created_count,
        SUM(CASE WHEN role = 'outgoing' AND type = 0 THEN 1 ELSE 0 END) AS account_funded_count,
        SUM(CASE WHEN role = 'outgoing' AND type = 8 THEN 1 ELSE 0 END) AS account_merged_count,
        SUM(CASE WHEN role = 'incoming' AND type = 8 THEN 1 ELSE 0 END) AS merge_destination_count
    FROM op_roles
    WHERE account_address IS NOT NULL AND account_address <> ''
    GROUP BY account_address
)
SELECT
    COALESCE(tx.account_address, op.account_address) AS account_address,
    tx.first_ledger,
    tx.last_ledger,
    tx.first_activity_at,
    tx.last_activity_at,
    COALESCE(tx.total_transactions, 0) AS total_transactions,
    COALESCE(tx.successful_transactions, 0) AS successful_transactions,
    COALESCE(tx.failed_transactions, 0) AS failed_transactions,
    COALESCE(tx.operation_count, 0) AS operation_count,
    COALESCE(tx.fee_charged_sum, 0) AS fee_charged_sum,
    COALESCE(tx.max_fee_sum, 0) AS max_fee_sum,
    COALESCE(op.operation_source_count, 0) AS operation_source_count,
    COALESCE(op.payment_sent_count, 0) AS payment_sent_count,
    COALESCE(op.payment_received_count, 0) AS payment_received_count,
    COALESCE(op.native_sent, 0) AS native_sent,
    COALESCE(op.native_received, 0) AS native_received,
    COALESCE(op.trade_operation_count, 0) AS trade_operation_count,
    COALESCE(op.asset_operation_count, 0) AS asset_operation_count,
    COALESCE(op.contract_operation_count, 0) AS contract_operation_count,
    COALESCE(op.account_created_count, 0) AS account_created_count,
    COALESCE(op.account_funded_count, 0) AS account_funded_count,
    COALESCE(op.account_merged_count, 0) AS account_merged_count,
    COALESCE(op.merge_destination_count, 0) AS merge_destination_count
FROM tx_summary tx
FULL OUTER JOIN op_summary op ON op.account_address = tx.account_address
ORDER BY account_address ASC
SQL,
            [
                'start_ledger' => $startLedger,
                'end_ledger' => $endLedger,
            ],
            [
                'start_ledger' => ParameterType::INTEGER,
                'end_ledger' => ParameterType::INTEGER,
            ]
        );

        $result = [];
        foreach ($rows as $row) {
            $account = $this->normalizeString($row['account_address'] ?? null, 64);
            if ($account === '') {
                continue;
            }

            $result[] = [
                'network' => $networkCode,
                'range_start_ledger' => $startLedger,
                'range_end_ledger' => $endLedger,
                'account_address' => $account,
                'first_ledger' => $this->toInt($row['first_ledger'] ?? null),
                'last_ledger' => $this->toInt($row['last_ledger'] ?? null),
                'first_activity_at' => $this->formatUtcDateTime($row['first_activity_at'] ?? null),
                'last_activity_at' => $this->formatUtcDateTime($row['last_activity_at'] ?? null),
                'total_transactions' => $this->toInt($row['total_transactions'] ?? null, 0) ?? 0,
                'successful_transactions' => $this->toInt($row['successful_transactions'] ?? null, 0) ?? 0,
                'failed_transactions' => $this->toInt($row['failed_transactions'] ?? null, 0) ?? 0,
                'operation_count' => $this->toInt($row['operation_count'] ?? null, 0) ?? 0,
                'fee_charged_sum' => $this->normalizeNumericString($row['fee_charged_sum'] ?? '0') ?? '0',
                'max_fee_sum' => $this->normalizeNumericString($row['max_fee_sum'] ?? '0') ?? '0',
                'operation_source_count' => $this->toInt($row['operation_source_count'] ?? null, 0) ?? 0,
                'payment_sent_count' => $this->toInt($row['payment_sent_count'] ?? null, 0) ?? 0,
                'payment_received_count' => $this->toInt($row['payment_received_count'] ?? null, 0) ?? 0,
                'native_sent' => $this->normalizeNumericString($row['native_sent'] ?? '0') ?? '0',
                'native_received' => $this->normalizeNumericString($row['native_received'] ?? '0') ?? '0',
                'trade_operation_count' => $this->toInt($row['trade_operation_count'] ?? null, 0) ?? 0,
                'asset_operation_count' => $this->toInt($row['asset_operation_count'] ?? null, 0) ?? 0,
                'contract_operation_count' => $this->toInt($row['contract_operation_count'] ?? null, 0) ?? 0,
                'account_created_count' => $this->toInt($row['account_created_count'] ?? null, 0) ?? 0,
                'account_funded_count' => $this->toInt($row['account_funded_count'] ?? null, 0) ?? 0,
                'account_merged_count' => $this->toInt($row['account_merged_count'] ?? null, 0) ?? 0,
                'merge_destination_count' => $this->toInt($row['merge_destination_count'] ?? null, 0) ?? 0,
            ];
        }

        return $result;
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function upsertRows(array $rows): int
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $sql = $this->buildUpsertSql();
        $written = 0;

        $this->statisticsConnection->beginTransaction();
        try {
            foreach ($rows as $row) {
                $params = $row;
                $params['created_at'] = $now;
                $params['updated_at'] = $now;
                $this->statisticsConnection->executeStatement(
                    $sql,
                    $params,
                    [
                        'network' => ParameterType::INTEGER,
                        'range_start_ledger' => ParameterType::INTEGER,
                        'range_end_ledger' => ParameterType::INTEGER,
                        'first_ledger' => ParameterType::INTEGER,
                        'last_ledger' => ParameterType::INTEGER,
                        'total_transactions' => ParameterType::INTEGER,
                        'successful_transactions' => ParameterType::INTEGER,
                        'failed_transactions' => ParameterType::INTEGER,
                        'operation_count' => ParameterType::INTEGER,
                        'operation_source_count' => ParameterType::INTEGER,
                        'payment_sent_count' => ParameterType::INTEGER,
                        'payment_received_count' => ParameterType::INTEGER,
                        'trade_operation_count' => ParameterType::INTEGER,
                        'asset_operation_count' => ParameterType::INTEGER,
                        'contract_operation_count' => ParameterType::INTEGER,
                        'account_created_count' => ParameterType::INTEGER,
                        'account_funded_count' => ParameterType::INTEGER,
                        'account_merged_count' => ParameterType::INTEGER,
                        'merge_destination_count' => ParameterType::INTEGER,
                    ]
                );
                $written++;
            }

            $this->statisticsConnection->commit();
        } catch (\Throwable $exception) {
            $this->statisticsConnection->rollBack();
            throw $exception;
        }

        return $written;
    }

    private function buildUpsertSql(): string
    {
        $platform = $this->statisticsConnection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            return <<<SQL
INSERT INTO account_activity_summary
    (network, range_start_ledger, range_end_ledger, account_address, first_ledger, last_ledger, first_activity_at, last_activity_at, total_transactions, successful_transactions, failed_transactions, operation_count, fee_charged_sum, max_fee_sum, operation_source_count, payment_sent_count, payment_received_count, native_sent, native_received, trade_operation_count, asset_operation_count, contract_operation_count, account_created_count, account_funded_count, account_merged_count, merge_destination_count, created_at, updated_at)
VALUES
    (:network, :range_start_ledger, :range_end_ledger, :account_address, :first_ledger, :last_ledger, :first_activity_at, :last_activity_at, :total_transactions, :successful_transactions, :failed_transactions, :operation_count, :fee_charged_sum, :max_fee_sum, :operation_source_count, :payment_sent_count, :payment_received_count, :native_sent, :native_received, :trade_operation_count, :asset_operation_count, :contract_operation_count, :account_created_count, :account_funded_count, :account_merged_count, :merge_destination_count, :created_at, :updated_at)
ON DUPLICATE KEY UPDATE
    first_ledger = VALUES(first_ledger),
    last_ledger = VALUES(last_ledger),
    first_activity_at = VALUES(first_activity_at),
    last_activity_at = VALUES(last_activity_at),
    total_transactions = VALUES(total_transactions),
    successful_transactions = VALUES(successful_transactions),
    failed_transactions = VALUES(failed_transactions),
    operation_count = VALUES(operation_count),
    fee_charged_sum = VALUES(fee_charged_sum),
    max_fee_sum = VALUES(max_fee_sum),
    operation_source_count = VALUES(operation_source_count),
    payment_sent_count = VALUES(payment_sent_count),
    payment_received_count = VALUES(payment_received_count),
    native_sent = VALUES(native_sent),
    native_received = VALUES(native_received),
    trade_operation_count = VALUES(trade_operation_count),
    asset_operation_count = VALUES(asset_operation_count),
    contract_operation_count = VALUES(contract_operation_count),
    account_created_count = VALUES(account_created_count),
    account_funded_count = VALUES(account_funded_count),
    account_merged_count = VALUES(account_merged_count),
    merge_destination_count = VALUES(merge_destination_count),
    updated_at = VALUES(updated_at)
SQL;
        }

        if ($platform instanceof PostgreSQLPlatform) {
            return <<<SQL
INSERT INTO account_activity_summary
    (network, range_start_ledger, range_end_ledger, account_address, first_ledger, last_ledger, first_activity_at, last_activity_at, total_transactions, successful_transactions, failed_transactions, operation_count, fee_charged_sum, max_fee_sum, operation_source_count, payment_sent_count, payment_received_count, native_sent, native_received, trade_operation_count, asset_operation_count, contract_operation_count, account_created_count, account_funded_count, account_merged_count, merge_destination_count, created_at, updated_at)
VALUES
    (:network, :range_start_ledger, :range_end_ledger, :account_address, :first_ledger, :last_ledger, :first_activity_at, :last_activity_at, :total_transactions, :successful_transactions, :failed_transactions, :operation_count, :fee_charged_sum, :max_fee_sum, :operation_source_count, :payment_sent_count, :payment_received_count, :native_sent, :native_received, :trade_operation_count, :asset_operation_count, :contract_operation_count, :account_created_count, :account_funded_count, :account_merged_count, :merge_destination_count, :created_at, :updated_at)
ON CONFLICT (network, range_start_ledger, range_end_ledger, account_address) DO UPDATE SET
    first_ledger = EXCLUDED.first_ledger,
    last_ledger = EXCLUDED.last_ledger,
    first_activity_at = EXCLUDED.first_activity_at,
    last_activity_at = EXCLUDED.last_activity_at,
    total_transactions = EXCLUDED.total_transactions,
    successful_transactions = EXCLUDED.successful_transactions,
    failed_transactions = EXCLUDED.failed_transactions,
    operation_count = EXCLUDED.operation_count,
    fee_charged_sum = EXCLUDED.fee_charged_sum,
    max_fee_sum = EXCLUDED.max_fee_sum,
    operation_source_count = EXCLUDED.operation_source_count,
    payment_sent_count = EXCLUDED.payment_sent_count,
    payment_received_count = EXCLUDED.payment_received_count,
    native_sent = EXCLUDED.native_sent,
    native_received = EXCLUDED.native_received,
    trade_operation_count = EXCLUDED.trade_operation_count,
    asset_operation_count = EXCLUDED.asset_operation_count,
    contract_operation_count = EXCLUDED.contract_operation_count,
    account_created_count = EXCLUDED.account_created_count,
    account_funded_count = EXCLUDED.account_funded_count,
    account_merged_count = EXCLUDED.account_merged_count,
    merge_destination_count = EXCLUDED.merge_destination_count,
    updated_at = EXCLUDED.updated_at
SQL;
        }

        throw new \RuntimeException(sprintf('Unsupported statistics database platform: %s', $platform::class));
    }

    private function resolveTransactionLedgerColumn(Connection $connection): string
    {
        $rows = $connection->fetchFirstColumn(
            "SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'history_transactions'"
        );
        $columns = array_flip(array_map(static fn (mixed $value): string => strtolower((string) $value), $rows));

        if (isset($columns['ledger_sequence'])) {
            return 'ledger_sequence';
        }
        if (isset($columns['ledger_seq'])) {
            return 'ledger_seq';
        }

        throw new \RuntimeException('history_transactions does not expose ledger_sequence or ledger_seq.');
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

    private function normalizeString(mixed $value, int $maxLength): string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? '' : substr($normalized, 0, $maxLength);
    }

    private function normalizeNumericString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $normalized = trim((string) $value);
        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        return $normalized;
    }

    private function formatUtcDateTime(mixed $value): ?string
    {
        try {
            if ($value instanceof \DateTimeImmutable) {
                return $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
            if ($value instanceof \DateTimeInterface) {
                return \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
            if (!is_string($value) || trim($value) === '') {
                return null;
            }

            return (new \DateTimeImmutable(trim($value), new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function toInt(mixed $value, ?int $default = null): ?int
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

        return $default;
    }
}
