<?php

declare(strict_types=1);

namespace App\Command\Horizon;

use App\Command\Support\NetworkOptionTrait;
use App\Service\Statistics\AccountActivitySummarySyncService;
use App\Service\Stellar\StellarNetworkResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:horizon:sync-account-activity-summary',
    description: 'Extract compact account activity summaries from the currently ingested Horizon DB chunk.',
)]
final class SyncAccountActivitySummaryCommand extends Command
{
    use NetworkOptionTrait;

    public function __construct(
        private readonly AccountActivitySummarySyncService $accountActivitySummarySyncService,
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
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Read and aggregate without writing rows.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $network = $this->resolveNetworkOption($input, $this->stellarNetworkResolver);
        $startLedger = $this->parsePositiveInt($input->getOption('start-ledger'));
        $endLedger = $this->parsePositiveInt($input->getOption('end-ledger'));
        $dryRun = (bool) $input->getOption('dry-run');

        if ($startLedger === null || $endLedger === null) {
            $io->error('--start-ledger and --end-ledger must be positive integers.');

            return Command::FAILURE;
        }

        try {
            $result = $this->accountActivitySummarySyncService->sync($network, $startLedger, $endLedger, $dryRun);
        } catch (\Throwable $exception) {
            $io->error(sprintf('Account activity summary sync failed: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        $io->table(
            ['Metric', 'Value'],
            [
                ['network', $result['network']],
                ['start_ledger', (string) $result['start_ledger']],
                ['end_ledger', (string) $result['end_ledger']],
                ['accounts', (string) $result['accounts']],
                ['rows_written', (string) $result['rows_written']],
                ['dry_run', $result['dry_run'] ? '1' : '0'],
            ]
        );
        $io->success($dryRun ? 'Account activity summary dry-run completed.' : 'Account activity summary synced.');

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
