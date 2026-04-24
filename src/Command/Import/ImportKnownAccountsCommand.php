<?php

declare(strict_types=1);

namespace App\Command\Import;

use App\Command\Support\NetworkOptionTrait;
use App\Entity\Account;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import-known-accounts',
    description: 'Import important accounts directly from Horizon DB (no CSV).',
)]
final class ImportKnownAccountsCommand extends Command
{
    use NetworkOptionTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagerRegistry $doctrine,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('top', null, InputOption::VALUE_REQUIRED, 'How many top accounts to import', 1000)
            ->addOption('min-balance', null, InputOption::VALUE_REQUIRED, 'Min native balance in XLM (after stroops conversion)', '10')
            ->addOption('balance-unit', null, InputOption::VALUE_REQUIRED, 'Balance unit in Horizon accounts table: auto|stroops|xlm', 'auto')
            ->addOption('labels-file', null, InputOption::VALUE_REQUIRED, 'CSV with address,label,verified (applied only on mainnet)', 'resources/known_accounts.csv')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Flush batch size', 500)
            ->addNetworkOption('Target network (mainnet|testnet|futurenet)', 'testnet')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compute only, without writing to database');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $top = max(1, (int) $input->getOption('top'));
        $minBalanceXlm = (string) $input->getOption('min-balance');
        $balanceUnit = strtolower(trim((string) $input->getOption('balance-unit')));
        $labelsFile = (string) $input->getOption('labels-file');
        $batchSize = max(1, (int) $input->getOption('batch-size'));
        $network = $this->resolveNetworkOption($input, $this->stellarNetworkResolver);
        $networkCode = $this->resolveNetworkCodeOption($input, $this->stellarNetworkResolver);
        $applyCsvLabels = $network === 'mainnet';
        $isDryRun = (bool) $input->getOption('dry-run');
        if (!in_array($balanceUnit, ['auto', 'stroops', 'xlm'], true)) {
            $io->error('--balance-unit must be one of: auto, stroops, xlm.');

            return Command::FAILURE;
        }

        $horizonConnection = $this->resolveHorizonConnection($network);
        $columns = $this->loadAccountsColumns($horizonConnection);
        if (!isset($columns['account_id'], $columns['balance'])) {
            $io->error('Horizon accounts table does not contain required columns: account_id, balance.');

            return Command::FAILURE;
        }

        $csvLabels = $applyCsvLabels ? $this->loadCsvLabels($labelsFile) : [];
        $rows = $this->loadImportantAccounts($horizonConnection, $columns, $top);
        if ($applyCsvLabels) {
            $rows = $this->prioritizeCsvRows($horizonConnection, $rows, $csvLabels);
        }
        if ($rows === []) {
            $io->warning('No Horizon accounts returned by query.');

            return Command::SUCCESS;
        }

        $transactionsPerHourMap = $this->loadTransactionsPerHourMap(
            $horizonConnection,
            array_map(static fn (array $row): string => (string) ($row['address'] ?? ''), $rows)
        );

        $tableName = $this->entityManager->getClassMetadata(Account::class)->getTableName();
        $existingAddresses = $this->entityManager
            ->getConnection()
            ->fetchFirstColumn(
                sprintf('SELECT address FROM %s WHERE network = :network', $tableName),
                ['network' => $networkCode],
                ['network' => ParameterType::INTEGER]
            );
        $existingSet = array_fill_keys($existingAddresses, true);

        $now = new \DateTimeImmutable();
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $processed = 0;
        $labelsAppliedFromCsv = 0;
        $verifiedAppliedFromCsv = 0;
        $metricRowsUpserted = 0;
        $metricRows = [];

