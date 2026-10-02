<?php

declare(strict_types=1);

namespace App\Command\Statistics;

use App\Command\Support\NetworkOptionTrait;
use App\Service\Trace\PaymentFlowAssetSideSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:statistics:sync-payment-flow-asset-sides',
    description: 'Preview or explicitly rebuild a bounded asset-side read-model range from indexed payment events.',
)]
final class SyncPaymentFlowAssetSidesCommand extends Command
{
    use NetworkOptionTrait;

    public function __construct(
        private readonly PaymentFlowAssetSideSyncService $syncService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addNetworkOption('Network to inspect (mainnet|testnet|futurenet)', 'testnet')
            ->addOption('start-ledger', null, InputOption::VALUE_REQUIRED, 'Inclusive start ledger.')
            ->addOption('end-ledger', null, InputOption::VALUE_REQUIRED, 'Inclusive end ledger (at most 64 ledgers per run).')
            ->addOption('completed-through-ledger', null, InputOption::VALUE_REQUIRED, 'Verified source checkpoint; required with --apply.')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Replace derived rows and coverage in one transaction. Default is read-only preview.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $startLedger = $this->parsePositiveInt($input->getOption('start-ledger'));
        $endLedger = $this->parsePositiveInt($input->getOption('end-ledger'));
        $completedThroughLedger = $this->parsePositiveInt($input->getOption('completed-through-ledger'));
        $apply = (bool) $input->getOption('apply');

        if ($startLedger === null || $endLedger === null) {
            $io->error('--start-ledger and --end-ledger must be positive 32-bit integers.');

            return Command::FAILURE;
        }
        if ($input->getOption('completed-through-ledger') !== null && $completedThroughLedger === null) {
            $io->error('--completed-through-ledger must be a positive 32-bit integer.');

            return Command::FAILURE;
        }

        try {
            $result = $this->syncService->sync(
                (string) $input->getOption('network'),
                $startLedger,
                $endLedger,
                $apply,
                $completedThroughLedger,
            );
        } catch (\Throwable $exception) {
            $io->error(sprintf('Asset-side sync failed: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        $io->table(['Metric', 'Value'], [
            ['network', $result['network']],
            ['start_ledger', (string) $result['start_ledger']],
            ['end_ledger', (string) $result['end_ledger']],
            ['source_events', (string) $result['source_events']],
            ['source_rows', (string) $result['source_rows']],
            ['destination_rows', (string) $result['destination_rows']],
            ['excluded_source', (string) $result['excluded_source']],
            ['excluded_destination', (string) $result['excluded_destination']],
            ['rows_replaced', (string) $result['rows_replaced']],
            ['rows_written', (string) $result['rows_written']],
            ['dry_run', $result['dry_run'] ? '1' : '0'],
        ]);
        $io->success($apply ? 'Asset-side range rebuilt.' : 'Read-only asset-side preview completed.');

        return Command::SUCCESS;
    }

    private function parsePositiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 && $value <= 2147483647 ? $value : null;
        }
        if (!is_string($value) || preg_match('/^[0-9]+$/D', trim($value)) !== 1) {
            return null;
        }

        $parsed = (int) trim($value);

        return $parsed > 0 && $parsed <= 2147483647 ? $parsed : null;
    }
}
