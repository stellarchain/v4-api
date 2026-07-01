<?php

namespace App\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ContractMetricsRefreshService
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.contracts_connection')]
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param list<int> $contractIds
     */
    public function refreshForContractIds(array $contractIds): int
    {
        $contractIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $contractIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($contractIds === []) {
            return 0;
        }

        $txCounts = $this->fetchGroupedIntMap(
            'SELECT contract_id, COUNT(*) AS cnt
             FROM contract_transactions
             WHERE contract_id IN (:ids)
             GROUP BY contract_id',
            $contractIds
        );
        $eventTxCounts = $this->fetchGroupedIntMap(
            'SELECT contract_id, COUNT(DISTINCT tx_hash) AS cnt
             FROM contract_events
             WHERE contract_id IN (:ids)
               AND tx_hash IS NOT NULL
               AND tx_hash <> \'\'
             GROUP BY contract_id',
            $contractIds
        );
        $eventCounts = $this->fetchGroupedIntMap(
            'SELECT contract_id, COUNT(*) AS cnt
             FROM contract_events
             WHERE contract_id IN (:ids)
             GROUP BY contract_id',
            $contractIds
        );
        $storageCounts = $this->fetchGroupedIntMap(
            'SELECT contract_id, COUNT(*) AS cnt
             FROM contract_storage_entries
             WHERE contract_id IN (:ids)
             GROUP BY contract_id',
            $contractIds
        );
        $txAggregate = $this->fetchTxAggregateMap($contractIds);

        $updated = 0;
        $this->connection->beginTransaction();
        try {
            foreach ($contractIds as $contractId) {
                $this->connection->update(
                    'contracts',
                    [
                        'total_transactions' => max((int) ($txCounts[$contractId] ?? 0), (int) ($eventTxCounts[$contractId] ?? 0)),
                        'total_operations' => (int) ($txAggregate[$contractId]['operations'] ?? 0),
                        'total_events' => (int) ($eventCounts[$contractId] ?? 0),
                        'total_effects' => (int) ($txAggregate[$contractId]['effects'] ?? 0),
                        'total_storage_entries' => (int) ($storageCounts[$contractId] ?? 0),
                        'total_invokes' => (int) ($txAggregate[$contractId]['invokes'] ?? 0),
                    ],
                    ['id' => $contractId],
                    [
                        'total_transactions' => ParameterType::INTEGER,
                        'total_operations' => ParameterType::INTEGER,
                        'total_events' => ParameterType::INTEGER,
                        'total_effects' => ParameterType::INTEGER,
                        'total_storage_entries' => ParameterType::INTEGER,
                        'total_invokes' => ParameterType::INTEGER,
                        'id' => ParameterType::INTEGER,
                    ]
                );
                $updated++;
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        return $updated;
    }

    /**
     * @param list<int> $ids
     * @return array<int,int>
     */
    private function fetchGroupedIntMap(string $sql, array $ids): array
    {
        $rows = $this->connection->fetchAllAssociative(
            $sql,
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER]
        );

        $result = [];
        foreach ($rows as $row) {
            $contractId = isset($row['contract_id']) ? (int) $row['contract_id'] : 0;
            if ($contractId <= 0) {
                continue;
            }
            $result[$contractId] = isset($row['cnt']) ? (int) $row['cnt'] : 0;
        }

        return $result;
    }

    /**
     * @param list<int> $ids
     * @return array<int,array{operations:int,effects:int,invokes:int}>
     */
    private function fetchTxAggregateMap(array $ids): array
    {
        $rows = $this->connection->fetchAllAssociative(
            $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform
                ? 'SELECT
                contract_id,
                COALESCE(SUM(COALESCE(total_operations, 0)), 0) AS operations,
                COALESCE(SUM(
                    CASE
                        WHEN host_functions IS NOT NULL
                             AND host_functions <> \'\'
                             AND host_functions LIKE \'%"invokeContracts":[%\'
                             AND host_functions NOT LIKE \'%"invokeContracts":[]%\'
                        THEN 1
                        ELSE 0
                    END
                ), 0) AS invokes,
                COALESCE(SUM(
                    CASE
                        WHEN host_functions IS NOT NULL
                             AND host_functions <> \'\'
                             AND host_functions LIKE \'%"effectsCount":%\'
                        THEN COALESCE(NULLIF(host_functions::jsonb ->> \'effectsCount\', \'\')::INT, 0)
                        ELSE 0
                    END
                ), 0) AS effects
             FROM contract_transactions
             WHERE contract_id IN (:ids)
             GROUP BY contract_id'
                : 'SELECT
                contract_id,
                COALESCE(SUM(COALESCE(total_operations, 0)), 0) AS operations,
                COALESCE(SUM(
                    CASE
                        WHEN host_functions IS NOT NULL
                             AND host_functions <> \'\'
                             AND host_functions LIKE \'%"invokeContracts":[%\'
                             AND host_functions NOT LIKE \'%"invokeContracts":[]%\'
                        THEN 1
                        ELSE 0
                    END
                ), 0) AS invokes,
                COALESCE(SUM(
                    CASE
                        WHEN JSON_VALID(host_functions)
                        THEN CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(host_functions, \'$.effectsCount\')), \'0\') AS UNSIGNED)
                        ELSE 0
                    END
                ), 0) AS effects
             FROM contract_transactions
             WHERE contract_id IN (:ids)
             GROUP BY contract_id',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER]
        );

        $result = [];
        foreach ($rows as $row) {
            $contractId = isset($row['contract_id']) ? (int) $row['contract_id'] : 0;
            if ($contractId <= 0) {
                continue;
            }

            $result[$contractId] = [
                'operations' => isset($row['operations']) ? (int) $row['operations'] : 0,
                'effects' => isset($row['effects']) ? (int) $row['effects'] : 0,
                'invokes' => isset($row['invokes']) ? (int) $row['invokes'] : 0,
            ];
        }

        return $result;
    }
}
