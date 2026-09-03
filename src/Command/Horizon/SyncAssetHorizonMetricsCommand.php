<?php

namespace App\Command\Horizon;

use App\Command\Support\NetworkOptionTrait;
use App\Service\Stellar\HorizonAssetSupplyCalculator;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\StellarSDK;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:assets:sync-horizon-metrics',
    description: 'Sync asset metrics from Horizon (assets + trade_aggregations), compute custom rank, and store snapshots.',
)]
final class SyncAssetHorizonMetricsCommand extends Command
{
    use NetworkOptionTrait;

    /**
     * Horizon-supported trade_aggregations resolutions in minutes.
     *
     * @var list<int>
     */
    private const SUPPORTED_RESOLUTION_MINUTES = [1, 5, 60, 1440, 10080];

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private readonly Connection $connection,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
        private readonly HorizonAssetSupplyCalculator $assetSupplyCalculator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addNetworkOption('Horizon network (mainnet|testnet|futurenet)', 'testnet')
            ->addOption('lookback-hours', null, InputOption::VALUE_REQUIRED, 'Trade aggregation lookback in hours', 24)
            ->addOption('resolution-minutes', null, InputOption::VALUE_REQUIRED, 'Trade aggregation resolution in minutes', 60)
            ->addOption('limit-assets', null, InputOption::VALUE_REQUIRED, 'Limit number of local assets to process')
            ->addOption('top', null, InputOption::VALUE_REQUIRED, 'Persist only top ranked assets', 150)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compute metrics without writing to DB');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $network = $this->resolveNetworkOption($input, $this->stellarNetworkResolver);
        $lookbackHours = (int) $input->getOption('lookback-hours');
        $resolutionMinutes = (int) $input->getOption('resolution-minutes');
        $limitAssetsRaw = $input->getOption('limit-assets');
        $limitAssets = ($limitAssetsRaw === null || $limitAssetsRaw === '') ? null : (int) $limitAssetsRaw;
        $top = (int) $input->getOption('top');
        $dryRun = (bool) $input->getOption('dry-run');

        if ($lookbackHours < 1) {
            $io->error('--lookback-hours must be >= 1.');
            return Command::FAILURE;
        }
        if (!in_array($resolutionMinutes, self::SUPPORTED_RESOLUTION_MINUTES, true)) {
            $io->error(sprintf(
                '--resolution-minutes must be one of: %s.',
                implode(', ', self::SUPPORTED_RESOLUTION_MINUTES)
            ));
            return Command::FAILURE;
        }
        if ($limitAssets !== null && $limitAssets < 1) {
            $io->error('--limit-assets must be >= 1.');
            return Command::FAILURE;
        }
        if ($top < 1) {
            $io->error('--top must be >= 1.');
            return Command::FAILURE;
        }

        $networkCode = $this->resolveNetworkCodeOption($input, $this->stellarNetworkResolver);
        $assets = $this->loadAssets($networkCode, $limitAssets);
        if ($assets === []) {
            $io->warning('No assets found in local DB.');
            return Command::SUCCESS;
        }

        $sdk = new StellarSDK($this->stellarNetworkResolver->resolveHorizonUrl($network));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $start = $now->modify(sprintf('-%d hours', $lookbackHours));
        $resolutionMs = (string) ($resolutionMinutes * 60 * 1000);
        $startMs = (string) ($start->getTimestamp() * 1000);
        $endMs = (string) ($now->getTimestamp() * 1000);

        $io->writeln(sprintf(
            'Network=%s, assets=%d, lookback=%dh, resolution=%dm, top=%d',
            $network,
            count($assets),
            $lookbackHours,
            $resolutionMinutes,
            $top
        ));

        $computed = [];
        $failed = 0;
        $skipped = 0;
        $failureSamples = [];
        $io->progressStart(count($assets));

