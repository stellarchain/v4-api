<?php

declare(strict_types=1);

namespace App\Command\Horizon;

use App\Command\Support\NetworkOptionTrait;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:horizon:contracts:scan-cron',
    description: 'Incremental cron for Horizon DB contract scan by ledger cursor.',
)]
final class RunHorizonContractScanCronCommand extends Command
{
    use NetworkOptionTrait;

    private const DEFAULT_LOOKBACK_LEDGERS = 30000;
    private const DEFAULT_MAX_LEDGERS_PER_RUN = 50000;
    private const DEFAULT_BATCH_SIZE = 5000;

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addNetworkOption('mainnet|testnet|futurenet', 'mainnet')
            ->addOption('lookback-ledgers', null, InputOption::VALUE_REQUIRED, 'Initial lookback when cursor is empty.', (string) self::DEFAULT_LOOKBACK_LEDGERS)
            ->addOption('max-ledgers-per-run', null, InputOption::VALUE_REQUIRED, 'Maximum ledgers to scan in one run.', (string) self::DEFAULT_MAX_LEDGERS_PER_RUN)
            ->addOption('start-ledger', null, InputOption::VALUE_REQUIRED, 'Override inclusive start ledger (use with --end-ledger)')
            ->addOption('end-ledger', null, InputOption::VALUE_REQUIRED, 'Override inclusive end ledger (use with --start-ledger)')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Rows batch size from Horizon DB.', (string) self::DEFAULT_BATCH_SIZE)
            ->addOption('derive-events', null, InputOption::VALUE_NONE, 'Derive transfer/mint/burn events from invoke args.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Run without writes.')
            ->addOption('reset-cursor', null, InputOption::VALUE_NONE, 'Reset incremental ledger cursor before run.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $network = $this->resolveNetworkOption($input, $this->stellarNetworkResolver);
        $dryRun = (bool) $input->getOption('dry-run');
        $deriveEvents = (bool) $input->getOption('derive-events');
        $lookbackLedgers = $this->parsePositiveIntOption($input->getOption('lookback-ledgers'));
        $maxLedgersPerRun = $this->parsePositiveIntOption($input->getOption('max-ledgers-per-run'));
        $batchSize = $this->parsePositiveIntOption($input->getOption('batch-size'));

        if ($lookbackLedgers === null || $maxLedgersPerRun === null || $batchSize === null) {
            $io->error('--lookback-ledgers, --max-ledgers-per-run and --batch-size must be positive integers.');
            return Command::FAILURE;
        }

        if ((bool) $input->getOption('reset-cursor')) {
            $this->deleteCursor($this->cursorKey($network));
        }

        $latestLedger = $this->loadLatestLedgerFromHorizonDb($network);
        if ($latestLedger === null || $latestLedger < 1) {
            $io->error('Could not load latest ledger from Horizon DB.');
            return Command::FAILURE;
        }

        [$startLedger, $endLedger] = $this->resolveLedgerRange(
            $input,
            $network,
            $latestLedger,
            $lookbackLedgers,
            $maxLedgersPerRun,
            $io,
        );
        if ($startLedger === null || $endLedger === null) {
            return Command::FAILURE;
        }

        if ($startLedger > $latestLedger) {
            $io->success(sprintf(
                'No new ledgers to scan. cursor=%d latest=%d',
                $startLedger - 1,
                $latestLedger
            ));
            return Command::SUCCESS;
        }

        $io->writeln(sprintf(
            'network=%s latest=%d scan=%d..%d dry_run=%d derive_events=%d batch_size=%d',
            $network,
            $latestLedger,
            $startLedger,
            $endLedger,
            $dryRun ? 1 : 0,
            $deriveEvents ? 1 : 0,
            $batchSize
        ));

        $scanExit = $this->runChild($output, [
            '--network' => $network,
            '--start-ledger' => (string) $startLedger,
            '--end-ledger' => (string) $endLedger,
            '--batch-size' => (string) $batchSize,
            '--dry-run' => $dryRun,
            '--derive-events' => $deriveEvents,
        ]);
        if ($scanExit !== Command::SUCCESS) {
            $io->error('Horizon DB range scan failed.');
            return Command::FAILURE;
        }

        if (!$dryRun) {
            $this->storeCursor($this->cursorKey($network), (string) $endLedger);
        }

        $io->success($dryRun
            ? 'Horizon contracts scan cron dry-run completed.'
            : sprintf('Horizon contracts scan cron completed. next_cursor=%d', $endLedger));

        return Command::SUCCESS;
    }

