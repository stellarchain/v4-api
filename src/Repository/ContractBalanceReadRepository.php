<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\ContractTransparency\ContractVisibilitySql;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ContractBalanceReadRepository
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.contracts_connection')]
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<array{address:string,balance_raw:string,inflow_raw:string,outflow_raw:string}>
     */
    public function findBalancesByContract(string $contractId, int $networkCode, int $limit, int $offset = 0): array
    {
        $contractDbId = $this->resolveContractDbId($contractId, $networkCode);
        if ($contractDbId === null) {
            return [];
        }

        $amountCastType = $this->amountCastType();
        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT
                chb.holder_address AS address,
                CAST(chb.balance_raw AS %1$s) AS balance_raw,
                CAST(chb.inflow_raw AS %1$s) AS inflow_raw,
                CAST(chb.outflow_raw AS %1$s) AS outflow_raw
             FROM contract_holder_balances chb
             WHERE chb.contract_id = :contract_id
               AND chb.network = :network
             ORDER BY chb.balance_raw DESC, chb.holder_address ASC
             LIMIT :limit_rows OFFSET :offset_rows', $amountCastType),
            [
                'contract_id' => $contractDbId,
                'network' => $networkCode,
                'limit_rows' => $limit,
                'offset_rows' => $offset,
            ],
            [
                'contract_id' => ParameterType::INTEGER,
                'network' => ParameterType::INTEGER,
                'limit_rows' => ParameterType::INTEGER,
                'offset_rows' => ParameterType::INTEGER,
            ]
        );

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return $rows;
    }

    /**
     * @return array{holders_count:int,indexed_balance_raw:string,inflow_raw:string,outflow_raw:string}
     */
    public function findContractBalanceSummary(string $contractId, int $networkCode): array
    {
        $contractDbId = $this->resolveContractDbId($contractId, $networkCode);
        if ($contractDbId === null) {
            return [
                'holders_count' => 0,
                'indexed_balance_raw' => '0',
                'inflow_raw' => '0',
                'outflow_raw' => '0',
            ];
        }

        $amountCastType = $this->amountCastType();
        $row = $this->connection->fetchAssociative(
            sprintf('SELECT
                COUNT(*) AS holders_count,
                CAST(COALESCE(SUM(chb.balance_raw), 0) AS %1$s) AS indexed_balance_raw,
                CAST(COALESCE(SUM(chb.inflow_raw), 0) AS %1$s) AS inflow_raw,
                CAST(COALESCE(SUM(chb.outflow_raw), 0) AS %1$s) AS outflow_raw
             FROM contract_holder_balances chb
             WHERE chb.contract_id = :contract_id
               AND chb.network = :network', $amountCastType),
            [
                'contract_id' => $contractDbId,
                'network' => $networkCode,
            ],
            [
                'contract_id' => ParameterType::INTEGER,
                'network' => ParameterType::INTEGER,
            ]
        );

        if (!is_array($row)) {
            return [
                'holders_count' => 0,
                'indexed_balance_raw' => '0',
                'inflow_raw' => '0',
                'outflow_raw' => '0',
            ];
        }

        return [
            'holders_count' => isset($row['holders_count']) ? (int) $row['holders_count'] : 0,
            'indexed_balance_raw' => (string) ($row['indexed_balance_raw'] ?? '0'),
            'inflow_raw' => (string) ($row['inflow_raw'] ?? '0'),
            'outflow_raw' => (string) ($row['outflow_raw'] ?? '0'),
        ];
    }

    /**
     * @return list<array{related_contract_id:string,balance_raw:string,inflow_raw:string,outflow_raw:string}>
     */
    public function findHolderBalancesAcrossContracts(string $holderAddress, int $networkCode, int $limit, int $offset = 0): array
    {
        $amountCastType = $this->amountCastType();
        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT
                chb.contract_id,
                CAST(chb.balance_raw AS %1$s) AS balance_raw,
                CAST(chb.inflow_raw AS %1$s) AS inflow_raw,
                CAST(chb.outflow_raw AS %1$s) AS outflow_raw
             FROM contract_holder_balances chb
             WHERE chb.holder_address = :holder_address
               AND chb.network = :network
             ORDER BY ABS(chb.balance_raw) DESC, chb.contract_id ASC
             LIMIT :limit_rows OFFSET :offset_rows', $amountCastType),
            [
                'holder_address' => $holderAddress,
                'network' => $networkCode,
                'limit_rows' => $limit,
                'offset_rows' => $offset,
            ],
            [
                'network' => ParameterType::INTEGER,
                'limit_rows' => ParameterType::INTEGER,
                'offset_rows' => ParameterType::INTEGER,
            ]
        );

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return $this->mapContractRows($rows, 'contract_id');
    }

    private function resolveContractDbId(string $contractId, int $networkCode): ?int
    {
        $rowId = $this->connection->fetchOne(
            'SELECT c.id
             FROM contracts c
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
            ]
        );

        if ($rowId === false) {
            return null;
        }

        $normalizedId = (int) $rowId;
        if ($normalizedId <= 0) {
            return null;
        }

        return $normalizedId;
    }

    private function amountCastType(): string
    {
        return $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? 'TEXT' : 'CHAR';
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array{related_contract_id:string,balance_raw:string,inflow_raw:string,outflow_raw:string}>
     */
    private function mapContractRows(array $rows, string $idKey): array
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
            $relatedContractId = $contractMap[$contractDbId] ?? null;
            if (!is_string($relatedContractId) || $relatedContractId === '') {
                continue;
            }

            $result[] = [
                'related_contract_id' => $relatedContractId,
                'balance_raw' => (string) ($row['balance_raw'] ?? '0'),
                'inflow_raw' => (string) ($row['inflow_raw'] ?? '0'),
                'outflow_raw' => (string) ($row['outflow_raw'] ?? '0'),
            ];
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
            'SELECT c.id, c.contract_id
             FROM contracts c
             WHERE c.id IN (:ids)
               AND '.ContractVisibilitySql::confirmedPredicate('c'),
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
