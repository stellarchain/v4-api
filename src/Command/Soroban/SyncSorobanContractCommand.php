<?php

namespace App\Command\Soroban;

use App\Command\Support\NetworkOptionTrait;
use App\Dto\ContractRef;
use App\Entity\Contract;
use App\Repository\ContractRepository;
use App\Service\ContractTxSyncService;
use App\Service\Console\SorobanSyncProgressReporter;
use App\Service\Stellar\Soroban\SorobanContractInspector;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\ORM\EntityManagerInterface;
use Soneso\StellarSDK\Crypto\StrKey;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:soroban:sync-contract-data',
    description: 'Collect historical Soroban contract transactions/effects into local database.',
)]
final class SyncSorobanContractCommand extends Command
{
    use NetworkOptionTrait;

    public function __construct(
        private readonly ContractRepository $contractRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ContractTxSyncService $txSyncService,
        private readonly SorobanSyncProgressReporter $syncProgressReporter,
        private readonly SorobanContractInspector $sorobanContractInspector,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addNetworkOption('mainnet|testnet|futurenet', 'testnet')
            ->addOption('contract', null, InputOption::VALUE_REQUIRED, 'Specific contract id')
            ->addOption('start-ledger', null, InputOption::VALUE_REQUIRED, 'Inclusive start ledger (requires --end-ledger)')
            ->addOption('end-ledger', null, InputOption::VALUE_REQUIRED, 'Inclusive end ledger (requires --start-ledger)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Collect data but do not write into DB');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $validated = $this->validateInput($input, $io);
        if ($validated === null) {
            return Command::FAILURE;
        }

        $contractRef = $this->loadContract(
            $validated['networkCode'],
            $validated['network'],
            $validated['contractIdInput'],
            $validated['dryRun'],
        );
        if ($contractRef === null) {
            $io->warning('Contract not found on selected network.');
            return Command::SUCCESS;
        }

        $io->progressStart(1);
        $summary = $this->createSummary();
        $errors = [];
        $io->writeln('Starting Soroban scan...');
        $run = $this->runSync(
            $contractRef,
            $validated['network'],
            $validated['dryRun'],
            $validated['startLedger'],
            $validated['endLedger'],
            $this->syncProgressReporter->build($io),
        );
        if (($run['ok'] ?? false) !== true) {
            $summary['failed']++;
            $errors[] = sprintf('%s: %s', $contractRef->contractId, (string) ($run['error'] ?? 'unknown error'));
        } else {
            $summary['events'] += (int) ($run['eventsCollected'] ?? 0);
            $summary['transactions'] += (int) ($run['transactionsCollected'] ?? 0);
            $summary['inserted'] += (int) ($run['rowsInserted'] ?? 0);
            $summary['updated'] += (int) ($run['rowsUpdated'] ?? 0);
            $summary['unchanged'] += (int) ($run['rowsUnchanged'] ?? 0);
        }
        $io->progressAdvance();

        $io->progressFinish();
        $this->renderSummary($io, $summary, $errors, $validated['dryRun']);

        return Command::SUCCESS;
    }

    private function loadContract(int $networkCode, string $network, string $singleContract, bool $dryRun): ?ContractRef
    {
        $existing = $this->contractRepository->findOneByContractIdAndNetwork($singleContract, $networkCode);
        if ($existing instanceof Contract) {
            return new ContractRef(
                (int) $existing->getId(),
                (string) $existing->getContractId(),
            );
        }

        if (!$this->sorobanContractInspector->contractExistsOnNetwork($singleContract, $network)) {
            return null;
        }

        return $this->createContractForNetwork($singleContract, $networkCode, $dryRun);
    }

