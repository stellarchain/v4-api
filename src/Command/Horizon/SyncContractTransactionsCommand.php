<?php

namespace App\Command\Horizon;

use App\Command\Support\NetworkOptionTrait;
use App\Service\ContractTxEnrichmentService;
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
    name: 'app:horizon:enrich-contract-transactions',
    description: 'Enrich existing contract transactions by tx_hash using Horizon data.',
)]
final class SyncContractTransactionsCommand extends Command
{
    use NetworkOptionTrait;
    private const RECHECK_UNCHANGED_AFTER_HOURS = 24;
    private const RECHECK_FAILED_AFTER_MINUTES = 30;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.contracts_connection')]
        private readonly Connection $connection,
        private readonly ContractTxEnrichmentService $txEnrichmentService,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addNetworkOption('mainnet|testnet|futurenet', 'testnet')
            ->addOption('contract', null, InputOption::VALUE_REQUIRED, 'Sync only a specific contract id')
            ->addOption('skip-contract', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Contract id(s) to skip during enrich. Can be provided multiple times.')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Contracts batch size', 50)
            ->addOption('tx-batch-size', null, InputOption::VALUE_REQUIRED, 'Transaction hashes batch size for Horizon enrichment', 50)
            ->addOption('max-transactions', null, InputOption::VALUE_REQUIRED, 'Max candidate transactions per contract (for incremental runs)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not write into local DB');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $network = $this->resolveNetworkOption($input, $this->stellarNetworkResolver);
        $networkCode = $this->resolveNetworkCodeOption($input, $this->stellarNetworkResolver);
        $singleContract = $this->normalizeNullableString($input->getOption('contract'));
        $batchSize = $this->parseRequiredPositiveIntOption($input->getOption('batch-size'));
        if ($batchSize === null) {
            $io->error('--batch-size must be a positive integer.');
            return Command::FAILURE;
        }

        $txBatchSize = $this->parseRequiredPositiveIntOption($input->getOption('tx-batch-size'));
        if ($txBatchSize === null) {
            $io->error('--tx-batch-size must be a positive integer.');
            return Command::FAILURE;
        }

        $maxTransactions = $this->parsePositiveIntOption($input->getOption('max-transactions'));
        if ($maxTransactions === -1) {
            $io->error('--max-transactions must be a positive integer.');
            return Command::FAILURE;
        }
        $dryRun = (bool) $input->getOption('dry-run');

        $contracts = $this->loadContracts($networkCode, $singleContract);
        $skipContracts = $this->normalizeSkipContractIds($input);
        if ($skipContracts !== []) {
            $contracts = array_values(array_filter(
                $contracts,
                static function (array $row) use ($skipContracts): bool {
                    $contractId = strtoupper(trim((string) ($row['contract_id'] ?? '')));
                    return $contractId === '' || !isset($skipContracts[$contractId]);
                }
            ));
        }
        if ($contracts === []) {
            $io->warning('No contracts found for selected filter.');
            return Command::SUCCESS;
        }

        $io->writeln(sprintf(
            'Network=%s, contracts=%d%s',
            $network,
            count($contracts),
            $dryRun ? ', dry-run=1' : ''
        ));

        $metrics = [
            'contracts_total' => count($contracts),
            'contracts_processed' => 0,
            'contracts_failed' => 0,
            'transactions_candidates' => 0,
            'transactions_processed' => 0,
            'rows_updated' => 0,
            'rows_unchanged' => 0,
            'rows_failed' => 0,
            'rate_limit_retries' => 0,
            'rate_limit_wait_seconds' => 0,
            'remote_errors' => 0,
        ];
        $errors = [];

