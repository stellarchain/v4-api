<?php

declare(strict_types=1);

namespace App\Command\Horizon;

use App\Command\Support\NetworkOptionTrait;
use App\Service\Stellar\StellarNetworkResolver;
use App\Service\Trace\PaymentFlowEventSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:horizon:sync-payment-flow-events',
    description: 'Extract compact payment flow events from the currently ingested Horizon DB chunk into the statistics database.',
)]
final class SyncPaymentFlowEventsCommand extends Command
{
    use NetworkOptionTrait;

    private const DEFAULT_BATCH_SIZE = 5000;

    public function __construct(
        private readonly PaymentFlowEventSyncService $paymentFlowEventSyncService,
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
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Rows batch size from Horizon DB.', (string) self::DEFAULT_BATCH_SIZE)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Read and parse flow events without writing them locally.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $network = $this->resolveNetworkOption($input, $this->stellarNetworkResolver);
        $startLedger = $this->parsePositiveInt($input->getOption('start-ledger'));
        $endLedger = $this->parsePositiveInt($input->getOption('end-ledger'));
        $batchSize = $this->parsePositiveInt($input->getOption('batch-size')) ?? self::DEFAULT_BATCH_SIZE;
        $dryRun = (bool) $input->getOption('dry-run');

        if ($startLedger === null || $endLedger === null) {
            $io->error('--start-ledger and --end-ledger must be positive integers.');

            return Command::FAILURE;
        }
        if ($startLedger > $endLedger) {
            [$startLedger, $endLedger] = [$endLedger, $startLedger];
        }

        try {
            $result = $this->paymentFlowEventSyncService->sync($network, $startLedger, $endLedger, $batchSize, $dryRun);
        } catch (\Throwable $exception) {
            $io->error(sprintf('Payment flow event sync failed: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        $io->table(
            ['Metric', 'Value'],
            [
                ['network', $result['network']],
                ['start_ledger', (string) $result['start_ledger']],
                ['end_ledger', (string) $result['end_ledger']],
                ['batch_size', (string) $result['batch_size']],
                ['operations_scanned', (string) $result['operations_scanned']],
                ['events_written', (string) $result['events_written']],
                ['rows_written', (string) $result['rows_written']],
                ['dry_run', $result['dry_run'] ? '1' : '0'],
            ]
        );
        $io->success($dryRun ? 'Payment flow event dry-run completed.' : 'Payment flow events synced.');

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