    private function createContractForNetwork(string $contractId, int $networkCode, bool $dryRun): ContractRef
    {
        if ($dryRun) {
            return new ContractRef(0, $contractId);
        }

        $contract = (new Contract())
            ->setContractId($contractId)
            ->setNetwork($networkCode)
            ->setCreatedAt(new \DateTimeImmutable());

        $decoded = $this->decodeContractIdHexOrNull($contractId);
        if ($decoded !== null) {
            $contract->setContractIdHex($decoded);
        }

        $this->entityManager->persist($contract);
        $this->entityManager->flush();

        return new ContractRef((int) $contract->getId(), $contractId);
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function decodeContractIdHexOrNull(string $contractId): ?string
    {
        try {
            return StrKey::decodeContractIdHex($contractId);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{network:string,networkCode:int,contractIdInput:string,startLedger:?int,endLedger:?int,dryRun:bool}|null
     */
    private function validateInput(InputInterface $input, SymfonyStyle $io): ?array
    {
        $network = $this->resolveNetworkOption($input, $this->stellarNetworkResolver);
        $networkCode = $this->resolveNetworkCodeOption($input, $this->stellarNetworkResolver);
        $contractIdInput = $this->normalizeNullableString($input->getOption('contract'));
        if ($contractIdInput === null) {
            $io->error('Option --contract is required for this command.');
            return null;
        }

        $startLedgerOption = $input->getOption('start-ledger');
        $endLedgerOption = $input->getOption('end-ledger');
        if (($startLedgerOption === null) !== ($endLedgerOption === null)) {
            $io->error('Use both --start-ledger and --end-ledger together.');
            return null;
        }

        $startLedger = $this->parsePositiveIntOption($startLedgerOption);
        if ($startLedgerOption !== null && $startLedger === null) {
            $io->error('Option --start-ledger must be a positive integer.');
            return null;
        }

        $endLedger = $this->parsePositiveIntOption($endLedgerOption);
        if ($endLedgerOption !== null && $endLedger === null) {
            $io->error('Option --end-ledger must be a positive integer.');
            return null;
        }

        return [
            'network' => $network,
            'networkCode' => $networkCode,
            'contractIdInput' => $contractIdInput,
            'startLedger' => $startLedger,
            'endLedger' => $endLedger,
            'dryRun' => (bool) $input->getOption('dry-run'),
        ];
    }

    private function parsePositiveIntOption(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }

    /**
     * @return array{
     *   contracts:int,
     *   failed:int,
     *   events:int,
     *   transactions:int,
     *   inserted:int,
     *   updated:int,
     *   unchanged:int
     * }
     */
    private function createSummary(): array
    {
        return [
            'contracts' => 1,
            'failed' => 0,
            'events' => 0,
            'transactions' => 0,
            'inserted' => 0,
            'updated' => 0,
            'unchanged' => 0,
        ];
    }

    /**
     * @param callable(array<string,mixed>):void $onProgress
     * @return array<string,mixed>
     */
    private function runSync(
        ContractRef $contractRef,
        string $network,
        bool $dryRun,
        ?int $startLedger,
        ?int $endLedger,
        callable $onProgress,
    ): array {
        try {
            return $this->txSyncService->syncForContract(
                $contractRef->id,
                $contractRef->contractId,
                $network,
                $dryRun,
                $startLedger,
                $endLedger,
                $onProgress,
            );
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array{
     *   contracts:int,
     *   failed:int,
     *   events:int,
     *   transactions:int,
     *   inserted:int,
     *   updated:int,
     *   unchanged:int
     * } $summary
     * @param array<int,string> $errors
     */
    private function renderSummary(SymfonyStyle $io, array $summary, array $errors, bool $dryRun): void
    {
        $io->newLine();
        $io->table(
            ['Metric', 'Value'],
            [
                ['contracts', (string) $summary['contracts']],
                ['failed', (string) $summary['failed']],
                ['events', (string) $summary['events']],
                ['transactions (unique tx_hash)', (string) $summary['transactions']],
                ['inserted', (string) $summary['inserted']],
                ['updated', (string) $summary['updated']],
                ['unchanged', (string) $summary['unchanged']],
            ]
        );

        if ($errors !== []) {
            $io->warning('Sample errors:');
            foreach (array_slice($errors, 0, 10) as $error) {
                $io->writeln('- ' . $error);
            }
        }

        $io->success($dryRun ? 'Dry-run completed.' : 'Soroban contracts sync completed.');
    }
}
