<?php

declare(strict_types=1);

namespace App\Service\Trace;

use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds an asset/role access path from already indexed classic payment events.
 * This service is deliberately not called by forwardfill or any public endpoint.
 */
final class PaymentFlowAssetSideSyncService
{
    public const MAX_RANGE_LEDGERS = 64;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.statistics_connection')]
        private readonly Connection $statisticsConnection,
        private readonly StellarNetworkResolver $networkResolver,
    ) {
    }

    /**
     * @return array{network:string,start_ledger:int,end_ledger:int,source_events:int,source_rows:int,destination_rows:int,excluded_source:int,excluded_destination:int,rows_replaced:int,rows_written:int,dry_run:bool}
     */
    public function sync(
        string $network,
        int $startLedger,
        int $endLedger,
        bool $apply = false,
        ?int $completedThroughLedger = null,
    ): array {
        $this->validateRange($startLedger, $endLedger, $apply, $completedThroughLedger);
        if (!$this->statisticsConnection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            throw new \RuntimeException('The asset-side read model requires PostgreSQL.');
        }
        if ($this->statisticsConnection->isTransactionActive()) {
            throw new \RuntimeException('Asset-side sync requires its own transaction.');
        }

        if (!in_array(strtolower(trim($network)), ['mainnet', 'public', 'testnet', 'test', 'futurenet', 'future'], true)) {
            throw new \InvalidArgumentException('Specify a valid network explicitly.');
        }
        $normalizedNetwork = $this->networkResolver->normalizeNetwork($network);
        $networkCode = $this->networkResolver->resolveNetworkCode($normalizedNetwork);
        if ($networkCode === null) {
            throw new \InvalidArgumentException('Unsupported network.');
        }

        $this->statisticsConnection->beginTransaction();
        try {
            $this->statisticsConnection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            if (!$apply) {
                $this->statisticsConnection->executeStatement('SET TRANSACTION READ ONLY');
            }
            $this->statisticsConnection->executeStatement("SET LOCAL statement_timeout = '8s'");
            $this->statisticsConnection->executeStatement("SET LOCAL lock_timeout = '1s'");

            if ($apply) {
                $this->assertTargetSchema();
                $this->acquireNetworkLock($networkCode);
            }
            $ledgerRows = $this->loadLedgerCoverage($networkCode, $startLedger, $endLedger);
            $totals = $this->summarizeLedgerCoverage($ledgerRows, $startLedger, $endLedger);

            $rowsReplaced = 0;
            $rowsWritten = 0;
            if ($apply) {
                $rowsReplaced = $this->deleteExistingSides($networkCode, $startLedger, $endLedger);
                $rowsWritten = $this->insertSides($networkCode, $startLedger, $endLedger);
                if ($rowsWritten !== $totals['source_rows'] + $totals['destination_rows']) {
                    throw new \RuntimeException('Asset-side insert count does not match the source snapshot.');
                }
                $this->writeLedgerCoverage($networkCode, $ledgerRows);
                $this->statisticsConnection->commit();
            } else {
                $this->statisticsConnection->rollBack();
            }

            return [
                'network' => $normalizedNetwork,
                'start_ledger' => $startLedger,
                'end_ledger' => $endLedger,
                ...$totals,
                'rows_replaced' => $rowsReplaced,
                'rows_written' => $rowsWritten,
                'dry_run' => !$apply,
            ];
        } catch (\Throwable $exception) {
            if ($this->statisticsConnection->isTransactionActive()) {
                $this->statisticsConnection->rollBack();
            }

            throw $exception;
        }
    }

    private function validateRange(int $startLedger, int $endLedger, bool $apply, ?int $completedThroughLedger): void
    {
        if ($startLedger < 1 || $endLedger < $startLedger || $endLedger > 2147483647
            || $endLedger - $startLedger + 1 > self::MAX_RANGE_LEDGERS) {
            throw new \InvalidArgumentException(sprintf('Use an ascending range of 1..%d positive ledgers.', self::MAX_RANGE_LEDGERS));
        }
        if ($apply && ($completedThroughLedger === null || $completedThroughLedger < $endLedger)) {
            throw new \InvalidArgumentException('--apply requires a verified completed-through ledger at or beyond the end of the range.');
        }
    }

    private function assertTargetSchema(): void
    {
        $sideTable = $this->statisticsConnection->fetchOne("SELECT to_regclass('payment_flow_asset_side')");
        $coverageTable = $this->statisticsConnection->fetchOne("SELECT to_regclass('payment_flow_asset_side_build_ledger')");
        if ($sideTable === null || $sideTable === false || $coverageTable === null || $coverageTable === false) {
            throw new \RuntimeException('Asset-side schema is not installed. Review bin/sql/asset-side/schema.sql first.');
        }
    }

    private function acquireNetworkLock(int $networkCode): void
    {
        $locked = $this->statisticsConnection->fetchOne(
            'SELECT pg_try_advisory_xact_lock(731904, :network)',
            ['network' => $networkCode],
            ['network' => ParameterType::INTEGER],
        );
        if (!in_array($locked, [true, 't', '1', 1], true)) {
            throw new \RuntimeException('Another asset-side sync is already running for this network.');
        }
    }

    /** @return list<array<string,mixed>> */
    private function loadLedgerCoverage(int $networkCode, int $startLedger, int $endLedger): array
    {
        return $this->statisticsConnection->fetchAllAssociative(<<<'SQL'
SELECT bounds.ledger,
       COALESCE(source.source_events, 0) AS source_events,
       COALESCE(source.source_rows, 0) AS source_rows,
       COALESCE(source.destination_rows, 0) AS destination_rows
FROM generate_series(CAST(:start_ledger AS INTEGER), CAST(:end_ledger AS INTEGER)) AS bounds(ledger)
LEFT JOIN (
    SELECT e.ledger,
           COUNT(*) AS source_events,
           COUNT(*) FILTER (WHERE e.source_asset_id IS NOT NULL
                             AND e.from_address_id IS NOT NULL
                             AND e.source_amount_decimal > 0) AS source_rows,
           COUNT(*) FILTER (WHERE e.destination_asset_id IS NOT NULL
                             AND e.to_address_id IS NOT NULL
                             AND e.destination_amount_decimal > 0) AS destination_rows
    FROM payment_flow_event e
    WHERE e.network = :network
      AND e.ledger BETWEEN :start_ledger AND :end_ledger
      AND e.successful = TRUE
      AND e.operation_type IN ('payment', 'path_payment_strict_receive', 'path_payment_strict_send')
    GROUP BY e.ledger
) AS source ON source.ledger = bounds.ledger
ORDER BY bounds.ledger
SQL, [
            'network' => $networkCode,
            'start_ledger' => $startLedger,
            'end_ledger' => $endLedger,
        ], [
            'network' => ParameterType::INTEGER,
            'start_ledger' => ParameterType::INTEGER,
            'end_ledger' => ParameterType::INTEGER,
        ]);
    }

    /**
     * @param list<array<string,mixed>> $ledgerRows
     * @return array{source_events:int,source_rows:int,destination_rows:int,excluded_source:int,excluded_destination:int}
     */
    private function summarizeLedgerCoverage(array $ledgerRows, int $startLedger, int $endLedger): array
    {
        if (count($ledgerRows) !== $endLedger - $startLedger + 1) {
            throw new \RuntimeException('The source coverage query did not return every requested ledger.');
        }

        $totals = [
            'source_events' => 0,
            'source_rows' => 0,
            'destination_rows' => 0,
            'excluded_source' => 0,
            'excluded_destination' => 0,
        ];
        $expectedLedger = $startLedger;
        foreach ($ledgerRows as $row) {
            if ((int) $row['ledger'] !== $expectedLedger) {
                throw new \RuntimeException('The source coverage query returned a non-contiguous ledger range.');
            }
            ++$expectedLedger;
            $sourceEvents = (int) $row['source_events'];
            $sourceRows = (int) $row['source_rows'];
            $destinationRows = (int) $row['destination_rows'];
            if ($sourceRows > $sourceEvents || $destinationRows > $sourceEvents) {
                throw new \RuntimeException('Asset-side counts exceed the source event count.');
            }
            $totals['source_events'] += $sourceEvents;
            $totals['source_rows'] += $sourceRows;
            $totals['destination_rows'] += $destinationRows;
            $totals['excluded_source'] += $sourceEvents - $sourceRows;
            $totals['excluded_destination'] += $sourceEvents - $destinationRows;
        }

        return $totals;
    }

    private function deleteExistingSides(int $networkCode, int $startLedger, int $endLedger): int
    {
        return $this->statisticsConnection->executeStatement(
            'DELETE FROM payment_flow_asset_side WHERE network = :network AND ledger BETWEEN :start_ledger AND :end_ledger',
            ['network' => $networkCode, 'start_ledger' => $startLedger, 'end_ledger' => $endLedger],
            ['network' => ParameterType::INTEGER, 'start_ledger' => ParameterType::INTEGER, 'end_ledger' => ParameterType::INTEGER],
        );
    }

    private function insertSides(int $networkCode, int $startLedger, int $endLedger): int
    {
        return $this->statisticsConnection->executeStatement(<<<'SQL'
INSERT INTO payment_flow_asset_side (network, ledger, event_id, side, asset_id, address_id, amount_decimal)
SELECT e.network, e.ledger, e.id, side.side, side.asset_id, side.address_id, side.amount_decimal
FROM payment_flow_event e
CROSS JOIN LATERAL (VALUES
    (1::smallint, e.source_asset_id, e.from_address_id, e.source_amount_decimal),
    (2::smallint, e.destination_asset_id, e.to_address_id, e.destination_amount_decimal)
) AS side(side, asset_id, address_id, amount_decimal)
WHERE e.network = :network
  AND e.ledger BETWEEN :start_ledger AND :end_ledger
  AND e.successful = TRUE
  AND e.operation_type IN ('payment', 'path_payment_strict_receive', 'path_payment_strict_send')
  AND side.asset_id IS NOT NULL
  AND side.address_id IS NOT NULL
  AND side.amount_decimal > 0
SQL, [
            'network' => $networkCode,
            'start_ledger' => $startLedger,
            'end_ledger' => $endLedger,
        ], [
            'network' => ParameterType::INTEGER,
            'start_ledger' => ParameterType::INTEGER,
            'end_ledger' => ParameterType::INTEGER,
        ]);
    }

    /** @param list<array<string,mixed>> $ledgerRows */
    private function writeLedgerCoverage(int $networkCode, array $ledgerRows): void
    {
        foreach ($ledgerRows as $row) {
            $sourceEvents = (int) $row['source_events'];
            $sourceRows = (int) $row['source_rows'];
            $destinationRows = (int) $row['destination_rows'];
            $this->statisticsConnection->executeStatement(<<<'SQL'
INSERT INTO payment_flow_asset_side_build_ledger
    (network, ledger, source_events, source_rows, destination_rows, excluded_source, excluded_destination, built_at)
VALUES (:network, :ledger, :source_events, :source_rows, :destination_rows, :excluded_source, :excluded_destination,
        NOW() AT TIME ZONE 'UTC')
ON CONFLICT (network, ledger) DO UPDATE SET
    source_events = EXCLUDED.source_events,
    source_rows = EXCLUDED.source_rows,
    destination_rows = EXCLUDED.destination_rows,
    excluded_source = EXCLUDED.excluded_source,
    excluded_destination = EXCLUDED.excluded_destination,
    built_at = EXCLUDED.built_at
SQL, [
                'network' => $networkCode,
                'ledger' => (int) $row['ledger'],
                'source_events' => $sourceEvents,
                'source_rows' => $sourceRows,
                'destination_rows' => $destinationRows,
                'excluded_source' => $sourceEvents - $sourceRows,
                'excluded_destination' => $sourceEvents - $destinationRows,
            ], [
                'network' => ParameterType::INTEGER,
                'ledger' => ParameterType::INTEGER,
                'source_events' => ParameterType::INTEGER,
                'source_rows' => ParameterType::INTEGER,
                'destination_rows' => ParameterType::INTEGER,
                'excluded_source' => ParameterType::INTEGER,
                'excluded_destination' => ParameterType::INTEGER,
            ]);
        }
    }
}