        foreach ($assets as $asset) {
            try {
                $assetKey = (string) ($asset['asset_key'] ?? '');
                $isNative = (bool) $asset['is_native'] || $assetKey === 'XLM-native';
                if ($isNative) {
                    $skipped++;
                    $io->progressAdvance();
                    continue;
                }

                $code = (string) $asset['code'];
                $issuer = is_string($asset['issuer'] ?? null) ? trim((string) $asset['issuer']) : '';
                if ($issuer === '') {
                    $skipped++;
                    $io->progressAdvance();
                    continue;
                }
                $assetPage = $sdk->assets()
                    ->forAssetCode($code)
                    ->forAssetIssuer($issuer)
                    ->limit(1)
                    ->execute();

                $rows = $assetPage->getAssets()->toArray();
                if ($rows === []) {
                    $skipped++;
                    $io->progressAdvance();
                    continue;
                }

                $assetResponse = $rows[0];
                $accountStats = $assetResponse->getAccounts();
                $balanceStats = $assetResponse->getBalances();

                $baseAsset = Asset::createNonNativeAsset($code, $issuer);
                $tradePage = $sdk->tradeAggregations()
                    ->forBaseAsset($baseAsset)
                    ->forCounterAsset(Asset::native())
                    ->forStartTime($startMs)
                    ->forEndTime($endMs)
                    ->forResolution($resolutionMs)
                    ->order('asc')
                    ->limit(200)
                    ->execute();

                $tradeCount = 0;
                $baseVolume = '0';
                $counterVolume = '0';
                $lastClose = null;
                foreach ($tradePage->getTradeAggregations() as $aggregation) {
                    $tradeCount += (int) $aggregation->getTradeCount();
                    $baseVolume = bcadd($baseVolume, (string) $aggregation->getBaseVolume(), 7);
                    $counterVolume = bcadd($counterVolume, (string) $aggregation->getCounterVolume(), 7);
                    $lastClose = (string) $aggregation->getClosePrice();
                }

                $accountsAuthorized = $accountStats->getAuthorized();
                $accountsMaintain = $accountStats->getAuthorizedToMaintainLiabilities();
                $accountsUnauthorized = $accountStats->getUnauthorized();
                $trustlinesTotal = $accountsAuthorized + $accountsMaintain + $accountsUnauthorized;
                $supply = $this->assetSupplyCalculator->calculateStroopsFromDecimalUnits(
                    [
                        'authorized' => $balanceStats->getAuthorized(),
                        'authorized_to_maintain_liabilities' => $balanceStats->getAuthorizedToMaintainLiabilities(),
                        'unauthorized' => $balanceStats->getUnauthorized(),
                        'claimable_balances' => $assetResponse->getClaimableBalancesAmount(),
                        'liquidity_pools' => $assetResponse->getLiquidityPoolsAmount(),
                    ],
                    $assetResponse->getContractsAmount()
                );

                $ageDays = $this->ageDaysFromCreatedAt($asset['created_at'] ?? null, $now);
                $numLiquidityPools = $assetResponse->getNumLiquidityPools();
                $numClaimableBalances = $assetResponse->getNumClaimableBalances();
                $numContracts = $assetResponse->getNumContracts() ?? 0;

                $ratingAge = $this->scoreAge($ageDays);
                $ratingActivity = $this->scoreByLog($tradeCount, 2.0);
                $ratingTrustlines = $this->scoreByLog($trustlinesTotal, 2.5);
                $ratingLiquidity = $this->scoreByLog((float) $counterVolume, 1.8);
                $volume7d = $this->projectVolume7d((float) $counterVolume, $lookbackHours);
                $ratingVolume7d = $this->scoreByLog($volume7d, 1.8);
                $ratingInterop = $this->scoreInterop($numLiquidityPools, $numClaimableBalances, $numContracts);

                $score = $this->computeRankScore(
                    $ratingAge,
                    $ratingActivity,
                    $ratingTrustlines,
                    $ratingLiquidity,
                    $ratingVolume7d,
                    $ratingInterop
                );
                $ratingAverage = $this->computeRatingAverage(
                    $ratingAge,
                    $ratingActivity,
                    $ratingTrustlines,
                    $ratingLiquidity,
                    $ratingVolume7d,
                    $ratingInterop
                );

                $computed[] = [
                    'asset_id' => (int) $asset['id'],
                    'price' => $lastClose,
                    'supply' => $supply,
                    'trades' => $tradeCount,
                    'traded_amount' => $this->decimalToBigIntOrNull($counterVolume),
                    'trustlines_total' => $trustlinesTotal,
                    'trustlines_authorized' => $accountsAuthorized,
                    'trustlines_funded' => $accountsAuthorized + $accountsMaintain,
                    'score' => $score,
                    'rating_average' => number_format($ratingAverage, 2, '.', ''),
                    'rating_age' => number_format($ratingAge, 2, '.', ''),
                    'rating_activity' => number_format($ratingActivity, 2, '.', ''),
                    'rating_trustlines' => number_format($ratingTrustlines, 2, '.', ''),
                    'rating_liquidity' => number_format($ratingLiquidity, 2, '.', ''),
                    'rating_volume7d' => number_format($ratingVolume7d, 2, '.', ''),
                    'rating_interop' => number_format($ratingInterop, 2, '.', ''),
                    'meta_trade_count' => $tradeCount,
                    'meta_counter_volume' => $counterVolume,
                ];
            } catch (\Throwable $exception) {
                $failed++;
                if (count($failureSamples) < 10) {
                    $failureSamples[] = sprintf(
                        '%s: %s',
                        (string) ($asset['asset_key'] ?? 'unknown_asset'),
                        $exception->getMessage()
                    );
                }
            }

            $io->progressAdvance();
        }

