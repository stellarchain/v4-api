<?php

declare(strict_types=1);

namespace App\Command\Directory;

use App\Command\Support\NetworkOptionTrait;
use App\Service\Stellar\StellarNetworkResolver;
use App\Service\StellarExpertDirectorySyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:directory:sync-stellar-expert',
    description: 'Sync missing account directory labels from Stellar Expert and import external scam flags.',
)]
final class SyncStellarExpertDirectoryCommand extends Command
{
    use NetworkOptionTrait;

    public function __construct(
        private readonly StellarExpertDirectorySyncService $syncService,
        private readonly StellarNetworkResolver $networkResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addNetworkOption('Target local network for imported directory entries (mainnet|testnet|futurenet)', 'mainnet')
            ->addOption('page-size', null, InputOption::VALUE_REQUIRED, 'Stellar Expert page size, 1-200.', '200')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum records to process for this run. Omit to sync all.')
            ->addOption('cursor', null, InputOption::VALUE_REQUIRED, 'Start after this Stellar Expert paging cursor.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Fetch and compute changes without writing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pageSize = (int) $input->getOption('page-size');
        if ($pageSize < 1 || $pageSize > 200) {
            $io->error('--page-size must be between 1 and 200.');

            return Command::FAILURE;
        }

        $limit = null;
        $rawLimit = $input->getOption('limit');
        if ($rawLimit !== null && trim((string) $rawLimit) !== '') {
            $limit = (int) $rawLimit;
            if ($limit < 1) {
                $io->error('--limit must be greater than 0 when provided.');

                return Command::FAILURE;
            }
        }

        $cursor = $input->getOption('cursor');
        $cursor = is_scalar($cursor) && trim((string) $cursor) !== '' ? trim((string) $cursor) : null;
        $network = $this->resolveNetworkOption($input, $this->networkResolver);
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $result = $this->syncService->sync($network, $pageSize, $limit, $cursor, $dryRun);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->table(
            ['Metric', 'Value'],
            array_map(
                static fn (string $key, mixed $value): array => [$key, is_bool($value) ? ($value ? '1' : '0') : (string) $value],
                array_keys($result),
                $result
            )
        );
        $io->success($dryRun ? 'Stellar Expert directory dry-run completed.' : 'Stellar Expert directory sync completed.');

        return Command::SUCCESS;
    }
}
