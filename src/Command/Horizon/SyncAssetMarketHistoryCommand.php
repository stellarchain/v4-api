<?php

declare(strict_types=1);

namespace App\Command\Horizon;

use App\Command\Support\NetworkOptionTrait;
use App\Service\Statistics\AssetMarketHistorySyncService;
use App\Service\Stellar\StellarNetworkResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:horizon:sync-asset-market-history',
    description: 'Extract historical asset/XLM market buckets and active asset state snapshots from the currently ingested Horizon DB chunk.',
)]
final class SyncAssetMarketHistoryCommand extends Command
{
    use NetworkOptionTrait;

    public function __construct(
        private readonly AssetMarketHistorySyncService $assetMarketHistorySyncService,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addNetworkOption('Network to sync (mainnet|testnet|futurenet)', 'testnet')
            ->addOption('start-ledger', null, InputOption::VALUE_REQUIRED, 'Inclusive start ledger.')
            ->addOption('end-ledger', null, InputOption::VALUE_REQUIRED, 'Inclusive end ledger.')
            ->addOption('bucket-minutes', null, InputOption::VALUE_REQUIRED, 'Bucket size in minutes.', 5)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Read and aggregate without writing rows.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $network = $this->resolveNetworkOption($input, $this->stellarNetworkResolver);
        $startLedger = $this->parsePositiveInt($input->getOption('start-ledger'));
        $endLedger = $this->parsePositiveInt($input->getOption('end-ledger'));
        $bucketMinutes = $this->parsePositiveInt($input->getOption('bucket-minutes'));
        $dryRun = (bool) $input->getOption('dry-run');

        if ($startLedger === null || $endLedger === null) {
            $io->error('--start-ledger and --end-ledger must be positive integers.');

            return Command::FAILURE;
        }
        if ($bucketMinutes === null) {
            $io->error('--bucket-minutes must be a positive integer.');

            return Command::FAILURE;
        }

        try {
            $result = $this->assetMarketHistorySyncService->sync($network, $startLedger, $endLedger, $bucketMinutes, $dryRun);
        } catch (\Throwable $exception) {
            $io->error(sprintf('Asset market history sync failed: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        $io->table(
            ['Metric', 'Value'],
            [
                ['network', $result['network']],
                ['start_ledger', (string) $result['start_ledger']],
                ['end_ledger', (string) $result['end_ledger']],
                ['bucket_minutes', (string) $result['bucket_minutes']],
                ['market_points', (string) $result['market_points']],
                ['asset_state_snapshots', (string) $result['asset_state_snapshots']],
                ['rows_written', (string) $result['rows_written']],
                ['dry_run', $result['dry_run'] ? '1' : '0'],
            ]
        );
        $io->success($dryRun ? 'Asset market history dry-run completed.' : 'Asset market history synced.');

        return Command::SUCCESS;
    }

    private function parsePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^[0-9]+$/', trim($value)) === 1) {
            $parsed = (int) trim($value);

            return $parsed > 0 ? $parsed : null;
        }

        return null;
    }
}
