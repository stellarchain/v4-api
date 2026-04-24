<?php

namespace App\Command\Maintenance;

use App\Service\CoingeckoSnapshotCache;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:warm-coingecko-cache',
    description: 'Fetch CoinGecko/Stellar upstream data and persist it to cache for /api/coins/stellar.',
)]
final class WarmCoingeckoCacheCommand extends Command
{
    public function __construct(
        private readonly CoingeckoSnapshotCache $snapshotCache,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $payload = $this->snapshotCache->refresh();
        } catch (\Throwable $exception) {
            $io->error(sprintf('Failed to refresh CoinGecko cache: %s', $exception->getMessage()));
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'CoinGecko cache refreshed successfully (%d sections).',
            count($payload)
        ));

        return Command::SUCCESS;
    }
}