        $io->progressStart(count($contracts));
        $io->newLine();
        foreach (array_chunk($contracts, $batchSize) as $chunk) {
            foreach ($chunk as $contract) {
                $metrics['contracts_processed']++;
                $contractId = (string) $contract['contract_id'];
                $localContractId = (int) $contract['id'];
                $io->writeln(sprintf('[%s] enrichment started', $contractId));

                try {
                    $syncResult = $this->txEnrichmentService->enrichForContract(
                        $localContractId,
                        $network,
                        $dryRun,
                        $txBatchSize,
                        $maxTransactions,
                        $this->buildBatchProgressLogger($io, $contractId),
                    );
                    if (($syncResult['ok'] ?? false) !== true) {
                        $metrics['contracts_failed']++;
                        $errors[] = sprintf('%s: %s', $contractId, (string) ($syncResult['error'] ?? 'unknown error'));
                        $io->progressAdvance();
                        continue;
                    }

                    $metrics['transactions_candidates'] += (int) ($syncResult['transactionCandidates'] ?? 0);
                    $metrics['transactions_processed'] += (int) ($syncResult['processed'] ?? 0);
                    $metrics['rows_updated'] += (int) ($syncResult['rowsUpdated'] ?? 0);
                    $metrics['rows_unchanged'] += (int) ($syncResult['rowsUnchanged'] ?? 0);
                    $metrics['rows_failed'] += (int) ($syncResult['rowsFailed'] ?? 0);
                    $metrics['rate_limit_retries'] += (int) ($syncResult['rateLimitRetries'] ?? 0);
                    $metrics['rate_limit_wait_seconds'] += (int) ($syncResult['rateLimitWaitSeconds'] ?? 0);
                    $metrics['remote_errors'] += (int) ($syncResult['remoteErrors'] ?? 0);
                } catch (\Throwable $e) {
                    $metrics['contracts_failed']++;
                    $errors[] = sprintf('%s: %s', $contractId, $e->getMessage());
                }

                $io->progressAdvance();
            }
        }
        $io->progressFinish();

        $io->newLine();
        $io->table(
            ['Metric', 'Value'],
            [
                ['contracts_total', (string) $metrics['contracts_total']],
                ['contracts_processed', (string) $metrics['contracts_processed']],
                ['contracts_failed', (string) $metrics['contracts_failed']],
                ['transactions_candidates', (string) $metrics['transactions_candidates']],
                ['transactions_processed', (string) $metrics['transactions_processed']],
                ['rows_updated', (string) $metrics['rows_updated']],
                ['rows_unchanged', (string) $metrics['rows_unchanged']],
                ['rows_failed', (string) $metrics['rows_failed']],
                ['rate_limit_retries', (string) $metrics['rate_limit_retries']],
                ['rate_limit_wait_seconds', (string) $metrics['rate_limit_wait_seconds']],
                ['remote_errors', (string) $metrics['remote_errors']],
            ]
        );

        if ($errors !== []) {
            $io->warning('Sample errors:');
            foreach (array_slice($errors, 0, 10) as $error) {
                $io->writeln('- ' . $error);
            }
        }

        $io->success($dryRun ? 'Dry-run completed.' : 'Contract transactions enrichment completed.');