        foreach ($rows as $row) {
            $address = trim((string) ($row['address'] ?? ''));
            if ($address === '') {
                $skipped++;
                continue;
            }

            $nativeBalanceXlm = $this->toXlmDecimal((string) ($row['native_balance_stroops'] ?? '0'), $balanceUnit);
            if (bccomp($nativeBalanceXlm, $minBalanceXlm, 7) < 0) {
                $skipped++;
                continue;
            }

            $processed++;
            $rankPosition = $processed;
            $rankScore = $this->normalizeDecimal((float) ($row['importance_score'] ?? 0.0), 8);
            $transactionsPerHour = (string) ($transactionsPerHourMap[$address] ?? '0');
            if (isset($existingSet[$address])) {
                $updated++;
                $csv = $csvLabels[$address] ?? null;
                if (is_array($csv) && !$isDryRun) {
                    $label = $csv['label'] ?? null;
                    $verified = (bool) ($csv['verified'] ?? false);
                    $affected = $this->entityManager->getConnection()->executeStatement(
                        <<<SQL
UPDATE account
SET label = CASE WHEN (label IS NULL OR label = '') AND :csv_label IS NOT NULL AND :csv_label <> '' THEN :csv_label ELSE label END,
    verified = CASE WHEN :csv_verified = 1 THEN 1 ELSE verified END,
    updated_at = :updated_at
WHERE address = :address AND network = :network
SQL,
                        [
                            'csv_label' => $label,
                            'csv_verified' => $verified ? 1 : 0,
                            'updated_at' => $now->format('Y-m-d H:i:s'),
                            'address' => $address,
                            'network' => $networkCode,
                        ],
                        ['network' => ParameterType::INTEGER]
                    );
                    if ($affected > 0) {
                        if (is_string($label) && trim($label) !== '') {
                            $labelsAppliedFromCsv++;
                        }
                        if ($verified) {
                            $verifiedAppliedFromCsv++;
                        }
                    }
                }
                $metricRows[] = [
                    'address' => $address,
                    'native_balance' => $nativeBalanceXlm,
                    'transactions_per_hour' => $transactionsPerHour,
                    'rank_score' => $rankScore,
                    'rank_position' => $rankPosition,
                ];
                continue;
            }

            $account = new Account();
            $csv = $csvLabels[$address] ?? null;
            $label = is_array($csv) && is_string($csv['label'] ?? null) && trim((string) $csv['label']) !== '' ? trim((string) $csv['label']) : null;
            $verified = is_array($csv) ? (bool) ($csv['verified'] ?? false) : false;
            $account
                ->setAddress($address)
                ->setNetwork($networkCode)
                ->setLabel($label)
                ->setVerified($verified)
                ->setCreatedAt($now)
                ->setUpdatedAt($now);

            if (!$isDryRun) {
                $this->entityManager->persist($account);
            }

            $existingSet[$address] = true;
            $created++;
            if ($label !== null) {
                $labelsAppliedFromCsv++;
            }
            if ($verified) {
                $verifiedAppliedFromCsv++;
            }
            $metricRows[] = [
                'address' => $address,
                'native_balance' => $nativeBalanceXlm,
                'transactions_per_hour' => $transactionsPerHour,
                'rank_score' => $rankScore,
                'rank_position' => $rankPosition,
            ];

            if (!$isDryRun && $created % $batchSize === 0) {
                $this->entityManager->flush();
                $this->entityManager->clear();
            }
        }

        if (!$isDryRun) {
            $this->entityManager->flush();
            $this->entityManager->clear();
            $metricRowsUpserted = $this->upsertAccountMetrics($metricRows, $networkCode, $now);
        }

        $io->table(
            ['Metric', 'Value'],
            [
                ['network', $network],
                ['csv_labels_enabled', $applyCsvLabels ? '1' : '0'],
                ['top_requested', (string) $top],
                ['balance_unit', $balanceUnit],
                ['processed_after_filters', (string) $processed],
                ['created', (string) $created],
                ['already_existing', (string) $updated],
                ['labels_applied_from_csv', (string) $labelsAppliedFromCsv],
                ['verified_applied_from_csv', (string) $verifiedAppliedFromCsv],
                ['metric_rows_upserted', (string) $metricRowsUpserted],
                ['skipped', (string) $skipped],
                ['dry_run', $isDryRun ? '1' : '0'],
            ]
        );
        $io->success('Known accounts import from Horizon completed.');