    /**
     * @return array{0:int|null,1:int|null}
     */
    private function resolveLedgerRange(
        InputInterface $input,
        string $network,
        int $latestLedger,
        int $lookbackLedgers,
        int $maxLedgersPerRun,
        SymfonyStyle $io,
    ): array {
        $startOption = $input->getOption('start-ledger');
        $endOption = $input->getOption('end-ledger');

        if (($startOption === null) !== ($endOption === null)) {
            $io->error('Use both --start-ledger and --end-ledger together.');
            return [null, null];
        }

        if ($startOption !== null && $endOption !== null) {
            $start = $this->parsePositiveIntOption($startOption);
            $end = $this->parsePositiveIntOption($endOption);
            if ($start === null || $end === null) {
                $io->error('--start-ledger and --end-ledger must be positive integers.');
                return [null, null];
            }
            if ($start > $end) {
                [$start, $end] = [$end, $start];
            }

            return [$start, min($latestLedger, $end)];
        }

        $cursorRaw = $this->loadCursor($this->cursorKey($network));
        $cursor = is_string($cursorRaw) && ctype_digit($cursorRaw) ? (int) $cursorRaw : null;

        if ($cursor !== null) {
            $start = $cursor + 1;
            $end = min($latestLedger, $start + $maxLedgersPerRun - 1);
            return [$start, $end];
        }

        $start = max(1, $latestLedger - $lookbackLedgers + 1);
        $end = min($latestLedger, $start + $maxLedgersPerRun - 1);
        return [$start, $end];
    }

    private function loadLatestLedgerFromHorizonDb(string $network): ?int
    {
        $connection = $this->resolveHorizonConnection($network);
        if ($connection === null) {
            return null;
        }

        try {
            $value = $connection->fetchOne('SELECT MAX(ledger_sequence) FROM history_transactions');
        } catch (\Throwable) {
            return null;
        }

        if ($value === null) {
            return null;
        }

        return max(0, (int) $value);
    }

    private function resolveHorizonConnection(string $network): ?Connection
    {
        $connectionName = match ($network) {
            'mainnet' => 'horizon_mainnet',
            'testnet' => 'horizon_testnet',
            default => 'horizon_connection',
        };

        try {
            return $this->doctrine->getConnection($connectionName);
        } catch (\Throwable) {
            if ($connectionName === 'horizon_connection') {
                return null;
            }
        }

        try {
            return $this->doctrine->getConnection('horizon_connection');
        } catch (\Throwable) {
            return null;
        }
    }

    private function runChild(OutputInterface $output, array $arguments): int
    {
        $application = $this->getApplication();
        if ($application === null) {
            throw new \RuntimeException('Console application is not initialized.');
        }

        $command = $application->find('app:horizon:scan-contract-range-db');
        $input = new ArrayInput($arguments);
        $input->setInteractive(false);

        return $command->run($input, $output);
    }

    private function parsePositiveIntOption(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }

    private function cursorKey(string $network): string
    {
        return sprintf('horizon_contract_scan_cursor_%s', strtolower(trim($network)));
    }

    private function loadCursor(string $key): ?string
    {
        $item = $this->cache->getItem($key);
        if (!$item->isHit()) {
            return null;
        }
        $value = trim((string) $item->get());
        return $value !== '' ? $value : null;
    }

    private function storeCursor(string $key, string $value): void
    {
        $item = $this->cache->getItem($key);
        $item->set($value);
        $this->cache->save($item);
    }

    private function deleteCursor(string $key): void
    {
        $this->cache->deleteItem($key);
    }
}

