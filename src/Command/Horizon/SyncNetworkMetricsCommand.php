<?php

declare(strict_types=1);

namespace App\Command\Horizon;

use App\Command\Support\NetworkOptionTrait;
use App\Service\Statistics\NetworkMetricSyncServiceInterface;
use App\Service\Stellar\StellarNetworkResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:horizon:sync-network-metrics',
    description: 'Build paginated blockchain/network metric points directly from the currently ingested Horizon DB chunk.',
)]
final class SyncNetworkMetricsCommand extends Command
{
    use NetworkOptionTrait;

    public function __construct(
        private readonly NetworkMetricSyncServiceInterface $networkMetricSyncService,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addNetworkOption('Network to sync (mainnet|testnet|futurenet)', 'testnet')
            ->addOption('bucket-minutes', null, InputOption::VALUE_REQUIRED, 'Bucket size in minutes.', 10)
            ->addOption('start-ledger', null, InputOption::VALUE_REQUIRED, 'Optional inclusive start ledger.')
            ->addOption('end-ledger', null, InputOption::VALUE_REQUIRED, 'Optional inclusive end ledger.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compute metric points without writing them locally');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $network = $this->resolveNetworkOption($input, $this->stellarNetworkResolver);
        $bucketMinutes = $this->parsePositiveInt($input->getOption('bucket-minutes'));
        $startLedger = $this->parseNullablePositiveInt($input->getOption('start-ledger'));
        $endLedger = $this->parseNullablePositiveInt($input->getOption('end-ledger'));
        $dryRun = (bool) $input->getOption('dry-run');

        if ($bucketMinutes === null) {
            $io->error('--bucket-minutes must be a positive integer.');

            return Command::FAILURE;
        }
        if (($startLedger === null) !== ($endLedger === null)) {
            $io->error('Use --start-ledger and --end-ledger together.');

            return Command::FAILURE;
        }

        try {
            $result = $this->networkMetricSyncService->sync($network, $bucketMinutes, $startLedger, $endLedger, $dryRun);
        } catch (\Throwable $exception) {
            $io->error(sprintf('Network metrics sync failed: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        $io->table(
            ['Metric', 'Value'],
            [
                ['network', $result['network']],
                ['start_ledger', (string) $result['start_ledger']],
                ['end_ledger', (string) $result['end_ledger']],
                ['bucket_minutes', (string) $result['bucket_minutes']],
                ['buckets', (string) $result['buckets']],
                ['metrics_written', (string) $result['metrics_written']],
                ['rows_written', (string) $result['rows_written']],
                ['dry_run', $result['dry_run'] ? '1' : '0'],
            ]
        );
        $io->success($dryRun ? 'Dry-run completed.' : 'Network metric points synced.');

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

    private function parseNullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->parsePositiveInt($value);
    }
}
