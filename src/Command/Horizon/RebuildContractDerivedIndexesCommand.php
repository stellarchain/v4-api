<?php

declare(strict_types=1);

namespace App\Command\Horizon;

use App\Command\Support\NetworkOptionTrait;
use App\Service\ContractTxUpsertService;
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
    name: 'app:contracts:rebuild-derived-indexes',
    description: 'Rebuild scalable derived indexes for contract argument usages and holder balances.',
)]
final class RebuildContractDerivedIndexesCommand extends Command
{
    use NetworkOptionTrait;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.contracts_connection')]
        private readonly Connection $connection,
        private readonly ContractTxUpsertService $contractTxUpsertService,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addNetworkOption('mainnet|testnet|futurenet', 'mainnet')
            ->addOption('contract', null, InputOption::VALUE_REQUIRED, 'Rebuild only one contract id')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Tx batch size per contract.', '2000')
            ->addOption('limit-contracts', null, InputOption::VALUE_REQUIRED, 'Limit number of contracts processed.')
            ->addOption('skip-arguments', null, InputOption::VALUE_NONE, 'Skip argument usage rebuild.')
            ->addOption('skip-balances', null, InputOption::VALUE_NONE, 'Skip holder balances rebuild.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $network = $this->resolveNetworkOption($input, $this->stellarNetworkResolver);
        $networkCode = $this->resolveNetworkCodeOption($input, $this->stellarNetworkResolver);
        $singleContract = trim((string) ($input->getOption('contract') ?? ''));
        $batchSize = max(1, (int) ($input->getOption('batch-size') ?? 2000));
        $limitContracts = $this->parsePositiveIntOption($input->getOption('limit-contracts'));
        $skipArguments = (bool) $input->getOption('skip-arguments');
        $skipBalances = (bool) $input->getOption('skip-balances');

        $contracts = $this->loadContracts($networkCode, $singleContract, $limitContracts);
        if ($contracts === []) {
            $io->warning('No contracts matched filters.');
            return Command::SUCCESS;
        }

        $io->writeln(sprintf(
            'Rebuild derived indexes started | network=%s contracts=%d batch_size=%d skip_arguments=%d skip_balances=%d',
            $network,
            count($contracts),
            $batchSize,
            $skipArguments ? 1 : 0,
            $skipBalances ? 1 : 0
        ));

        $io->progressStart(count($contracts));
        foreach ($contracts as $row) {
            $contractId = (int) ($row['id'] ?? 0);
            if ($contractId <= 0) {
                $io->progressAdvance();
                continue;
            }

            if (!$skipArguments) {
                $this->contractTxUpsertService->rebuildArgumentUsageIndexForContract($contractId, $batchSize);
            }
            if (!$skipBalances) {
                $this->contractTxUpsertService->rebuildHolderBalancesForContract($contractId);
            }

            $io->progressAdvance();
        }
        $io->progressFinish();
        $io->newLine();
        $io->success('Derived indexes rebuild completed.');

        return Command::SUCCESS;
    }

    /**
     * @return list<array{id:int,contract_id:string}>
     */
    private function loadContracts(int $networkCode, string $singleContract, ?int $limitContracts): array
    {
        if ($singleContract !== '') {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, contract_id
                 FROM contracts
                 WHERE network = :network
                   AND contract_id = :contract_id
                 ORDER BY id ASC',
                [
                    'network' => $networkCode,
                    'contract_id' => strtoupper($singleContract),
                ],
                [
                    'network' => ParameterType::INTEGER,
                ]
            );

            return is_array($rows) ? $rows : [];
        }

        $sql = 'SELECT id, contract_id
                FROM contracts
                WHERE network = :network
                ORDER BY id ASC';
        $params = ['network' => $networkCode];
        $types = ['network' => ParameterType::INTEGER];
        if ($limitContracts !== null) {
            $sql .= ' LIMIT :limit_rows';
            $params['limit_rows'] = $limitContracts;
            $types['limit_rows'] = ParameterType::INTEGER;
        }

        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);

        return is_array($rows) ? $rows : [];
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
}
