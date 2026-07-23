<?php

declare(strict_types=1);

namespace App\Service\Statistics;

final class NetworkMetricCatalog
{
    public const AVAILABLE_METRIC_KEYS = [
        'trades',
        'dex-vol-xlm',
        'xlm-total-pay',
        'ledgers',
        'tps',
        'ops',
        'tx-ledger',
        'tx-success',
        'tx-failed',
        'ops-ledger',
        'transactions',
        'operations',
        'avg-ledger-sec',
        'output-value',
        'invocations',
        'contracts',
        'fee-charged',
        'max-fee',
        'active-addresses',
        'accounts-created',
        'accounts-merged',
    ];

    public const DISABLED_METRIC_KEYS = [
        'price-usd',
        'rank',
        'market-cap',
        'volume-24h',
        'circulating-supply',
        'market-cap-dominance',
        'dex-vol',
        'accounts',
        'assets',
        'top-accounts',
        'inactive-addresses',
        'top-payers',
        'top-receivers',
        'top-contract-callers',
    ];

    public const METRIC_KEYS = [
        ...self::AVAILABLE_METRIC_KEYS,
        ...self::DISABLED_METRIC_KEYS,
    ];

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
        'top-payers' => 'network',
        'top-receivers' => 'network',
        'top-contract-callers' => 'network',
    ];

    public function hasMetric(string $metricKey): bool
    {
        return in_array(strtolower(trim($metricKey)), self::METRIC_KEYS, true);
    }

    public function isAvailableMetric(string $metricKey): bool
    {
        return in_array(strtolower(trim($metricKey)), self::AVAILABLE_METRIC_KEYS, true);
    }

    public function groupForMetric(string $metricKey): string
    {
        $normalized = strtolower(trim($metricKey));

        return self::GROUPS[$normalized] ?? 'network';
    }
}