        $io->progressFinish();
        $io->newLine();

        usort($computed, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $rank = 0;
        $lastScore = null;
        foreach ($computed as $index => $row) {
            if ($lastScore === null || abs($row['score'] - $lastScore) > 0.000000001) {
                $rank = $index + 1;
                $lastScore = $row['score'];
            }
            $computed[$index]['rank_position'] = $rank;
        }

        $persistRows = array_slice($computed, 0, $top);

        $written = 0;
        if (!$dryRun) {
            $this->connection->beginTransaction();
            try {
                foreach ($persistRows as $row) {
                    $this->insertAssetStatistic($row, $now);
                    $this->insertMetricHistory((int) $row['asset_id'], 'horizon_asset_rank', 'rank_score', $this->normalizeDecimal($row['score']), null, $now);
                    $this->insertMetricHistory((int) $row['asset_id'], 'horizon_asset_rank', 'rank_position', null, (string) $row['rank_position'], $now);
                    $this->insertMetricHistory((int) $row['asset_id'], 'horizon_asset_rank', sprintf('trade_count_%dh', $lookbackHours), (string) $row['meta_trade_count'], null, $now);
                    $this->insertMetricHistory((int) $row['asset_id'], 'horizon_asset_rank', sprintf('counter_volume_xlm_%dh', $lookbackHours), $this->normalizeDecimal((float) $row['meta_counter_volume']), null, $now);
                    $this->connection->executeStatement(
                        'UPDATE asset SET updated_at = :updated_at, rating_average = :rating_average WHERE id = :id',
                        [
                            'updated_at' => $now->format('Y-m-d H:i:s'),
                            'rating_average' => (string) $row['rating_average'],
                            'id' => (int) $row['asset_id'],
                        ],
                        ['id' => ParameterType::INTEGER]
                    );
                    $written++;
                }
                $this->connection->commit();
            } catch (\Throwable $exception) {
                $this->connection->rollBack();
                throw $exception;
            }
        }

        $io->table(
            ['Metric', 'Value'],
            [
                ['assets_total', (string) count($assets)],
                ['assets_computed', (string) count($computed)],
                ['assets_persisted_top', (string) count($persistRows)],
                ['assets_skipped', (string) $skipped],
                ['assets_failed', (string) $failed],
                ['rows_written', $dryRun ? '0 (dry-run)' : (string) $written],
            ]
        );
        if ($failureSamples !== []) {
            $io->warning('Sample errors:');
            foreach ($failureSamples as $sample) {
                $io->writeln(sprintf('- %s', $sample));
            }
        }
        if (!$dryRun && count($computed) === 0 && $failed > 0) {
            $io->error('No asset metrics were computed. Check errors above.');
            return Command::FAILURE;
        }

        $io->success($dryRun ? 'Dry-run completed.' : 'Horizon asset metrics sync completed.');

        return Command::SUCCESS;
    }

    /**
     * @return list<array{id:int,asset_key:string,code:string,issuer:?string,is_native:int,created_at:?string}>
     */
    private function loadAssets(int $networkCode, ?int $limit): array
    {
        $sql = 'SELECT id, asset_key, code, issuer, is_native, created_at FROM asset WHERE network = :network ORDER BY id ASC';
        if ($limit !== null) {
            $sql .= ' LIMIT :limit';
            return $this->connection->fetchAllAssociative(
                $sql,
                [
                    'network' => $networkCode,
                    'limit' => $limit,
                ],
                [
                    'network' => ParameterType::INTEGER,
                    'limit' => ParameterType::INTEGER,
                ]
            );
        }

        return $this->connection->fetchAllAssociative(
            $sql,
            ['network' => $networkCode],
            ['network' => ParameterType::INTEGER]
        );
    }

    private function computeRankScore(
        float $ratingAge,
        float $ratingActivity,
        float $ratingTrustlines,
        float $ratingLiquidity,
        float $ratingVolume7d,
        float $ratingInterop
    ): float
    {
        return
            ($ratingAge * 0.10) +
            ($ratingActivity * 0.25) +
            ($ratingTrustlines * 0.20) +
            ($ratingLiquidity * 0.20) +
            ($ratingVolume7d * 0.20) +
            ($ratingInterop * 0.05);
    }

    private function computeRatingAverage(
        float $ratingAge,
        float $ratingActivity,
        float $ratingTrustlines,
        float $ratingLiquidity,
        float $ratingVolume7d,
        float $ratingInterop
    ): float {
        return ($ratingAge + $ratingActivity + $ratingTrustlines + $ratingLiquidity + $ratingVolume7d + $ratingInterop) / 6.0;
    }

