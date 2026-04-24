<?php

namespace App\Command\Import;

use App\Command\Support\NetworkOptionTrait;
use App\Entity\Account;
use App\Entity\AccountMetric;
use App\Repository\AccountRepository;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import-account-metrics',
    description: 'Import account balances/transactions from CSV and upsert Account + AccountMetric.',
)]
final class ImportAccountMetricsCommand extends Command
{
    use NetworkOptionTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountRepository $accountRepository,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to CSV file', 'resources/known_accounts_with_balance_and_transactions.csv')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Flush batch size', 500)
            ->addNetworkOption('Target network (mainnet|testnet|futurenet)', 'testnet')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Parse CSV but do not write to database');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filePath = (string) $input->getOption('file');
        $batchSize = (int) $input->getOption('batch-size');
        $network = $this->resolveNetworkOption($input, $this->stellarNetworkResolver);
        $networkCode = $this->resolveNetworkCodeOption($input, $this->stellarNetworkResolver);
        $isDryRun = (bool) $input->getOption('dry-run');

        if (!is_file($filePath)) {
            $io->error(sprintf('File not found: %s', $filePath));
            return Command::FAILURE;
        }

        $rows = [];
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            $io->error(sprintf('Unable to read file: %s', $filePath));
            return Command::FAILURE;
        }

        $lineNumber = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $lineNumber++;
            if ($row === [null] || count($row) < 4) {
                $io->warning(sprintf('Skipping invalid row %d', $lineNumber));
                continue;
            }

            [$accountId, $name, $balance, $transactions] = $row;
            $accountId = trim((string) $accountId, " \t\n\r\0\x0B\"");
            if ($accountId === '' || strtolower($accountId) === 'accountid') {
                continue;
            }

            $rows[] = [
                'accountid' => $accountId,
                'name' => trim((string) $name, " \t\n\r\0\x0B\""),
                'balance' => trim((string) $balance, " \t\n\r\0\x0B\""),
                'transactions' => trim((string) $transactions, " \t\n\r\0\x0B\""),
            ];
        }
        fclose($handle);

        if ($rows === []) {
            $io->warning('No valid rows found.');
            return Command::SUCCESS;
        }

        $addresses = array_map(static fn (array $row) => $row['accountid'], $rows);
        $existingAccounts = [];
        $chunkSize = 500;
        foreach (array_chunk($addresses, $chunkSize) as $chunk) {
            foreach ($this->accountRepository->findBy(['address' => $chunk, 'network' => $networkCode]) as $account) {
                $existingAccounts[$account->getAddress()] = $account;
            }
        }

        $created = 0;
        $updated = 0;
        $metricsCreated = 0;
        $now = new \DateTimeImmutable();

        foreach ($rows as $index => $row) {
            $address = $row['accountid'];
            $label = $row['name'] !== '' ? $row['name'] : null;
            $balance = $row['balance'] !== '' ? $row['balance'] : '0.0000000';
            $transactions = $row['transactions'] !== '' ? $row['transactions'] : '0';

            $account = $existingAccounts[$address] ?? null;
            if ($account === null) {
                $account = new Account();
                $account
                    ->setAddress($address)
                    ->setNetwork($networkCode)
                    ->setLabel($label)
                    ->setVerified(false)
                    ->setCreatedAt($now)
                    ->setUpdatedAt($now);

                $existingAccounts[$address] = $account;
                $created++;

                if (!$isDryRun) {
                    $this->entityManager->persist($account);
                }
            } else {
                if ($label !== null) {
                    $account->setLabel($label);
                }
                $account->setUpdatedAt($now);
                $updated++;
            }

            $metric = $account->getAccountMetric();
            if ($metric === null) {
                $metric = new AccountMetric();
                $account->setAccountMetric($metric);
                $metricsCreated++;
                if (!$isDryRun) {
                    $this->entityManager->persist($metric);
                }
            }

            $metric
                ->setNativeBalance($balance)
                ->setTransactionsPerHour($transactions);

            if (!$isDryRun && ($index + 1) % $batchSize === 0) {
                $this->entityManager->flush();
            }
        }

        if (!$isDryRun) {
            $this->entityManager->flush();
        }

        $io->success(sprintf(
            'Done. Created accounts: %d, updated accounts: %d, metrics created: %d.',
            $created,
            $updated,
            $metricsCreated
        ));

        return Command::SUCCESS;
    }
}
