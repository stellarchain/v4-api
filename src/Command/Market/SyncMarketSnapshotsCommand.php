<?php

declare(strict_types=1);

namespace App\Command\Market;

use App\Command\Support\NetworkOptionTrait;
use App\Service\Market\MarketSnapshotSyncService;
use App\Service\Stellar\StellarNetworkResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:market:sync-snapshots',
    description: 'Build and persist market snapshot metrics from Horizon DB using direct SQL queries.',
)]
final class SyncMarketSnapshotsCommand extends Command
{
    use NetworkOptionTrait;

    public function __construct(
        private readonly MarketSnapshotSyncService $marketSnapshotSyncService,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addNetworkOption('Network to sync (mainnet|testnet|futurenet)', 'testnet')
            ->addOption('top', null, InputOption::VALUE_REQUIRED, 'Persist only top N ranked assets (0 = all)', 1000)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compute metrics without writing to local DB');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $network = $this->resolveNetworkOption($input, $this->stellarNetworkResolver);
        $dryRun = (bool) $input->getOption('dry-run');
        $topRaw = (int) $input->getOption('top');
        $top = $topRaw > 0 ? $topRaw : null;

        try {
            $result = $this->marketSnapshotSyncService->sync($network, $dryRun, $top);
        } catch (\Throwable $exception) {
            $io->error(sprintf('Market snapshot sync failed: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        $io->table(
            ['Metric', 'Value'],
            [
                ['network', $result['network']],
                ['local_assets', (string) $result['local_assets']],
                ['horizon_assets', (string) $result['horizon_assets']],
                ['mapped_assets', (string) $result['mapped_assets']],
                ['persisted_assets', (string) $result['persisted_assets']],
                ['rows_written', (string) $result['rows_written']],
                ['dry_run', $result['dry_run'] ? '1' : '0'],
            ]
        );
        $io->success($dryRun ? 'Dry-run completed.' : 'Market snapshots synced.');

        return Command::SUCCESS;
    }
}