    private function scoreByLog(int|float $value, float $multiplier): float
    {
        $numeric = max(0.0, (float) $value);
        $score = log10(1.0 + $numeric) * $multiplier;

        return $this->clamp10($score);
    }

    private function scoreAge(int $ageDays): float
    {
        return $this->clamp10(($ageDays / 365.0) * 10.0);
    }

    private function scoreInterop(int $numLiquidityPools, int $numClaimableBalances, int $numContracts): float
    {
        $signal = $numLiquidityPools + $numContracts + (int) floor($numClaimableBalances / 10);
        return $this->scoreByLog($signal, 3.0);
    }

    private function projectVolume7d(float $counterVolume, int $lookbackHours): float
    {
        if ($lookbackHours <= 0) {
            return max(0.0, $counterVolume);
        }

        return max(0.0, $counterVolume) * (168.0 / (float) $lookbackHours);
    }

    private function ageDaysFromCreatedAt(mixed $createdAt, \DateTimeImmutable $now): int
    {
        if (!is_string($createdAt) || trim($createdAt) === '') {
            return 0;
        }

        try {
            $created = new \DateTimeImmutable($createdAt, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return 0;
        }

        $seconds = max(0, $now->getTimestamp() - $created->getTimestamp());
        return (int) floor($seconds / 86400);
    }

    private function clamp10(float $value): float
    {
        if ($value < 0.0) {
            return 0.0;
        }
        if ($value > 10.0) {
            return 10.0;
        }
        return $value;
    }

    private function insertAssetStatistic(array $row, \DateTimeImmutable $recordedAt): void
    {
        $this->connection->executeStatement(
            <<<SQL
INSERT INTO asset_statistic (
    asset_id, price, supply, trades, traded_amount, payments, payments_amount,
    trustlines_total, trustlines_authorized, trustlines_funded, rating_average, recorded_at
)
VALUES (
    :asset_id, :price, :supply, :trades, :traded_amount, NULL, NULL,
    :trustlines_total, :trustlines_authorized, :trustlines_funded, :rating_average, :recorded_at
)
SQL,
            [
                'asset_id' => (int) $row['asset_id'],
                'price' => $row['price'],
                'supply' => $row['supply'],
                'trades' => (int) $row['trades'],
                'traded_amount' => $row['traded_amount'],
                'trustlines_total' => (int) $row['trustlines_total'],
                'trustlines_authorized' => (int) $row['trustlines_authorized'],
                'trustlines_funded' => (int) $row['trustlines_funded'],
                'rating_average' => (string) $row['rating_average'],
                'recorded_at' => $recordedAt->format('Y-m-d H:i:s'),
            ],
            [
                'asset_id' => ParameterType::INTEGER,
                'trades' => ParameterType::INTEGER,
                'traded_amount' => $row['traded_amount'] === null ? ParameterType::NULL : ParameterType::INTEGER,
                'trustlines_total' => ParameterType::INTEGER,
                'trustlines_authorized' => ParameterType::INTEGER,
                'trustlines_funded' => ParameterType::INTEGER,
            ]
        );
    }

    private function insertMetricHistory(
        int $assetId,
        string $source,
        string $metricKey,
        ?string $valueDecimal,
        ?string $valueText,
        \DateTimeImmutable $recordedAt
    ): void {
        $this->connection->executeStatement(
            <<<SQL
INSERT INTO asset_metric_history (asset_id, source, metric_key, value_decimal, value_text, recorded_at)
VALUES (:asset_id, :source, :metric_key, :value_decimal, :value_text, :recorded_at)
SQL,
            [
                'asset_id' => $assetId,
                'source' => $source,
                'metric_key' => $metricKey,
                'value_decimal' => $valueDecimal,
                'value_text' => $valueText,
                'recorded_at' => $recordedAt->format('Y-m-d H:i:s'),
            ],
            [
                'asset_id' => ParameterType::INTEGER,
                'value_decimal' => $valueDecimal === null ? ParameterType::NULL : ParameterType::STRING,
                'value_text' => $valueText === null ? ParameterType::NULL : ParameterType::STRING,
            ]
        );
    }

    private function decimalToBigIntOrNull(string $decimal): ?int
    {
        $normalized = trim($decimal);
        if ($normalized === '') {
            return null;
        }

        $integerPart = explode('.', ltrim($normalized, '+-'), 2)[0];
        if ($integerPart === '') {
            $integerPart = '0';
        }

        if (strlen($integerPart) > 18) {
            return null;
        }

        return (int) floor((float) $normalized);
    }

    private function normalizeDecimal(float $value): string
    {
        $normalized = sprintf('%.14F', $value);
        return rtrim(rtrim($normalized, '0'), '.');
    }
}
