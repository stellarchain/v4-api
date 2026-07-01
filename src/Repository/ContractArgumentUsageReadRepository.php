<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ContractArgumentUsageReadRepository
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.contracts_connection')]
        private readonly Connection $connection,
    ) {
    }

    public function contractExists(string $contractId, int $networkCode): bool
    {
        $rowId = $this->connection->fetchOne(
            'SELECT id
             FROM contracts
             WHERE contract_id = :contract_id
               AND network = :network
             LIMIT 1',
            [
                'contract_id' => $contractId,
                'network' => $networkCode,
            ],
            [
                'network' => ParameterType::INTEGER,
            ]
        );

        return $rowId !== false;
    }

    public function countArgumentUsages(string $contractId, int $networkCode): int
    {
        try {
            return (int) $this->connection->fetchOne(
                'SELECT COUNT(*)
                 FROM contract_argument_usages
                 WHERE referenced_contract_id = :referenced_contract_id
                   AND network = :network',
                [
                    'referenced_contract_id' => $contractId,
                    'network' => $networkCode,
                ],
                [
                    'network' => ParameterType::INTEGER,
                ]
            );
        } catch (\Throwable) {
            $queryBuilder = $this->connection->createQueryBuilder();
            $queryBuilder
                ->select('COUNT(*)')
                ->from('contract_transactions', 'ct')
                ->where('ct.contract_id IN (
                    SELECT c.id
                    FROM contracts c
                    WHERE c.network = :network
                )')
                ->andWhere('ct.host_functions IS NOT NULL')
                ->andWhere('ct.host_functions LIKE :needle')
                ->setParameter('network', $networkCode, ParameterType::INTEGER)
                ->setParameter('needle', '%"'.$contractId.'"%');

            return (int) $queryBuilder->executeQuery()->fetchOne();
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function loadCandidateRows(
        string $contractId,
        int $networkCode,
        int $itemsPerPage,
        int $page,
        ?int $beforeId,
        int $scanMultiplier,
    ): array {
        $neededForScan = ($itemsPerPage + 1) * max(1, $scanMultiplier);

        try {
            $queryBuilder = $this->connection->createQueryBuilder();
            $queryBuilder
                ->select(
                    'cau.id',
                    'cau.tx_hash',
                    'cau.source_account',
                    'cau.ledger',
                    'cau.created_at',
                    'cau.function_name',
                    'cau.matched_paths',
                    'cau.matches_count',
                    'cau.target_contract_address AS target_contract_id'
                )
                ->from('contract_argument_usages', 'cau')
                ->where('cau.referenced_contract_id = :referenced_contract_id')
                ->andWhere('cau.network = :network')
                ->orderBy('cau.id', 'DESC')
                ->setMaxResults($neededForScan)
                ->setParameter('referenced_contract_id', $contractId)
                ->setParameter('network', $networkCode, ParameterType::INTEGER);

            if ($beforeId !== null) {
                $queryBuilder
                    ->andWhere('cau.id < :before_id')
                    ->setParameter('before_id', $beforeId, ParameterType::INTEGER);
            } else {
                $queryBuilder->setFirstResult(($page - 1) * $itemsPerPage);
            }

            $rows = $queryBuilder->executeQuery()->fetchAllAssociative();
            return is_array($rows) ? $rows : [];
        } catch (\Throwable) {
            $queryBuilder = $this->connection->createQueryBuilder();
            $queryBuilder
                ->select(
                    'ct.id',
                    'ct.tx_hash',
                    'ct.source_account',
                    'ct.host_functions',
                    'ct.ledger',
                    'ct.created_at',
                    'ct.contract_id AS target_contract_db_id'
                )
                ->from('contract_transactions', 'ct')
                ->where('ct.contract_id IN (
                    SELECT c.id
                    FROM contracts c
                    WHERE c.network = :network
                )')
                ->andWhere('ct.host_functions IS NOT NULL')
                ->andWhere('ct.host_functions LIKE :needle')
                ->orderBy('ct.id', 'DESC')
                ->setMaxResults($neededForScan)
                ->setParameter('network', $networkCode, ParameterType::INTEGER)
                ->setParameter('needle', '%"'.$contractId.'"%');

            if ($beforeId !== null) {
                $queryBuilder
                    ->andWhere('ct.id < :before_id')
                    ->setParameter('before_id', $beforeId, ParameterType::INTEGER);
            } else {
                $queryBuilder->setFirstResult(($page - 1) * $itemsPerPage);
            }

            $rows = $queryBuilder->executeQuery()->fetchAllAssociative();
            if (!is_array($rows) || $rows === []) {
                return [];
            }

            return $this->mapTargetContractIds($rows, 'target_contract_db_id');
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function mapTargetContractIds(array $rows, string $idKey): array
    {
        $contractDbIds = [];
        foreach ($rows as $row) {
            $contractDbId = isset($row[$idKey]) ? (int) $row[$idKey] : 0;
            if ($contractDbId > 0) {
                $contractDbIds[] = $contractDbId;
            }
        }

        $contractMap = $this->loadContractAddressMap($contractDbIds);
        if ($contractMap === []) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $contractDbId = isset($row[$idKey]) ? (int) $row[$idKey] : 0;
            $targetContractId = $contractMap[$contractDbId] ?? null;
            if (!is_string($targetContractId) || $targetContractId === '') {
                continue;
            }

            $row['target_contract_id'] = $targetContractId;
            unset($row[$idKey]);
            $result[] = $row;
        }

        return $result;
    }

    /**
     * @param list<int> $contractDbIds
     * @return array<int,string>
     */
    private function loadContractAddressMap(array $contractDbIds): array
    {
        $contractDbIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $contractDbIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($contractDbIds === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, contract_id
             FROM contracts
             WHERE id IN (:ids)',
            ['ids' => $contractDbIds],
            ['ids' => ArrayParameterType::INTEGER]
        );
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            $id = isset($row['id']) ? (int) $row['id'] : 0;
            $contractId = trim((string) ($row['contract_id'] ?? ''));
            if ($id <= 0 || $contractId === '') {
                continue;
            }
            $map[$id] = $contractId;
        }

        return $map;
    }
}