        return Command::SUCCESS;
    }

    /**
     * @return array<int,array{id:int,contract_id:string}>
     */
    private function loadContracts(int $networkCode, ?string $singleContract): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $staleUnchanged = $now->sub(new \DateInterval('PT' . self::RECHECK_UNCHANGED_AFTER_HOURS . 'H'))->format('Y-m-d H:i:s');
        $staleFailed = $now->sub(new \DateInterval('PT' . self::RECHECK_FAILED_AFTER_MINUTES . 'M'))->format('Y-m-d H:i:s');

        if ($singleContract !== null) {
            return $this->connection->fetchAllAssociative(
                'SELECT id, contract_id FROM contracts WHERE network = :network AND contract_id = :contract_id ORDER BY id ASC',
                [
                    'network' => $networkCode,
                    'contract_id' => $singleContract,
                ],
                [
                    'network' => ParameterType::INTEGER,
                ],
            );
        }

        return $this->connection->fetchAllAssociative(
            'SELECT c.id, c.contract_id
             FROM contracts c
             WHERE c.network = :network
               AND EXISTS (
                   SELECT 1
                   FROM contract_transactions ct
                   WHERE ct.contract_id = c.id
                     AND (
                         ct.source_account IS NULL
                         OR ct.host_functions IS NULL
                         OR ct.host_functions LIKE \'%"functionName":"transfer","args":[null,%\'
                         OR ct.host_functions LIKE \'%"invokeContracts":[]%\'
                         OR ct.fee_charged = 0
                         OR ct.max_fee = 0
                         OR ct.total_operations IS NULL
                         OR ct.created_at IS NULL
                     )
                     AND (
                         ct.enrichment_checked_at IS NULL
                         OR (
                             ct.enrichment_status = \'failed\'
                             AND ct.enrichment_checked_at < :stale_failed_before
                         )
                         OR (
                             (ct.enrichment_status IS NULL OR ct.enrichment_status <> \'failed\')
                             AND ct.enrichment_checked_at < :stale_unchanged_before
                         )
                     )
               )
             ORDER BY c.id ASC',
            [
                'network' => $networkCode,
                'stale_unchanged_before' => $staleUnchanged,
                'stale_failed_before' => $staleFailed,
            ],
            [
                'network' => ParameterType::INTEGER,
                'stale_unchanged_before' => ParameterType::STRING,
                'stale_failed_before' => ParameterType::STRING,
            ],
        );
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
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

        return -1;
    }

    private function parseRequiredPositiveIntOption(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }

    /**
     * @return array<string,bool>
     */
    private function normalizeSkipContractIds(InputInterface $input): array
    {
        $raw = $input->getOption('skip-contract');
        if (!is_array($raw) || $raw === []) {
            return [];
        }

        $result = [];
        foreach ($raw as $value) {
            if (!is_string($value)) {
                continue;
            }

            $trimmed = strtoupper(trim($value));
            if ($trimmed === '') {
                continue;
            }

            $result[$trimmed] = true;
        }

        return $result;
    }

    /**
     * @return callable(array<string,mixed>):void
     */
    private function buildBatchProgressLogger(SymfonyStyle $io, string $contractId): callable
    {
        return static function (array $progress) use ($io, $contractId): void {
            $phase = (string) ($progress['phase'] ?? '');
            if ($phase === 'no_candidates') {
                $io->writeln(sprintf('[%s] no candidate transactions to enrich', $contractId));
                return;
            }

            if ($phase === 'batch_start') {
                $io->writeln(sprintf(
                    '[%s] batch %d/%d started (size=%d)',
                    $contractId,
                    (int) ($progress['batchIndex'] ?? 0),
                    (int) ($progress['batchTotal'] ?? 0),
                    (int) ($progress['batchSize'] ?? 0),
                ));
                return;
            }

            if ($phase === 'rate_limited') {
                $io->writeln(sprintf(
                    '[%s] rate-limited on %s (tx=%s, attempt=%d/%d, wait=%ds, remaining=%s, limit=%s, reset=%s)',
                    $contractId,
                    (string) ($progress['scope'] ?? 'unknown'),
                    (string) ($progress['txHash'] ?? ''),
                    (int) ($progress['attempt'] ?? 0),
                    (int) ($progress['maxRetries'] ?? 0),
                    (int) ($progress['waitSeconds'] ?? 0),
                    (string) ($progress['rateLimitRemaining'] ?? '?'),
                    (string) ($progress['rateLimitLimit'] ?? '?'),
                    (string) ($progress['rateLimitReset'] ?? '?'),
                ));
                return;
            }

            if ($phase === 'rate_limit_resumed') {
                $io->writeln(sprintf(
                    '[%s] resumed after rate-limit on %s (tx=%s)',
                    $contractId,
                    (string) ($progress['scope'] ?? 'unknown'),
                    (string) ($progress['txHash'] ?? ''),
                ));
                return;
            }

            if ($phase === 'batch_done') {
                $errorSample = is_array($progress['remoteErrorSample'] ?? null)
                    ? $progress['remoteErrorSample']
                    : null;
                $errorSampleText = '';
                if ($errorSample !== null) {
                    $errorSampleText = sprintf(
                        ' sample_error=%s:%s (tx=%s%s)',
                        (string) ($errorSample['scope'] ?? 'unknown'),
                        (string) ($errorSample['message'] ?? 'unknown'),
                        (string) ($errorSample['txHash'] ?? ''),
                        isset($errorSample['httpCode']) && $errorSample['httpCode'] !== null
                            ? ', http=' . (int) $errorSample['httpCode']
                            : ''
                    );
                }
                $io->writeln(sprintf(
                    '[%s] batch %d/%d done | processed=%d updated=%d unchanged=%d failed=%d wait=%ds remote_errors=%d%s',
                    $contractId,
                    (int) ($progress['batchIndex'] ?? 0),
                    (int) ($progress['batchTotal'] ?? 0),
                    (int) ($progress['processed'] ?? 0),
                    (int) ($progress['rowsUpdated'] ?? 0),
                    (int) ($progress['rowsUnchanged'] ?? 0),
                    (int) ($progress['rowsFailed'] ?? 0),
                    (int) ($progress['batchRateLimitWaitSeconds'] ?? 0),
                    (int) ($progress['batchRemoteErrors'] ?? 0),
                    $errorSampleText,
                ));
            }
        };
    }

}
