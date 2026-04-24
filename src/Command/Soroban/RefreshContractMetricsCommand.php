<?php

namespace App\Command\Soroban;

use App\Service\ContractMetricsRefreshService;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:contracts:refresh-metrics',
    description: 'Rebuild denormalized contract metrics in contracts table for API performance.',
)]
final class RefreshContractMetricsCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private readonly Connection $connection,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
        private readonly ContractMetricsRefreshService $contractMetricsRefreshService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('network', null, InputOption::VALUE_REQUIRED, 'mainnet|testnet|futurenet', 'mainnet')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Contracts per batch', '500')
            ->addOption('max-contracts', null, InputOption::VALUE_REQUIRED, 'Max contracts to process, 0 for all', '0')
            ->addOption('after-id', null, InputOption::VALUE_REQUIRED, 'Start after this contracts.id', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $network = $this->stellarNetworkResolver->normalizeNetwork(
            is_string($input->getOption('network')) ? $input->getOption('network') : null,
            'mainnet'
        );
        $networkCode = $this->stellarNetworkResolver->resolveNetworkCode($network);
        if ($networkCode === null) {
            $io->error('Invalid --network value.');
            return Command::INVALID;
        }

        $batchSize = max(1, (int) $input->getOption('batch-size'));
        $maxContracts = max(0, (int) $input->getOption('max-contracts'));
        $cursorId = max(0, (int) $input->getOption('after-id'));

        $processed = 0;
        $updated = 0;
        $batches = 0;
        $startedAt = microtime(true);

        $io->writeln(sprintf(
            '[start] network=%s batch_size=%d max_contracts=%s after_id=%d',
            $network,
            $batchSize,
            $maxContracts > 0 ? (string) $maxContracts : 'all',
            $cursorId
        ));

        while (true) {
            $remaining = $maxContracts > 0 ? max(0, $maxContracts - $processed) : $batchSize;
            if ($maxContracts > 0 && $remaining === 0) {
                break;
            }
            $limit = min($batchSize, $remaining);
            if ($limit <= 0) {
                break;
            }

            $rows = $this->connection->fetchAllAssociative(
                'SELECT id
                 FROM contracts
                 WHERE network = :network
                   AND id > :after_id
                 ORDER BY id ASC
                 LIMIT :limit_rows',
                [
                    'network' => $networkCode,
                    'after_id' => $cursorId,
                    'limit_rows' => $limit,
                ],
                [
                    'network' => ParameterType::INTEGER,
                    'after_id' => ParameterType::INTEGER,
                    'limit_rows' => ParameterType::INTEGER,
                ]
            );

            if ($rows === []) {
                break;
            }

            $ids = [];
            foreach ($rows as $row) {
                $id = isset($row['id']) ? (int) $row['id'] : 0;
                if ($id > 0) {
                    $ids[] = $id;
                    $cursorId = $id;
                }
            }
            if ($ids === []) {
                break;
            }

            $batchUpdated = $this->contractMetricsRefreshService->refreshForContractIds($ids);
            $batchProcessed = count($ids);

            $updated += $batchUpdated;
            $processed += $batchProcessed;
            $batches++;

            $elapsed = (int) (microtime(true) - $startedAt);
            $io->writeln(sprintf(
                '[batch %d] processed=%d updated=%d total_processed=%d total_updated=%d last_id=%d elapsed=%ds',
                $batches,
                $batchProcessed,
                $batchUpdated,
                $processed,
                $updated,
                $cursorId,
                $elapsed
            ));
        }

        $io->table(
            ['Metric', 'Value'],
            [
                ['network', $network],
                ['processed_contracts', (string) $processed],
                ['updated_contracts', (string) $updated],
                ['batches', (string) $batches],
                ['last_id', (string) $cursorId],
            ]
        );
        $io->success('Contract metrics refresh completed.');

        return Command::SUCCESS;
    }
}
