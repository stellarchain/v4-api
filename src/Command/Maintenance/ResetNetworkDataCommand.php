<?php

namespace App\Command\Maintenance;

use App\Command\Support\NetworkOptionTrait;
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
    name: 'app:network:reset-data',
    description: 'Delete local data for one Stellar network (accounts/assets/contracts/orders and related rows).',
)]
final class ResetNetworkDataCommand extends Command
{
    use NetworkOptionTrait;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private readonly Connection $connection,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addNetworkOption('Network to reset (mainnet|testnet|futurenet)', 'testnet')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show counts only, do not delete data')
            ->addOption('allow-mainnet', null, InputOption::VALUE_NONE, 'Required to permit mainnet reset')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Execute deletion');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $network = $this->resolveNetworkOption($input, $this->stellarNetworkResolver);
        $networkCode = $this->resolveNetworkCodeOption($input, $this->stellarNetworkResolver, 2);

        $dryRun = (bool) $input->getOption('dry-run');
        $allowMainnet = (bool) $input->getOption('allow-mainnet');
        $force = (bool) $input->getOption('force');

        if ($network === 'mainnet' && !$allowMainnet) {
            $io->error('Mainnet reset is blocked by default. Use --allow-mainnet to explicitly permit it.');
            return Command::FAILURE;
        }

        $plan = [
            [
                'name' => 'account_balance_snapshot',
                'countSql' => 'SELECT COUNT(*) FROM account_balance_snapshot abs INNER JOIN account a ON a.id = abs.account_id WHERE a.network = :network',
                'deleteSql' => 'DELETE abs FROM account_balance_snapshot abs INNER JOIN account a ON a.id = abs.account_id WHERE a.network = :network',
            ],
            [
                'name' => 'account_metric_interval',
                'countSql' => 'SELECT COUNT(*) FROM account_metric_interval ami INNER JOIN account a ON a.id = ami.account_id WHERE a.network = :network',
                'deleteSql' => 'DELETE ami FROM account_metric_interval ami INNER JOIN account a ON a.id = ami.account_id WHERE a.network = :network',
            ],
            [
                'name' => 'account_metric',
                'countSql' => 'SELECT COUNT(*) FROM account_metric am INNER JOIN account a ON a.id = am.account_id WHERE a.network = :network',
                'deleteSql' => 'DELETE am FROM account_metric am INNER JOIN account a ON a.id = am.account_id WHERE a.network = :network',
            ],
            [
                'name' => 'account',
                'countSql' => 'SELECT COUNT(*) FROM account WHERE network = :network',
                'deleteSql' => 'DELETE FROM account WHERE network = :network',
            ],
            [
                'name' => 'asset_statistic',
                'countSql' => 'SELECT COUNT(*) FROM asset_statistic ast INNER JOIN asset a ON a.id = ast.asset_id WHERE a.network = :network',
                'deleteSql' => 'DELETE ast FROM asset_statistic ast INNER JOIN asset a ON a.id = ast.asset_id WHERE a.network = :network',
            ],
            [
                'name' => 'asset_metric_history',
                'countSql' => 'SELECT COUNT(*) FROM asset_metric_history amh INNER JOIN asset a ON a.id = amh.asset_id WHERE a.network = :network',
                'deleteSql' => 'DELETE amh FROM asset_metric_history amh INNER JOIN asset a ON a.id = amh.asset_id WHERE a.network = :network',
            ],
            [
                'name' => 'network_metric_point',
                'countSql' => 'SELECT COUNT(*) FROM network_metric_point WHERE network = :network',
                'deleteSql' => 'DELETE FROM network_metric_point WHERE network = :network',
            ],
            [
                'name' => 'asset',
                'countSql' => 'SELECT COUNT(*) FROM asset WHERE network = :network',
                'deleteSql' => 'DELETE FROM asset WHERE network = :network',
            ],
            [
                'name' => 'contract_events',
                'countSql' => 'SELECT COUNT(*) FROM contract_events ce INNER JOIN contracts c ON c.id = ce.contract_id WHERE c.network = :network',
                'deleteSql' => 'DELETE ce FROM contract_events ce INNER JOIN contracts c ON c.id = ce.contract_id WHERE c.network = :network',
            ],
            [
                'name' => 'contract_storage_entries',
                'countSql' => 'SELECT COUNT(*) FROM contract_storage_entries cse INNER JOIN contracts c ON c.id = cse.contract_id WHERE c.network = :network',
                'deleteSql' => 'DELETE cse FROM contract_storage_entries cse INNER JOIN contracts c ON c.id = cse.contract_id WHERE c.network = :network',
            ],
            [
                'name' => 'contract_transactions',
                'countSql' => 'SELECT COUNT(*) FROM contract_transactions ct INNER JOIN contracts c ON c.id = ct.contract_id WHERE c.network = :network',
                'deleteSql' => 'DELETE ct FROM contract_transactions ct INNER JOIN contracts c ON c.id = ct.contract_id WHERE c.network = :network',
            ],
            [
                'name' => 'contracts',
                'countSql' => 'SELECT COUNT(*) FROM contracts WHERE network = :network',
                'deleteSql' => 'DELETE FROM contracts WHERE network = :network',
            ],
            [
                'name' => 'orders',
                'countSql' => 'SELECT COUNT(*) FROM orders WHERE network = :network',
                'deleteSql' => 'DELETE FROM orders WHERE network = :network',
            ],
        ];

        $rows = [];
        foreach ($plan as $segment) {
            $count = (int) $this->connection->fetchOne(
                $segment['countSql'],
                ['network' => $networkCode],
                ['network' => ParameterType::INTEGER]
            );
            $rows[] = [
                'table' => $segment['name'],
                'count' => (string) $count,
                'deleted' => $dryRun ? '0 (dry-run)' : 'pending',
            ];
        }

        $io->writeln(sprintf('Target network: %s (code=%d)', $network, $networkCode));
        $io->table(['Table', 'Rows matched', 'Rows deleted'], $rows);

        if ($dryRun) {
            $io->success('Dry-run completed.');
            return Command::SUCCESS;
        }

        if (!$force) {
            $io->warning('No data deleted. Re-run with --force to execute.');
            return Command::SUCCESS;
        }

        $deleted = [];
        $this->connection->beginTransaction();
        try {
            foreach ($plan as $segment) {
                $affected = $this->connection->executeStatement(
                    $segment['deleteSql'],
                    ['network' => $networkCode],
                    ['network' => ParameterType::INTEGER]
                );
                $deleted[] = [$segment['name'], (string) $affected];
            }
            $this->connection->commit();
        } catch (\Throwable $exception) {
            $this->connection->rollBack();
            throw $exception;
        }

        $io->table(['Table', 'Rows deleted'], $deleted);
        $io->success('Network data reset completed.');

        return Command::SUCCESS;
    }
}
