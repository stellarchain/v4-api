<?php

declare(strict_types=1);

namespace App\Command\Maintenance;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:setup:database-data',
    description: 'Bootstrap and populate local DB from Horizon for testnet/mainnet (accounts, markets, metrics, ranks).',
)]
final class SetupDatabaseDataCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('network', null, InputOption::VALUE_REQUIRED, 'all|mainnet|testnet', 'all')
            ->addOption('from-zero', null, InputOption::VALUE_NONE, 'Reset local network data before bootstrap')
            ->addOption('allow-mainnet-reset', null, InputOption::VALUE_NONE, 'Required if --from-zero includes mainnet')
            ->addOption('migrate', null, InputOption::VALUE_NONE, 'Run doctrine migrations before bootstrap')
            ->addOption('accounts-top', null, InputOption::VALUE_REQUIRED, 'Top accounts imported and processed', 1000)
            ->addOption('market-top', null, InputOption::VALUE_REQUIRED, 'Top market assets to persist', 1000)
            ->addOption('toml-top', null, InputOption::VALUE_REQUIRED, 'Top assets for TOML enrichment', 1000)
            ->addOption('lookback-hours', null, InputOption::VALUE_REQUIRED, 'Lookback hours for account interval metrics', 24)
            ->addOption('interval-minutes', null, InputOption::VALUE_REQUIRED, 'Bucket size for account interval metrics', 5)
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Batch size for account interval metrics', 300)
            ->addOption('skip-toml', null, InputOption::VALUE_NONE, 'Skip TOML enrichment step')
            ->addOption('skip-logo-check', null, InputOption::VALUE_NONE, 'Skip remote logo validation during TOML enrichment')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Propagate dry-run where supported')
            ->addOption('continue-on-error', null, InputOption::VALUE_NONE, 'Continue remaining steps even if a step fails');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $networkOption = strtolower(trim((string) $input->getOption('network')));
        $fromZero = (bool) $input->getOption('from-zero');
        $allowMainnetReset = (bool) $input->getOption('allow-mainnet-reset');
        $runMigrate = (bool) $input->getOption('migrate');
        $accountsTop = max(1, (int) $input->getOption('accounts-top'));
        $marketTop = max(1, (int) $input->getOption('market-top'));
        $tomlTop = max(1, (int) $input->getOption('toml-top'));
        $lookbackHours = max(1, (int) $input->getOption('lookback-hours'));
        $intervalMinutes = max(1, (int) $input->getOption('interval-minutes'));
        $batchSize = max(1, (int) $input->getOption('batch-size'));
        $skipToml = (bool) $input->getOption('skip-toml');
        $skipLogoCheck = (bool) $input->getOption('skip-logo-check');
        $dryRun = (bool) $input->getOption('dry-run');
        $continueOnError = (bool) $input->getOption('continue-on-error');

        $networks = match ($networkOption) {
            'all' => ['testnet', 'mainnet'],
            'testnet' => ['testnet'],
            'mainnet' => ['mainnet'],
            default => null,
        };
        if (!is_array($networks)) {
            $io->error('--network must be one of: all, mainnet, testnet.');

            return Command::FAILURE;
        }

        if ($fromZero && in_array('mainnet', $networks, true) && !$allowMainnetReset) {
            $io->error('Mainnet reset requested via --from-zero. Re-run with --allow-mainnet-reset.');

            return Command::FAILURE;
        }

        $summary = [];
        if ($runMigrate) {
            $exitCode = $this->runChild(
                $output,
                'doctrine:migrations:migrate',
                ['--no-interaction' => true]
            );
            $summary[] = ['global', 'doctrine:migrations:migrate', (string) $exitCode];
            if ($exitCode !== Command::SUCCESS && !$continueOnError) {
                $io->error('Migrations failed. Aborting.');

                return Command::FAILURE;
            }
        }

        foreach ($networks as $network) {
            $io->section(sprintf('Setup %s', $network));

            $steps = [];
            if ($fromZero) {
                $resetArgs = ['--network' => $network];
                if ($network === 'mainnet') {
                    $resetArgs['--allow-mainnet'] = true;
                }
                if ($dryRun) {
                    $resetArgs['--dry-run'] = true;
                } else {
                    $resetArgs['--force'] = true;
                }
                $steps[] = ['app:network:reset-data', $resetArgs];
            }

            $importArgs = [
                '--network' => $network,
                '--top' => (string) $accountsTop,
                '--balance-unit' => 'auto',
            ];
            if ($dryRun) {
                $importArgs['--dry-run'] = true;
            }
            $steps[] = ['app:import-known-accounts', $importArgs];

            $marketArgs = [
                '--network' => $network,
                '--top' => (string) $marketTop,
            ];
            if ($dryRun) {
                $marketArgs['--dry-run'] = true;
            }
            $steps[] = ['app:market:sync-snapshots', $marketArgs];

            $overviewArgs = [
                '--network' => $network,
            ];
            if ($dryRun) {
                $overviewArgs['--dry-run'] = true;
            }
            $steps[] = ['app:horizon:sync-market-overview', $overviewArgs];

            if (!$skipToml) {
                $tomlArgs = [
                    '--network' => $network,
                    '--batch-size' => '200',
                    '--market-top' => (string) $tomlTop,
                ];
                if ($skipLogoCheck) {
                    $tomlArgs['--skip-logo-check'] = true;
                }
                if ($dryRun) {
                    $tomlArgs['--dry-run'] = true;
                }
                $steps[] = ['app:market:enrich-assets-toml', $tomlArgs];
            }

            $accountMetricsArgs = [
                '--network' => $network,
                '--lookback-hours' => (string) $lookbackHours,
                '--interval-minutes' => (string) $intervalMinutes,
                '--top' => (string) $accountsTop,
                '--batch-size' => (string) $batchSize,
            ];
            if ($dryRun) {
                $accountMetricsArgs['--dry-run'] = true;
            }
            $steps[] = ['app:account-metrics:sync-intervals', $accountMetricsArgs];

            foreach ($steps as [$name, $args]) {
                $exitCode = $this->runChild($output, $name, $args);
                $summary[] = [$network, $name, (string) $exitCode];
                if ($exitCode !== Command::SUCCESS && !$continueOnError) {
                    $io->error(sprintf('Step failed: %s (%s). Aborting.', $name, $network));
                    $io->table(['Network', 'Step', 'Exit'], $summary);

                    return Command::FAILURE;
                }
            }
        }

        $io->table(['Network', 'Step', 'Exit'], $summary);
        $io->success('SetupDatabaseData pipeline finished.');

        return Command::SUCCESS;
    }

    /**
     * @param array<string,mixed> $arguments
     */
    private function runChild(OutputInterface $output, string $commandName, array $arguments): int
    {
        $application = $this->getApplication();
        if ($application === null) {
            throw new \RuntimeException('Console application instance is not available.');
        }

        $command = $application->find($commandName);
        $input = new ArrayInput(['command' => $commandName] + $arguments);
        $input->setInteractive(false);

        return $command->run($input, $output);
    }
}
