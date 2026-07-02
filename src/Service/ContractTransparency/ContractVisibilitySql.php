<?php

declare(strict_types=1);

namespace App\Service\ContractTransparency;

final class ContractVisibilitySql
{
    public static function confirmedPredicate(string $alias = 'c'): string
    {
        $alias = preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias) === 1 ? $alias : 'c';

        return sprintf(
            <<<'SQL'
(
    %1$s.deployed_ledger IS NOT NULL
    OR %1$s.deployed_at IS NOT NULL
    OR %1$s.wasm_id IS NOT NULL
    OR %1$s.executable_type IS NOT NULL
    OR COALESCE(%1$s.total_invokes, 0) > 0
    OR COALESCE(%1$s.total_storage_entries, 0) > 0
    OR EXISTS (
        SELECT 1
        FROM contract_transactions visible_ct
        WHERE visible_ct.contract_id = %1$s.id
          AND visible_ct.host_functions IS NOT NULL
          AND visible_ct.host_functions <> ''
          AND visible_ct.host_functions LIKE '%%"invokeContracts":[%%'
          AND visible_ct.host_functions NOT LIKE '%%"invokeContracts":[]%%'
        LIMIT 1
    )
)
SQL,
            $alias
        );
    }
}
