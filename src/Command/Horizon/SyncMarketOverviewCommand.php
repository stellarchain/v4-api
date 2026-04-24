<?php

declare(strict_types=1);

namespace App\Command\Horizon;

use App\Command\Support\NetworkOptionTrait;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:horizon:sync-market-overview',
    description: 'Build and persist market overview snapshot (XLM-focused) from Horizon DB.',
)]
final class SyncMarketOverviewCommand extends Command
{
    use NetworkOptionTrait;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private readonly Connection $connection,
        private readonly ManagerRegistry $doctrine,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addNetworkOption('Network to sync (mainnet|testnet|futurenet)', 'testnet')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compute metrics without writing to local DB');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $network = $this->resolveNetworkOption($input, $this->stellarNetworkResolver);
        $networkCode = $this->resolveNetworkCodeOption($input, $this->stellarNetworkResolver);
        $dryRun = (bool) $input->getOption('dry-run');
        $horizonConnection = $this->resolveHorizonConnection($network);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $from24hMs = (string) (($now->getTimestamp() - 86400) * 1000);

        $nativeAssetId = $this->toInt($horizonConnection->fetchOne(
            "SELECT id FROM history_assets WHERE asset_type = 'native' ORDER BY id ASC LIMIT 1"
        ));
        if ($nativeAssetId === null) {
            $io->error('Cannot resolve native asset id from horizon.history_assets.');

            return Command::FAILURE;
        }

        $tradeRow = $horizonConnection->fetchAssociative(
            <<<SQL
SELECT
    COALESCE(SUM(CASE WHEN t.base_asset_id = :native_id THEN t.base_volume ELSE t.counter_volume END), 0) AS xlm_volume_24h,
    COALESCE(SUM(t.count), 0) AS total_trades_24h,
    COALESCE(COUNT(DISTINCT CASE WHEN t.base_asset_id = :native_id THEN t.counter_asset_id ELSE t.base_asset_id END), 0) AS active_assets_24h
FROM history_trades_60000 t
WHERE t.timestamp >= :from_24h
  AND (t.base_asset_id = :native_id OR t.counter_asset_id = :native_id)
SQL,
            [
                'native_id' => $nativeAssetId,
                'from_24h' => $from24hMs,
            ],
            [
                'native_id' => ParameterType::INTEGER,
                'from_24h' => ParameterType::STRING,
            ]
        ) ?: [];

        $trackedAssets = (int) $horizonConnection->fetchOne(
            "SELECT COUNT(*) FROM exp_asset_stats WHERE asset_type IN (1,2) AND asset_code <> '' AND asset_issuer <> ''"
        );
        $totalAccounts = (int) $horizonConnection->fetchOne('SELECT COUNT(*) FROM accounts');
        $totalContracts = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM contracts WHERE network = :network',
            ['network' => $networkCode],
            ['network' => ParameterType::INTEGER]
        );
        $xlmPriceUsd = $this->connection->fetchOne(
            <<<SQL
SELECT amh.value_decimal
FROM asset_metric_history amh
JOIN asset a ON a.id = amh.asset_id
WHERE a.network IN (:networks)
  AND a.asset_key = 'XLM-native'
  AND amh.source = 'coingecko_stellar'
  AND amh.metric_key = 'market_data.current_price.usd'
ORDER BY CASE WHEN a.network = :network THEN 0 ELSE 1 END, amh.recorded_at DESC, amh.id DESC
LIMIT 1
SQL,
            [
                'network' => $networkCode,
                'networks' => array_values(array_unique([$networkCode, 1])),
            ],
            [
                'network' => ParameterType::INTEGER,
                'networks' => ArrayParameterType::INTEGER,
            ]
        );

        $payload = [
            'network' => $networkCode,
            'xlm_price_usd' => is_string($xlmPriceUsd) && trim($xlmPriceUsd) !== '' ? trim($xlmPriceUsd) : null,
            'xlm_volume24h' => $this->normalizeXlmVolume($tradeRow['xlm_volume_24h'] ?? null),
            'total_trades24h' => (string) $this->toInt($tradeRow['total_trades_24h'] ?? null, 0),
            'active_assets24h' => $this->toInt($tradeRow['active_assets_24h'] ?? null, 0),
            'tracked_assets' => max(0, $trackedAssets),
            'total_accounts' => max(0, $totalAccounts),
            'total_contracts' => max(0, $totalContracts),
            'recorded_at' => $now->format('Y-m-d H:i:s'),
        ];

        if (!$dryRun) {
            $this->connection->executeStatement(
                <<<SQL
INSERT INTO market_overview_snapshot
    (network, xlm_price_usd, xlm_volume24h, total_trades24h, active_assets24h, tracked_assets, total_accounts, total_contracts, recorded_at)
VALUES
    (:network, :xlm_price_usd, :xlm_volume24h, :total_trades24h, :active_assets24h, :tracked_assets, :total_accounts, :total_contracts, :recorded_at)
ON DUPLICATE KEY UPDATE
    xlm_price_usd = VALUES(xlm_price_usd),
    xlm_volume24h = VALUES(xlm_volume24h),
    total_trades24h = VALUES(total_trades24h),
    active_assets24h = VALUES(active_assets24h),
    tracked_assets = VALUES(tracked_assets),
    total_accounts = VALUES(total_accounts),
    total_contracts = VALUES(total_contracts),
    recorded_at = VALUES(recorded_at)
SQL,
                $payload,
                [
                    'network' => ParameterType::INTEGER,
                    'active_assets24h' => ParameterType::INTEGER,
                    'tracked_assets' => ParameterType::INTEGER,
                    'total_accounts' => ParameterType::INTEGER,
                    'total_contracts' => ParameterType::INTEGER,
                ]
            );
        }

        $io->table(
            ['Metric', 'Value'],
            [
                ['network', $network],
                ['xlm_price_usd', (string) ($payload['xlm_price_usd'] ?? 'null')],
                ['xlm_volume24h', (string) ($payload['xlm_volume24h'] ?? 'null')],
                ['total_trades24h', (string) $payload['total_trades24h']],
                ['active_assets24h', (string) $payload['active_assets24h']],
                ['tracked_assets', (string) $payload['tracked_assets']],
                ['total_accounts', (string) $payload['total_accounts']],
                ['total_contracts', (string) $payload['total_contracts']],
                ['dry_run', $dryRun ? '1' : '0'],
            ]
        );
        $io->success($dryRun ? 'Dry-run completed.' : 'Market overview snapshot synced.');

        return Command::SUCCESS;
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

    private function toInt(mixed $value, ?int $default = null): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?[0-9]+$/', trim($value)) === 1) {
            return (int) trim($value);
        }
        if (is_float($value)) {
            return (int) round($value);
        }

        return $default;
    }

    private function normalizeDecimal(mixed $value, int $scale): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric((string) $value)) {
            return null;
        }

        return number_format((float) $value, $scale, '.', '');
    }

    private function normalizeXlmVolume(mixed $rawVolume): ?string
    {
        if ($rawVolume === null || $rawVolume === '') {
            return null;
        }

        $raw = trim((string) $rawVolume);
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }

        if (function_exists('bcdiv')) {
            return bcdiv($raw, '10000000', 7);
        }

        return number_format(((float) $raw) / 10000000, 7, '.', '');
    }
}
