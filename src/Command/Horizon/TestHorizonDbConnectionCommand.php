<?php

declare(strict_types=1);

namespace App\Command\Horizon;

use App\Command\Support\NetworkOptionTrait;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:horizon:db:test',
    description: 'Test Horizon PostgreSQL connection for selected network.',
)]
final class TestHorizonDbConnectionCommand extends Command
{
    use NetworkOptionTrait;

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addNetworkOption('Network connection to test (mainnet|testnet|futurenet)', 'testnet');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $network = $this->resolveNetworkOption($input, $this->stellarNetworkResolver);
        $connectionName = match ($network) {
            'mainnet' => 'horizon_mainnet',
            'testnet' => 'horizon_testnet',
            default => 'horizon_testnet',
        };
        $horizonConnection = $this->doctrine->getConnection($connectionName);

        try {
            $ping = $horizonConnection->fetchOne('SELECT 1');
            $meta = $horizonConnection->fetchAssociative(
                'SELECT current_database() AS database_name, current_user AS database_user'
            );
        } catch (\Throwable $exception) {
            $io->error(sprintf('Horizon DB connection (%s) failed: %s', $connectionName, $exception->getMessage()));

            return Command::FAILURE;
        }

        $io->success(sprintf('Horizon DB connection is successful (%s).', $connectionName));
        $io->table(
            ['Key', 'Value'],
            [
                ['Network', $network],
                ['Connection', $connectionName],
                ['Ping', (string) $ping],
                ['Database', (string) ($meta['database_name'] ?? 'unknown')],
                ['User', (string) ($meta['database_user'] ?? 'unknown')],
            ]
        );

        return Command::SUCCESS;
    }
}
