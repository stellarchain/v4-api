<?php

declare(strict_types=1);

namespace App\Service\Statistics;

final class NetworkMetricCatalog
{
    /**
     * @var array<string,string>
     */
    private const GROUPS = [
        'price-usd' => 'market',
        'rank' => 'market',
        'market-cap' => 'market',
        'volume-24h' => 'market',
        'circulating-supply' => 'market',
        'market-cap-dominance' => 'market',
        'trades' => 'market',
        'dex-vol' => 'market',
        'dex-vol-xlm' => 'market',
        'xlm-total-pay' => 'market',
        'ledgers' => 'blockchain',
        'tps' => 'blockchain',
        'ops' => 'blockchain',
        'tx-ledger' => 'blockchain',
        'tx-success' => 'blockchain',
        'tx-failed' => 'blockchain',
        'ops-ledger' => 'blockchain',
        'transactions' => 'blockchain',
        'operations' => 'blockchain',
        'avg-ledger-sec' => 'blockchain',
        'accounts' => 'network',
        'assets' => 'network',
        'output-value' => 'network',
        'top-accounts' => 'network',
        'invocations' => 'network',
        'contracts' => 'network',
        'fee-charged' => 'network',
        'max-fee' => 'network',
        'active-addresses' => 'network',
        'inactive-addresses' => 'network',
        'accounts-created' => 'network',
        'accounts-merged' => 'network',
    ];

    public function groupForMetric(string $metricKey): string
    {
        $normalized = strtolower(trim($metricKey));

        return self::GROUPS[$normalized] ?? 'network';
    }
}