        return Command::SUCCESS;
    }

    /**
     * @return array<string,bool>
     */
    private function loadAccountsColumns(Connection $horizonConnection): array
    {
        $rows = $horizonConnection->fetchAllAssociative(
            "SELECT column_name FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='accounts'"
        );

        $set = [];
        foreach ($rows as $row) {
            $column = strtolower(trim((string) ($row['column_name'] ?? '')));
            if ($column !== '') {
                $set[$column] = true;
            }
        }

        return $set;
    }

    /**
     * @param array<string,bool> $columns
     * @return list<array{address:string,native_balance_stroops:string,importance_score:float}>
     */
    private function loadImportantAccounts(Connection $horizonConnection, array $columns, int $top): array
    {
        $liabilitiesExpr = '0';
        if (isset($columns['buying_liabilities'], $columns['selling_liabilities'])) {
            $liabilitiesExpr = 'ABS(COALESCE(a.buying_liabilities,0)) + ABS(COALESCE(a.selling_liabilities,0))';
        }

        $subentriesExpr = isset($columns['num_subentries']) ? 'COALESCE(a.num_subentries,0)' : '0';
        $homeDomainBoostExpr = isset($columns['home_domain']) ? 'CASE WHEN a.home_domain IS NULL OR a.home_domain = \'\' THEN 0 ELSE 1 END' : '0';

        $sql = <<<SQL
SELECT
    a.account_id AS address,
    CAST(COALESCE(a.balance, 0) AS TEXT) AS native_balance_stroops,
    (
        LOG10(1 + COALESCE(a.balance, 0)) * 0.70 +
        LOG10(1 + ($liabilitiesExpr)) * 0.20 +
        LOG10(1 + ($subentriesExpr)) * 0.08 +
        ($homeDomainBoostExpr) * 0.02
    ) AS importance_score
FROM accounts a
ORDER BY importance_score DESC, a.balance DESC
LIMIT :top
SQL;

        $rows = $horizonConnection->fetchAllAssociative(
            $sql,
            ['top' => $top],
            ['top' => ParameterType::INTEGER]
        );

        return array_map(
            static fn (array $row): array => [
                'address' => (string) ($row['address'] ?? ''),
                'native_balance_stroops' => (string) ($row['native_balance_stroops'] ?? '0'),
                'importance_score' => is_numeric($row['importance_score'] ?? null) ? (float) $row['importance_score'] : 0.0,
            ],
            $rows
        );
    }

    /**
     * @param list<array{address:string,native_balance:string,transactions_per_hour:string,rank_score:string,rank_position:int}> $rows
     */
    private function upsertAccountMetrics(array $rows, int $networkCode, \DateTimeImmutable $now): int
    {
        if ($rows === []) {
            return 0;
        }

        foreach ($rows as $row) {
            $this->entityManager->getConnection()->executeStatement(
                <<<SQL
INSERT INTO account_metric (
    account_id, total_transactions, native_balance, payments_count, trades_count, rank_score, rank_position, metric_updated_at
)
SELECT
    a.id, :transactions_per_hour, :native_balance, 0, 0, :rank_score, :rank_position, :metric_updated_at
FROM account a
WHERE a.address = :address AND a.network = :network
ON DUPLICATE KEY UPDATE
    total_transactions = VALUES(total_transactions),
    native_balance = VALUES(native_balance),
    rank_score = VALUES(rank_score),
    rank_position = VALUES(rank_position),
    metric_updated_at = VALUES(metric_updated_at)
SQL,
                [
                    'transactions_per_hour' => (string) $row['transactions_per_hour'],
                    'native_balance' => (string) $row['native_balance'],
                    'rank_score' => (string) $row['rank_score'],
                    'rank_position' => (int) $row['rank_position'],
                    'metric_updated_at' => $now->format('Y-m-d H:i:s'),
                    'address' => (string) $row['address'],
                    'network' => $networkCode,
                ],
                [
                    'transactions_per_hour' => ParameterType::INTEGER,
                    'rank_position' => ParameterType::INTEGER,
                    'network' => ParameterType::INTEGER,
                ]
            );
        }

        return count($rows);
    }

    private function normalizeDecimal(float $value, int $scale): string
    {
        if (!is_finite($value)) {
            $value = 0.0;
        }

        return number_format($value, $scale, '.', '');
    }

    /**
     * @param list<string> $addresses
     * @return array<string,string>
     */
    private function loadTransactionsPerHourMap(Connection $horizonConnection, array $addresses): array
    {
        $addresses = array_values(array_unique(array_filter(array_map('trim', $addresses), static fn (string $a): bool => $a !== '')));
        if ($addresses === []) {
            return [];
        }

        $columns = $this->loadTableColumns($horizonConnection, 'history_transactions');
        if ($columns === []) {
            return [];
        }

        $accountColumn = null;
        foreach (['source_account', 'account_id', 'account'] as $candidate) {
            if (isset($columns[$candidate])) {
                $accountColumn = $candidate;
                break;
            }
        }
        if ($accountColumn === null) {
            return [];
        }

        $joinSql = '';
        $timeSql = '';
        $params = [];
        $types = [];
        $from = new \DateTimeImmutable('-1 hour', new \DateTimeZone('UTC'));

        if (isset($columns['closed_at'])) {
            $timeSql = 'ht.closed_at >= :from_dt';
            $params['from_dt'] = $from->format('Y-m-d H:i:s');
            $types['from_dt'] = ParameterType::STRING;
        } elseif (isset($columns['created_at'])) {
            $timeSql = 'ht.created_at >= :from_dt';
            $params['from_dt'] = $from->format('Y-m-d H:i:s');
            $types['from_dt'] = ParameterType::STRING;
        } elseif (isset($columns['ledger_close_time'])) {
            $timeSql = 'ht.ledger_close_time >= :from_dt';
            $params['from_dt'] = $from->format('Y-m-d H:i:s');
            $types['from_dt'] = ParameterType::STRING;
        } elseif (isset($columns['ledger_seq'])) {
            $ledgerColumns = $this->loadTableColumns($horizonConnection, 'history_ledgers');
            if (isset($ledgerColumns['sequence'], $ledgerColumns['closed_at'])) {
                $joinSql = ' INNER JOIN history_ledgers hl ON hl.sequence = ht.ledger_seq';
                $timeSql = 'hl.closed_at >= :from_dt';
                $params['from_dt'] = $from->format('Y-m-d H:i:s');
                $types['from_dt'] = ParameterType::STRING;
            }
        }

        if ($timeSql === '') {
            return [];
        }

        $result = [];
        foreach (array_chunk($addresses, 500) as $chunk) {
            $rows = $horizonConnection->fetchAllAssociative(
                <<<SQL
SELECT ht.{$accountColumn} AS address, COUNT(*) AS tx_per_hour
FROM history_transactions ht
{$joinSql}
WHERE ht.{$accountColumn} IN (:addresses)
  AND {$timeSql}
GROUP BY ht.{$accountColumn}
SQL,
                $params + ['addresses' => $chunk],
                $types + ['addresses' => ArrayParameterType::STRING]
            );

            foreach ($rows as $row) {
                $address = trim((string) ($row['address'] ?? ''));
                if ($address === '') {
                    continue;
                }
                $result[$address] = (string) ((int) ($row['tx_per_hour'] ?? 0));
            }
        }

        return $result;
    }

    /**
     * @return array<string,true>
     */
    private function loadTableColumns(Connection $horizonConnection, string $table): array
    {
        $rows = $horizonConnection->fetchAllAssociative(
            'SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = :table',
            ['table' => $table]
        );

        $set = [];
        foreach ($rows as $row) {
            $name = strtolower(trim((string) ($row['column_name'] ?? '')));
            if ($name !== '') {
                $set[$name] = true;
            }
        }

        return $set;
    }

    /**
     * @param list<array{address:string,native_balance_stroops:string,importance_score:float}> $rows
     * @param array<string,array{label:?string,verified:bool}> $csvLabels
     * @return list<array{address:string,native_balance_stroops:string,importance_score:float}>
     */
    private function prioritizeCsvRows(Connection $horizonConnection, array $rows, array $csvLabels): array
    {
        if ($csvLabels === []) {
            return $rows;
        }

        $rowsByAddress = [];
        foreach ($rows as $row) {
            $address = trim((string) ($row['address'] ?? ''));
            if ($address === '') {
                continue;
            }
            $row['importance_score'] = (float) ($row['importance_score'] ?? 0.0);
            $rowsByAddress[$address] = $row;
        }

        $missingCsvAddresses = [];
        foreach (array_keys($csvLabels) as $address) {
            if (!isset($rowsByAddress[$address])) {
                $missingCsvAddresses[] = $address;
            }
        }

        if ($missingCsvAddresses !== []) {
            foreach (array_chunk($missingCsvAddresses, 500) as $chunk) {
                $fetched = $horizonConnection->fetchAllAssociative(
                    <<<SQL
SELECT account_id AS address, CAST(COALESCE(balance, 0) AS TEXT) AS native_balance_stroops
FROM accounts
WHERE account_id IN (:addresses)
SQL,
                    ['addresses' => $chunk],
                    ['addresses' => ArrayParameterType::STRING]
                );

                foreach ($fetched as $row) {
                    $address = trim((string) ($row['address'] ?? ''));
                    if ($address === '') {
                        continue;
                    }
                    $rowsByAddress[$address] = [
                        'address' => $address,
                        'native_balance_stroops' => (string) ($row['native_balance_stroops'] ?? '0'),
                        'importance_score' => 0.0,
                    ];
                }
            }
        }

        foreach (array_keys($csvLabels) as $address) {
            if (!isset($rowsByAddress[$address])) {
                $rowsByAddress[$address] = [
                    'address' => $address,
                    'native_balance_stroops' => '0',
                    'importance_score' => 0.0,
                ];
            }
        }

        $allRows = array_values($rowsByAddress);
        usort($allRows, static function (array $a, array $b) use ($csvLabels): int {
            $aAddress = (string) ($a['address'] ?? '');
            $bAddress = (string) ($b['address'] ?? '');
            $aCsv = isset($csvLabels[$aAddress]) ? 1 : 0;
            $bCsv = isset($csvLabels[$bAddress]) ? 1 : 0;
            if ($aCsv !== $bCsv) {
                return $bCsv <=> $aCsv;
            }

            return ((float) ($b['importance_score'] ?? 0.0)) <=> ((float) ($a['importance_score'] ?? 0.0));
        });

        return $allRows;
    }

    private function toXlmDecimal(string $rawBalance, string $balanceUnit): string
    {
        $normalized = trim($rawBalance);
        if ($normalized === '' || !preg_match('/^-?\d+(?:\.\d+)?$/', $normalized)) {
            return '0.0000000';
        }

        $unit = $balanceUnit;
        if ($unit === 'auto') {
            $unit = str_contains($normalized, '.') ? 'xlm' : 'stroops';
        }

        if ($unit === 'xlm') {
            return bcadd($normalized, '0', 7);
        }

        return bcdiv($normalized, '10000000', 7);
    }

    /**
     * @return array<string,array{label:?string,verified:bool}>
     */
    private function loadCsvLabels(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $handle = fopen($file, 'r');
        if ($handle === false) {
            return [];
        }

        $map = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if ($row === [null] || count($row) < 2) {
                continue;
            }
            $address = trim((string) $row[0]);
            $label = trim((string) ($row[1] ?? ''));
            $verifiedRaw = strtolower(trim((string) ($row[2] ?? '0')));
            if ($address === '') {
                continue;
            }

            $map[$address] = [
                'label' => $label !== '' ? $label : null,
                'verified' => in_array($verifiedRaw, ['1', 'true', 'yes'], true),
            ];
        }

        fclose($handle);

        return $map;
    }

    private function resolveHorizonConnection(string $network): Connection
    {
        $connectionName = match ($network) {
            'mainnet' => 'horizon_mainnet',
            'testnet' => 'horizon_testnet',
            default => 'horizon_testnet',
        };

        return $this->doctrine->getConnection($connectionName);
    }
}
