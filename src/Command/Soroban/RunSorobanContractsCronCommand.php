<?php

declare(strict_types=1);

namespace App\Command\Soroban;

use App\Command\Support\NetworkOptionTrait;
use App\Service\ContractTxEnrichmentService;
use App\Service\ContractTxSyncService;
use App\Service\Stellar\Soroban\SorobanContractInspector;
use App\Service\Stellar\Soroban\SorobanScValMapper;
use App\Service\Stellar\Soroban\SorobanServerFactory;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Cache\CacheItemPoolInterface;
use Soneso\StellarSDK\Crypto\StrKey;
use Soneso\StellarSDK\Soroban\SorobanServer;
use Soneso\StellarSDK\Xdr\XdrSCVal;
use Soneso\StellarSDK\Xdr\XdrContractExecutableType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:soroban:contracts:cron',
    description: 'Incremental Soroban cron: scan contracts and sync contract transactions/events/storage into local DB.',
)]
final class RunSorobanContractsCronCommand extends Command
{
    use NetworkOptionTrait;

    private const DEFAULT_LOOKBACK_LEDGERS = 30000;
    private const DEFAULT_SYNC_CONTRACTS_LIMIT = 150;
    private const DEFAULT_SYNC_START_ID = 0;
    private const DEFAULT_HORIZON_DISCOVERY_OP_LIMIT = 50000;
    private const CONTRACT_OPERATION_TYPES = [24, 25, 26];

    public function __construct(
        private readonly SorobanServerFactory $sorobanServerFactory,
        private readonly SorobanContractInspector $sorobanContractInspector,
        private readonly SorobanScValMapper $sorobanScValMapper,
        private readonly ContractTxSyncService $contractTxSyncService,
        private readonly ContractTxEnrichmentService $contractTxEnrichmentService,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
        private readonly ManagerRegistry $doctrine,
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private readonly Connection $connection,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addNetworkOption('mainnet|testnet|futurenet', 'mainnet')
            ->addOption('lookback-ledgers', null, InputOption::VALUE_REQUIRED, 'Scan lookback window when scan cursor is empty.', (string) self::DEFAULT_LOOKBACK_LEDGERS)
            ->addOption('start-ledger', null, InputOption::VALUE_REQUIRED, 'Override inclusive start ledger (use with --end-ledger)')
            ->addOption('end-ledger', null, InputOption::VALUE_REQUIRED, 'Override inclusive end ledger (use with --start-ledger)')
            ->addOption('max-contracts', null, InputOption::VALUE_REQUIRED, 'Max new contracts to persist during scan. Use 0 for unlimited.', '0')
            ->addOption('sync-contracts-limit', null, InputOption::VALUE_REQUIRED, 'How many contracts to process for tx/events sync this run.', (string) self::DEFAULT_SYNC_CONTRACTS_LIMIT)
            ->addOption('sync-found-only', null, InputOption::VALUE_NONE, 'Sync/enrich only contracts discovered during current scan run.')
            ->addOption('skip-contract', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Contract id(s) to skip during sync. Can be provided multiple times.')
            ->addOption('skip-horizon-discovery', null, InputOption::VALUE_NONE, 'Skip Horizon DB fallback discovery for invoke operations without events.')
            ->addOption('horizon-discovery-op-limit', null, InputOption::VALUE_REQUIRED, 'How many latest contract operations to inspect in Horizon fallback discovery.', (string) self::DEFAULT_HORIZON_DISCOVERY_OP_LIMIT)
            ->addOption('with-horizon-fallback', null, InputOption::VALUE_NONE, 'Enable expensive Horizon fallback while syncing contract transactions.')
            ->addOption('skip-horizon-enrich', null, InputOption::VALUE_NONE, 'Skip lightweight Horizon tx_hash enrichment (host_functions/source/fees).')
            ->addOption('force-sync-from-start', null, InputOption::VALUE_NONE, 'Force sync phase to use requested start ledger as-is (disable local resume shortcut).')
            ->addOption('horizon-enrich-tx-batch-size', null, InputOption::VALUE_REQUIRED, 'Tx hash batch size for lightweight Horizon enrich.', '100')
            ->addOption('horizon-enrich-max-transactions', null, InputOption::VALUE_REQUIRED, 'Max candidate tx per contract for lightweight Horizon enrich. Use 0 for unlimited.', '500')
            ->addOption('reset-scan-cursor', null, InputOption::VALUE_NONE, 'Reset scan ledger cursor before run.')
            ->addOption('reset-sync-cursor', null, InputOption::VALUE_NONE, 'Reset contract sync cursor before run.')
            ->addOption('ignore-throttle', null, InputOption::VALUE_NONE, 'Disable Horizon pacing/retry waits for this run.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Run without writes where supported.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $network = $this->resolveNetworkOption($input, $this->stellarNetworkResolver);
        $networkCode = $this->resolveNetworkCodeOption($input, $this->stellarNetworkResolver);
        $dryRun = (bool) $input->getOption('dry-run');
        $ignoreThrottle = (bool) $input->getOption('ignore-throttle');
        $syncFoundOnly = (bool) $input->getOption('sync-found-only');
        $skipHorizonDiscovery = (bool) $input->getOption('skip-horizon-discovery');
        $withHorizonFallback = (bool) $input->getOption('with-horizon-fallback');
        $skipHorizonEnrich = (bool) $input->getOption('skip-horizon-enrich');
        $forceSyncFromStart = (bool) $input->getOption('force-sync-from-start');
        $horizonDiscoveryOpLimit = $this->parsePositiveIntOption($input->getOption('horizon-discovery-op-limit'));
        $horizonEnrichTxBatchSize = $this->parsePositiveIntOption($input->getOption('horizon-enrich-tx-batch-size'));
        $horizonEnrichMaxTransactions = $this->parseNonNegativeIntOption($input->getOption('horizon-enrich-max-transactions'));
        $scanRunStartedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        if ($ignoreThrottle) {
            putenv('HORIZON_NO_THROTTLE=1');
            putenv('HORIZON_MIN_REQUEST_DELAY_MS=0');
            putenv('HORIZON_RETRY_MAX=0');
        }
        putenv('SOROBAN_SYNC_FORCE_START=' . ($forceSyncFromStart ? '1' : '0'));
        // Default for cron: avoid per-contract full Horizon ledger fallback (memory/CPU heavy).
        putenv('SOROBAN_SYNC_SKIP_HORIZON_FALLBACK=' . ($withHorizonFallback ? '0' : '1'));

        $maxContracts = $this->parseNonNegativeIntOption($input->getOption('max-contracts'));
        $syncContractsLimit = $this->parsePositiveIntOption($input->getOption('sync-contracts-limit'));
        $lookbackLedgers = $this->parsePositiveIntOption($input->getOption('lookback-ledgers'));
        if ($maxContracts === null || $syncContractsLimit === null || $lookbackLedgers === null || $horizonDiscoveryOpLimit === null || $horizonEnrichTxBatchSize === null || $horizonEnrichMaxTransactions === null) {
            $io->error('--max-contracts and --horizon-enrich-max-transactions must be >= 0; --sync-contracts-limit, --lookback-ledgers, --horizon-discovery-op-limit and --horizon-enrich-tx-batch-size must be positive integers.');
            return Command::FAILURE;
        }

        if ((bool) $input->getOption('reset-scan-cursor')) {
            $this->deleteCursor($this->scanCursorKey($network));
        }
        if ((bool) $input->getOption('reset-sync-cursor')) {
            $this->deleteCursor($this->syncCursorKey($network));
        }

        $latestLedger = $this->loadLatestLedger($network);
        if ($latestLedger === null) {
            $io->error('Could not fetch latest ledger from Soroban RPC.');
            return Command::FAILURE;
        }

        [$startLedger, $endLedger] = $this->resolveLedgerRange($input, $network, $latestLedger, $lookbackLedgers, $io);
        if ($startLedger === null || $endLedger === null) {
            return Command::FAILURE;
        }

        $io->writeln(sprintf(
            'network=%s latest=%d scan=%d..%d dry_run=%d no_throttle=%d',
            $network,
            $latestLedger,
            $startLedger,
            $endLedger,
            $dryRun ? 1 : 0,
            $ignoreThrottle ? 1 : 0
        ));

        $scanArgs = [
            '--network' => $network,
            '--start-ledger' => (string) $startLedger,
            '--end-ledger' => (string) $endLedger,
            '--dry-run' => $dryRun,
        ];
        if ($maxContracts > 0) {
            $scanArgs['--max-contracts'] = (string) $maxContracts;
        }
        $scanExit = $this->runChild($output, 'app:soroban:scan-contracts', $scanArgs);
        if ($scanExit !== Command::SUCCESS) {
            $io->error('Contract scan failed.');
            return Command::FAILURE;
        }

        if (!$dryRun) {
            $this->storeCursor($this->scanCursorKey($network), (string) $endLedger);
        }

        $horizonDiscovered = 0;
        if (!$skipHorizonDiscovery) {
            $horizonDiscovered = $this->seedContractsFromHorizonOperations($network, $networkCode, $horizonDiscoveryOpLimit, $dryRun, $io);
            if ($horizonDiscovered > 0) {
                $io->writeln(sprintf('Horizon fallback discovery inserted=%d', $horizonDiscovered));
            }
        }

        $syncCursor = $this->loadSyncCursor($network);
        $contracts = $syncFoundOnly
            ? $this->loadContractsDiscoveredSince($networkCode, $scanRunStartedAt, $syncContractsLimit)
            : $this->loadContractsBatch($networkCode, $syncCursor, $syncContractsLimit);
        $skipContracts = $this->normalizeSkipContractIds($input);
        $skippedByOption = 0;
        if ($skipContracts !== []) {
            $before = count($contracts);
            $contracts = array_values(array_filter(
                $contracts,
                static function (array $row) use ($skipContracts): bool {
                    $contractId = strtoupper(trim((string) ($row['contract_id'] ?? '')));
                    return $contractId === '' || !isset($skipContracts[$contractId]);
                }
            ));
            $skippedByOption = max(0, $before - count($contracts));
        }
        if ($contracts === []) {
            $io->warning($syncFoundOnly
                ? 'No newly discovered contracts to sync in current run.'
                : 'No contracts available for sync on selected network.');
            return Command::SUCCESS;
        }

        $metrics = [
            'contracts_selected' => count($contracts),
            'contracts_metadata_updated' => 0,
            'contracts_ok' => 0,
            'contracts_failed' => 0,
            'events_collected' => 0,
            'transactions_collected' => 0,
            'rows_inserted' => 0,
            'rows_updated' => 0,
            'rows_unchanged' => 0,
            'enrich_processed' => 0,
            'enrich_rows_updated' => 0,
            'enrich_rows_unchanged' => 0,
            'enrich_rows_failed' => 0,
            'enrich_remote_errors' => 0,
        ];
        $lastSyncedId = $syncCursor;
        $sorobanServer = $this->sorobanServerFactory->create($network);
        $contractsTotal = count($contracts);
        $io->writeln(sprintf(
            'Starting contract sync phase: selected=%d, skipped_by_option=%d, horizon_enrich=%d',
            $contractsTotal,
            $skippedByOption,
            $skipHorizonEnrich ? 0 : 1
        ));

        $contractIndex = 0;
        foreach ($contracts as $contract) {
            $contractIndex++;
            $contractDbId = (int) ($contract['id'] ?? 0);
            $contractId = (string) ($contract['contract_id'] ?? '');
            if ($contractDbId <= 0 || $contractId === '') {
                $io->writeln(sprintf('[%d/%d] skipped invalid contract row (id=%d).', $contractIndex, $contractsTotal, $contractDbId));
                continue;
            }

            // Defensive guard in case a contract slips past pre-filtering.
            $normalizedContractId = $this->sorobanContractInspector->normalizeContractId(trim($contractId));
            if (is_string($normalizedContractId) && $normalizedContractId !== '' && isset($skipContracts[strtoupper($normalizedContractId)])) {
                $io->writeln(sprintf('[%d/%d] contract=%s skipped by --skip-contract', $contractIndex, $contractsTotal, $contractId));
                continue;
            }

            $contractStartedAt = microtime(true);
            $io->writeln(sprintf('[%d/%d] contract=%s (db_id=%d) sync started', $contractIndex, $contractsTotal, $contractId, $contractDbId));
            $lastSyncedId = max($lastSyncedId, $contractDbId);
            if ($this->enrichContractMetadataIfNeeded($contract, $network, $sorobanServer, $dryRun)) {
                $metrics['contracts_metadata_updated']++;
                $io->writeln(sprintf('[%d/%d] contract=%s metadata updated', $contractIndex, $contractsTotal, $contractId));
            }

            $result = $this->contractTxSyncService->syncForContract(
                $contractDbId,
                $contractId,
                $network,
                $dryRun,
                $startLedger,
                $endLedger,
                function (array $progress) use ($io, $contractId, $contractIndex, $contractsTotal): void {
                    $phase = (string) ($progress['phase'] ?? '');
                    if ($phase === 'range-adjusted') {
                        $io->writeln(sprintf(
                            '[%d/%d] contract=%s sync range adjusted start=%s end=%s',
                            $contractIndex,
                            $contractsTotal,
                            $contractId,
                            (string) ($progress['scanStartLedger'] ?? '?'),
                            (string) ($progress['scanEndLedger'] ?? '?')
                        ));
                        return;
                    }

                    if ($phase === 'resume-ledger-adjusted') {
                        $io->writeln(sprintf(
                            '[%d/%d] contract=%s resume adjusted start=%s -> %s (end=%s)',
                            $contractIndex,
                            $contractsTotal,
                            $contractId,
                            (string) ($progress['requestedStartLedger'] ?? '?'),
                            (string) ($progress['effectiveStartLedger'] ?? '?'),
                            (string) ($progress['endLedger'] ?? '?')
                        ));
                        return;
                    }

                    if ($phase === 'page') {
                        $io->writeln(sprintf(
                            '[%d/%d] contract=%s sync page=%s events=%s tx=%s scanned=%s/%s',
                            $contractIndex,
                            $contractsTotal,
                            $contractId,
                            (string) ($progress['pagesProcessed'] ?? '?'),
                            (string) ($progress['eventsCollected'] ?? '?'),
                            (string) ($progress['transactionsCollected'] ?? '?'),
                            (string) ($progress['scannedLedgers'] ?? '?'),
                            (string) ($progress['totalLedgers'] ?? '?')
                        ));
                        return;
                    }

                    if ($phase === 'window') {
                        $io->writeln(sprintf(
                            '[%d/%d] contract=%s sync window %s..%s done | events=%s tx=%s',
                            $contractIndex,
                            $contractsTotal,
                            $contractId,
                            (string) ($progress['windowStartLedger'] ?? '?'),
                            (string) ($progress['windowEndLedger'] ?? '?'),
                            (string) ($progress['eventsCollected'] ?? '?'),
                            (string) ($progress['transactionsCollected'] ?? '?')
                        ));
                    }
                }
            );

            if (($result['ok'] ?? false) !== true) {
                $metrics['contracts_failed']++;
                $io->writeln(sprintf(
                    '[%d/%d] contract=%s failed: %s',
                    $contractIndex,
                    $contractsTotal,
                    $contractId,
                    (string) ($result['error'] ?? 'unknown error')
                ));
                continue;
            }

            $metrics['contracts_ok']++;
            $metrics['events_collected'] += (int) ($result['eventsCollected'] ?? 0);
            $metrics['transactions_collected'] += (int) ($result['transactionsCollected'] ?? 0);
            $metrics['rows_inserted'] += (int) ($result['rowsInserted'] ?? 0);
            $metrics['rows_updated'] += (int) ($result['rowsUpdated'] ?? 0);
            $metrics['rows_unchanged'] += (int) ($result['rowsUnchanged'] ?? 0);
            $io->writeln(sprintf(
                '[%d/%d] contract=%s sync done | tx=%d events=%d inserted=%d updated=%d unchanged=%d',
                $contractIndex,
                $contractsTotal,
                $contractId,
                (int) ($result['transactionsCollected'] ?? 0),
                (int) ($result['eventsCollected'] ?? 0),
                (int) ($result['rowsInserted'] ?? 0),
                (int) ($result['rowsUpdated'] ?? 0),
                (int) ($result['rowsUnchanged'] ?? 0)
            ));

            if (!$dryRun && !$skipHorizonEnrich) {
                $io->writeln(sprintf('[%d/%d] contract=%s horizon enrich started', $contractIndex, $contractsTotal, $contractId));
                $enrich = $this->contractTxEnrichmentService->enrichForContract(
                    $contractDbId,
                    $network,
                    false,
                    $horizonEnrichTxBatchSize,
                    $horizonEnrichMaxTransactions > 0 ? $horizonEnrichMaxTransactions : null,
                    function (array $progress) use ($io, $contractId, $contractIndex, $contractsTotal): void {
                        $phase = (string) ($progress['phase'] ?? '');
                        if ($phase === 'batch_start') {
                            $io->writeln(sprintf(
                                '[%d/%d] contract=%s enrich batch %d/%d started (size=%d)',
                                $contractIndex,
                                $contractsTotal,
                                $contractId,
                                (int) ($progress['batchIndex'] ?? 0),
                                (int) ($progress['batchTotal'] ?? 0),
                                (int) ($progress['batchSize'] ?? 0)
                            ));
                            return;
                        }

                        if ($phase === 'batch_done') {
                            $io->writeln(sprintf(
                                '[%d/%d] contract=%s enrich batch %d/%d done | processed=%d updated=%d unchanged=%d failed=%d remote_errors=%d',
                                $contractIndex,
                                $contractsTotal,
                                $contractId,
                                (int) ($progress['batchIndex'] ?? 0),
                                (int) ($progress['batchTotal'] ?? 0),
                                (int) ($progress['processed'] ?? 0),
                                (int) ($progress['rowsUpdated'] ?? 0),
                                (int) ($progress['rowsUnchanged'] ?? 0),
                                (int) ($progress['rowsFailed'] ?? 0),
                                (int) ($progress['batchRemoteErrors'] ?? 0),
                            ));
                            return;
                        }

                        if ($phase === 'no_candidates') {
                            $io->writeln(sprintf('[%d/%d] contract=%s enrich has no candidate transactions', $contractIndex, $contractsTotal, $contractId));
                            return;
                        }

                        if ($phase === 'rate_limited') {
                            $io->writeln(sprintf(
                                '[%d/%d] contract=%s enrich rate-limited scope=%s tx=%s wait=%ss attempt=%d/%d',
                                $contractIndex,
                                $contractsTotal,
                                $contractId,
                                (string) ($progress['scope'] ?? ''),
                                (string) ($progress['txHash'] ?? ''),
                                (int) ($progress['waitSeconds'] ?? 0),
                                (int) ($progress['attempt'] ?? 0),
                                (int) ($progress['maxRetries'] ?? 0)
                            ));
                        }
                    }
                );
                if (($enrich['ok'] ?? false) === true) {
                    $metrics['enrich_processed'] += (int) ($enrich['processed'] ?? 0);
                    $metrics['enrich_rows_updated'] += (int) ($enrich['rowsUpdated'] ?? 0);
                    $metrics['enrich_rows_unchanged'] += (int) ($enrich['rowsUnchanged'] ?? 0);
                    $metrics['enrich_rows_failed'] += (int) ($enrich['rowsFailed'] ?? 0);
                    $metrics['enrich_remote_errors'] += (int) ($enrich['remoteErrors'] ?? 0);
                    $io->writeln(sprintf(
                        '[%d/%d] contract=%s horizon enrich done | processed=%d updated=%d unchanged=%d failed=%d remote_errors=%d',
                        $contractIndex,
                        $contractsTotal,
                        $contractId,
                        (int) ($enrich['processed'] ?? 0),
                        (int) ($enrich['rowsUpdated'] ?? 0),
                        (int) ($enrich['rowsUnchanged'] ?? 0),
                        (int) ($enrich['rowsFailed'] ?? 0),
                        (int) ($enrich['remoteErrors'] ?? 0)
                    ));
                }
            }

            $durationSeconds = (int) max(1, round(microtime(true) - $contractStartedAt));
            $io->writeln(sprintf('[%d/%d] contract=%s finished in %ds', $contractIndex, $contractsTotal, $contractId, $durationSeconds));
        }

        if (!$dryRun) {
            $this->storeCursor($this->syncCursorKey($network), (string) $lastSyncedId);
        }

        $io->table(
            ['Metric', 'Value'],
            [
                ['network', $network],
                ['latest_ledger', (string) $latestLedger],
                ['scan_start_ledger', (string) $startLedger],
                ['scan_end_ledger', (string) $endLedger],
                ['scan_max_contracts', $maxContracts > 0 ? (string) $maxContracts : 'unlimited'],
                ['contracts_selected', (string) $metrics['contracts_selected']],
                ['contracts_skipped_by_option', (string) $skippedByOption],
                ['sync_found_only', $syncFoundOnly ? '1' : '0'],
                ['horizon_discovery', $skipHorizonDiscovery ? '0' : '1'],
                ['horizon_discovery_op_limit', (string) $horizonDiscoveryOpLimit],
                ['horizon_discovered', (string) $horizonDiscovered],
                ['horizon_fallback', $withHorizonFallback ? '1' : '0'],
                ['horizon_enrich', $skipHorizonEnrich ? '0' : '1'],
                ['force_sync_from_start', $forceSyncFromStart ? '1' : '0'],
                ['horizon_enrich_tx_batch_size', (string) $horizonEnrichTxBatchSize],
                ['horizon_enrich_max_transactions', $horizonEnrichMaxTransactions > 0 ? (string) $horizonEnrichMaxTransactions : 'unlimited'],
                ['contracts_metadata_updated', (string) $metrics['contracts_metadata_updated']],
                ['contracts_ok', (string) $metrics['contracts_ok']],
                ['contracts_failed', (string) $metrics['contracts_failed']],
                ['events_collected', (string) $metrics['events_collected']],
                ['transactions_collected', (string) $metrics['transactions_collected']],
                ['rows_inserted', (string) $metrics['rows_inserted']],
                ['rows_updated', (string) $metrics['rows_updated']],
                ['rows_unchanged', (string) $metrics['rows_unchanged']],
                ['enrich_processed', (string) $metrics['enrich_processed']],
                ['enrich_rows_updated', (string) $metrics['enrich_rows_updated']],
                ['enrich_rows_unchanged', (string) $metrics['enrich_rows_unchanged']],
                ['enrich_rows_failed', (string) $metrics['enrich_rows_failed']],
                ['enrich_remote_errors', (string) $metrics['enrich_remote_errors']],
                ['next_scan_cursor', (string) $endLedger],
                ['next_sync_cursor', (string) $lastSyncedId],
            ]
        );

        $io->success($dryRun ? 'Soroban contracts cron dry-run completed.' : 'Soroban contracts cron completed.');

        return Command::SUCCESS;
    }

    /**
     * @return array{0:int|null,1:int|null}
     */
    private function resolveLedgerRange(
        InputInterface $input,
        string $network,
        int $latestLedger,
        int $lookbackLedgers,
        SymfonyStyle $io,
    ): array {
        $startOption = $input->getOption('start-ledger');
        $endOption = $input->getOption('end-ledger');

        if (($startOption === null) !== ($endOption === null)) {
            $io->error('Use both --start-ledger and --end-ledger together.');
            return [null, null];
        }

        if ($startOption !== null && $endOption !== null) {
            $start = $this->parsePositiveIntOption($startOption);
            $end = $this->parsePositiveIntOption($endOption);
            if ($start === null || $end === null) {
                $io->error('--start-ledger and --end-ledger must be positive integers.');
                return [null, null];
            }
            if ($start > $end) {
                [$start, $end] = [$end, $start];
            }
            return [$start, min($latestLedger, $end)];
        }

        $cursorRaw = $this->loadCursor($this->scanCursorKey($network));
        $cursor = is_string($cursorRaw) && ctype_digit($cursorRaw) ? (int) $cursorRaw : null;
        if ($cursor !== null && $cursor >= $latestLedger) {
            $start = max(1, $latestLedger - 1);
            return [$start, $latestLedger];
        }

        if ($cursor !== null) {
            return [max(1, $cursor + 1), $latestLedger];
        }

        $start = max(1, $latestLedger - $lookbackLedgers + 1);
        return [$start, $latestLedger];
    }

    /**
     * @return list<array{id:int,contract_id:string,asset_code:?string,asset_issuer:?string,asset_address:?string,executable_type:?int,is_sac:int|bool}>
     */
    private function loadContractsBatch(int $networkCode, int $afterId, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, contract_id, asset_code, asset_issuer, asset_address, executable_type, is_sac
             FROM contracts
             WHERE network = :network AND id > :after_id
             ORDER BY id ASC
             LIMIT :row_limit',
            [
                'network' => $networkCode,
                'after_id' => $afterId,
                'row_limit' => $limit,
            ],
            [
                'network' => ParameterType::INTEGER,
                'after_id' => ParameterType::INTEGER,
                'row_limit' => ParameterType::INTEGER,
            ]
        );
        if (count($rows) >= $limit) {
            return $rows;
        }

        $remaining = $limit - count($rows);
        if ($remaining <= 0) {
            return $rows;
        }

        $wrapRows = $this->connection->fetchAllAssociative(
            'SELECT id, contract_id, asset_code, asset_issuer, asset_address, executable_type, is_sac
             FROM contracts
             WHERE network = :network
             ORDER BY id ASC
             LIMIT :row_limit',
            [
                'network' => $networkCode,
                'row_limit' => $remaining,
            ],
            [
                'network' => ParameterType::INTEGER,
                'row_limit' => ParameterType::INTEGER,
            ]
        );

        /** @var array<int,bool> $seen */
        $seen = [];
        foreach ($rows as $row) {
            $seen[(int) $row['id']] = true;
        }
        foreach ($wrapRows as $row) {
            $rowId = (int) ($row['id'] ?? 0);
            if ($rowId <= 0 || isset($seen[$rowId])) {
                continue;
            }
            $rows[] = $row;
            $seen[$rowId] = true;
        }

        return $rows;
    }

    /**
     * @return list<array{id:int,contract_id:string,asset_code:?string,asset_issuer:?string,asset_address:?string,executable_type:?int,is_sac:int|bool}>
     */
    private function loadContractsDiscoveredSince(int $networkCode, \DateTimeImmutable $since, int $limit): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT id, contract_id, asset_code, asset_issuer, asset_address, executable_type, is_sac
             FROM contracts
             WHERE network = :network
               AND created_at >= :since
             ORDER BY id ASC
             LIMIT :row_limit',
            [
                'network' => $networkCode,
                'since' => $since->format('Y-m-d H:i:s'),
                'row_limit' => $limit,
            ],
            [
                'network' => ParameterType::INTEGER,
                'row_limit' => ParameterType::INTEGER,
            ]
        );
    }

    /**
     * @param array<string,mixed> $contract
     */
    private function enrichContractMetadataIfNeeded(
        array $contract,
        string $network,
        SorobanServer $server,
        bool $dryRun,
    ): bool {
        $contractDbId = (int) ($contract['id'] ?? 0);
        $contractId = trim((string) ($contract['contract_id'] ?? ''));
        if ($contractDbId <= 0 || $contractId === '') {
            return false;
        }

        $existingExecutableType = isset($contract['executable_type']) && $contract['executable_type'] !== null
            ? (int) $contract['executable_type']
            : null;
        $existingIsSac = (bool) ($contract['is_sac'] ?? false);
        $existingAssetCode = $this->normalizeNullableString($contract['asset_code'] ?? null);
        $existingAssetIssuer = $this->normalizeNullableString($contract['asset_issuer'] ?? null);
        $existingAssetAddress = $this->normalizeNullableString($contract['asset_address'] ?? null);

        $needsExecutable = $existingExecutableType === null;
        $needsSacAsset = $existingIsSac && ($existingAssetCode === null || $existingAssetIssuer === null);
        if (!$needsExecutable && !$needsSacAsset) {
            return false;
        }

        try {
            $meta = $this->sorobanContractInspector->loadContractExecutableMetaForContractId($server, $contractId);
        } catch (\Throwable) {
            return false;
        }

        $newExecutableType = is_int($meta['executableType'] ?? null) ? (int) $meta['executableType'] : $existingExecutableType;
        $newIsSac = $newExecutableType === XdrContractExecutableType::CONTRACT_EXECUTABLE_STELLAR_ASSET;

        $newAssetCode = $existingAssetCode;
        $newAssetIssuer = $existingAssetIssuer;
        $newAssetAddress = $existingAssetAddress;
        if ($newIsSac && ($newAssetCode === null || $newAssetIssuer === null || $newAssetAddress === null)) {
            $assetMeta = $this->findSacAssetMetaFromHorizon($network, $contractId);
            $newAssetCode = $assetMeta['asset_code'] ?? $newAssetCode;
            $newAssetIssuer = $assetMeta['asset_issuer'] ?? $newAssetIssuer;
            $newAssetAddress = $assetMeta['asset_address'] ?? $newAssetAddress;
        }

        $changed = $newExecutableType !== $existingExecutableType
            || $newIsSac !== $existingIsSac
            || $newAssetCode !== $existingAssetCode
            || $newAssetIssuer !== $existingAssetIssuer
            || $newAssetAddress !== $existingAssetAddress;
        if (!$changed) {
            return false;
        }
        if ($dryRun) {
            return true;
        }

        $this->connection->executeStatement(
            'UPDATE contracts
             SET executable_type = :executable_type,
                 is_sac = :is_sac,
                 asset_code = :asset_code,
                 asset_issuer = :asset_issuer,
                 asset_address = :asset_address
             WHERE id = :id',
            [
                'id' => $contractDbId,
                'executable_type' => $newExecutableType,
                'is_sac' => $newIsSac ? 1 : 0,
                'asset_code' => $newAssetCode,
                'asset_issuer' => $newAssetIssuer,
                'asset_address' => $newAssetAddress,
            ],
            [
                'id' => ParameterType::INTEGER,
                'executable_type' => $newExecutableType !== null ? ParameterType::INTEGER : ParameterType::NULL,
                'is_sac' => ParameterType::INTEGER,
                'asset_code' => $newAssetCode !== null ? ParameterType::STRING : ParameterType::NULL,
                'asset_issuer' => $newAssetIssuer !== null ? ParameterType::STRING : ParameterType::NULL,
                'asset_address' => $newAssetAddress !== null ? ParameterType::STRING : ParameterType::NULL,
            ]
        );

        return true;
    }

    /**
     * @return array{asset_code:?string,asset_issuer:?string,asset_address:?string}
     */
    private function findSacAssetMetaFromHorizon(string $network, string $contractId): array
    {
        $result = ['asset_code' => null, 'asset_issuer' => null, 'asset_address' => null];
        $contractHex = $this->decodeContractIdHexOrNull($contractId);
        $horizonConnection = $this->resolveHorizonConnection($network);
        if ($horizonConnection === null) {
            return $result;
        }

        foreach (['asset_contracts', 'contract_asset_stats'] as $tableName) {
            try {
                if (!$this->tableExists($horizonConnection, $tableName)) {
                    continue;
                }
                $columns = $this->listTableColumns($horizonConnection, $tableName);
                $contractColumn = $this->firstExistingColumn($columns, ['contract_id', 'contract', 'contract_id_hex']);
                if ($contractColumn === null) {
                    continue;
                }
                $codeColumn = $this->firstExistingColumn($columns, ['asset_code', 'code']);
                $issuerColumn = $this->firstExistingColumn($columns, ['asset_issuer', 'issuer']);
                $addressColumn = $this->firstExistingColumn($columns, ['asset_address', 'asset', 'asset_id']);

                $sql = sprintf(
                    'SELECT %s AS asset_code, %s AS asset_issuer, %s AS asset_address
                     FROM %s
                     WHERE (%s = :contract_id%s)
                     LIMIT 1',
                    $codeColumn !== null ? $codeColumn : 'NULL',
                    $issuerColumn !== null ? $issuerColumn : 'NULL',
                    $addressColumn !== null ? $addressColumn : 'NULL',
                    $tableName,
                    $contractColumn,
                    $contractHex !== null ? ' OR ' . $contractColumn . ' = :contract_hex' : ''
                );
                $params = ['contract_id' => $contractId];
                if ($contractHex !== null) {
                    $params['contract_hex'] = $contractHex;
                }
                $row = $horizonConnection->fetchAssociative($sql, $params);
                if (!is_array($row)) {
                    continue;
                }

                $candidate = [
                    'asset_code' => $this->normalizeNullableString($row['asset_code'] ?? null),
                    'asset_issuer' => $this->normalizeNullableString($row['asset_issuer'] ?? null),
                    'asset_address' => $this->normalizeNullableString($row['asset_address'] ?? null),
                ];
                if ($candidate['asset_code'] !== null || $candidate['asset_issuer'] !== null || $candidate['asset_address'] !== null) {
                    return $candidate;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $result;
    }

    private function resolveHorizonConnection(string $network): ?Connection
    {
        $connectionName = match ($network) {
            'mainnet' => 'horizon_mainnet',
            'testnet' => 'horizon_testnet',
            default => 'horizon_testnet',
        };

        try {
            return $this->doctrine->getConnection($connectionName);
        } catch (\Throwable) {
            return null;
        }
    }

    private function tableExists(Connection $connection, string $tableName): bool
    {
        $exists = $connection->fetchOne(
            'SELECT 1
             FROM information_schema.tables
             WHERE table_schema = current_schema()
               AND table_name = :table
             LIMIT 1',
            ['table' => $tableName]
        );

        return $exists !== false && $exists !== null;
    }

    /**
     * @return list<string>
     */
    private function listTableColumns(Connection $connection, string $tableName): array
    {
        $rows = $connection->fetchFirstColumn(
            'SELECT column_name
             FROM information_schema.columns
             WHERE table_schema = current_schema()
               AND table_name = :table',
            ['table' => $tableName]
        );

        $result = [];
        foreach ($rows as $value) {
            if (!is_string($value)) {
                continue;
            }
            $trimmed = trim($value);
            if ($trimmed === '') {
                continue;
            }
            $result[] = strtolower($trimmed);
        }

        return $result;
    }

    /**
     * @param list<string> $columns
     * @param list<string> $candidates
     */
    private function firstExistingColumn(array $columns, array $candidates): ?string
    {
        $set = array_fill_keys($columns, true);
        foreach ($candidates as $candidate) {
            $column = strtolower(trim($candidate));
            if ($column !== '' && isset($set[$column])) {
                return $column;
            }
        }

        return null;
    }

    private function decodeContractIdHexOrNull(string $contractId): ?string
    {
        try {
            return StrKey::decodeContractIdHex($contractId);
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed !== '' ? $trimmed : null;
    }

    private function loadLatestLedger(string $network): ?int
    {
        try {
            $latest = $this->sorobanServerFactory->create($network)->getLatestLedger();
            $payload = $latest->getJsonResponse();
            $value = isset($payload['result']['sequence']) ? (int) $payload['result']['sequence'] : null;
            return (is_int($value) && $value > 0) ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function loadSyncCursor(string $network): int
    {
        $raw = $this->loadCursor($this->syncCursorKey($network));
        if (!is_string($raw) || !ctype_digit($raw)) {
            return self::DEFAULT_SYNC_START_ID;
        }

        return max(self::DEFAULT_SYNC_START_ID, (int) $raw);
    }

    private function loadCursor(string $key): ?string
    {
        $item = $this->cache->getItem($key);
        if (!$item->isHit()) {
            return null;
        }

        $value = trim((string) $item->get());
        return $value !== '' ? $value : null;
    }

    private function storeCursor(string $key, string $value): void
    {
        $item = $this->cache->getItem($key);
        $item->set($value);
        $this->cache->save($item);
    }

    private function deleteCursor(string $key): void
    {
        $this->cache->deleteItem($key);
    }

    private function scanCursorKey(string $network): string
    {
        return sprintf('soroban_contract_scan_cursor_%s', strtolower(trim($network)));
    }

    private function syncCursorKey(string $network): string
    {
        return sprintf('soroban_contract_sync_cursor_%s', strtolower(trim($network)));
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

    private function parseNonNegativeIntOption(mixed $value): ?int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[0-9]+$/', trim($value)) === 1) {
            return (int) trim($value);
        }
        return null;
    }

    private function seedContractsFromHorizonOperations(
        string $network,
        int $networkCode,
        int $operationsLimit,
        bool $dryRun,
        SymfonyStyle $io,
    ): int {
        $horizonConnection = $this->resolveHorizonConnection($network);
        if ($horizonConnection === null) {
            $io->writeln('Horizon fallback discovery skipped: horizon connection unavailable.');
            return 0;
        }

        if (!$this->tableExists($horizonConnection, 'history_operations')) {
            $io->writeln('Horizon fallback discovery skipped: history_operations not found.');
            return 0;
        }

        $knownRows = $this->connection->fetchFirstColumn(
            'SELECT contract_id FROM contracts WHERE network = :network',
            ['network' => $networkCode],
            ['network' => ParameterType::INTEGER]
        );
        $known = [];
        foreach ($knownRows as $value) {
            if (!is_string($value)) {
                continue;
            }
            $normalized = $this->sorobanContractInspector->normalizeContractId($value);
            if (is_string($normalized) && $normalized !== '') {
                $known[strtoupper($normalized)] = true;
            }
        }

        $rows = $horizonConnection->fetchAllAssociative(
            'SELECT details
             FROM history_operations
             WHERE type IN (:types)
             ORDER BY id DESC
             LIMIT :op_limit',
            [
                'types' => self::CONTRACT_OPERATION_TYPES,
                'op_limit' => $operationsLimit,
            ],
            [
                'types' => ArrayParameterType::INTEGER,
                'op_limit' => ParameterType::INTEGER,
            ]
        );

        $inserted = 0;
        foreach ($rows as $row) {
            $details = $row['details'] ?? null;
            foreach ($this->extractContractIdsFromMixed($details) as $candidateId) {
                if (isset($known[$candidateId])) {
                    continue;
                }

                $known[$candidateId] = true;
                if ($dryRun) {
                    $inserted++;
                    continue;
                }

                $contractHex = $this->decodeContractIdHexOrNull($candidateId);
                $this->connection->executeStatement(
                    'INSERT INTO contracts (contract_id, contract_id_hex, network, created_at)
                     VALUES (:contract_id, :contract_id_hex, :network, :created_at)
                     ON DUPLICATE KEY UPDATE contract_id = contract_id',
                    [
                        'contract_id' => $candidateId,
                        'contract_id_hex' => $contractHex,
                        'network' => $networkCode,
                        'created_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                    ],
                    [
                        'contract_id' => ParameterType::STRING,
                        'contract_id_hex' => $contractHex !== null ? ParameterType::STRING : ParameterType::NULL,
                        'network' => ParameterType::INTEGER,
                        'created_at' => ParameterType::STRING,
                    ]
                );

                $inserted++;
            }
        }

        return $inserted;
    }

    /**
     * @return list<string>
     */
    private function extractContractIdsFromMixed(mixed $value, bool $allowScValDecode = true, int $depth = 0): array
    {
        if ($depth > 8) {
            return [];
        }

        $found = [];

        $scanString = function (string $input) use (&$found): void {
            if ($input === '') {
                return;
            }
            if (preg_match_all('/\bC[A-Z2-7]{55}\b/', $input, $matches) !== 1) {
                return;
            }
            foreach ($matches[0] ?? [] as $raw) {
                if (!is_string($raw)) {
                    continue;
                }
                $normalized = $this->sorobanContractInspector->normalizeContractId($raw);
                if (!is_string($normalized) || $normalized === '') {
                    continue;
                }
                $found[strtoupper($normalized)] = true;
            }
        };

        if (is_string($value)) {
            $scanString($value);
            if ($allowScValDecode) {
                foreach ($this->extractContractIdsFromScValBase64($value) as $id) {
                    $found[$id] = true;
                }
            }
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    foreach ($this->extractContractIdsFromMixed($item, $allowScValDecode, $depth + 1) as $id) {
                        $found[$id] = true;
                    }
                }
            }
        } elseif (is_array($value)) {
            foreach ($value as $item) {
                foreach ($this->extractContractIdsFromMixed($item, $allowScValDecode, $depth + 1) as $id) {
                    $found[$id] = true;
                }
            }

            // Decode operation parameters once at top-level only to avoid repeated deep traversals.
            if ($depth === 0) {
                foreach ($this->extractContractIdsFromHorizonOperationParams($value) as $id) {
                    $found[$id] = true;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * @param array<string,mixed> $details
     * @return list<string>
     */
    private function extractContractIdsFromHorizonOperationParams(array $details): array
    {
        $parameters = $details['parameters'] ?? null;
        if (!is_array($parameters)) {
            return [];
        }

        $found = [];
        foreach ($parameters as $parameter) {
            if (!is_array($parameter)) {
                continue;
            }

            $raw = $parameter['value'] ?? null;
            if (!is_string($raw) || trim($raw) === '') {
                continue;
            }

            foreach ($this->extractContractIdsFromScValBase64($raw) as $id) {
                $found[$id] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * @return list<string>
     */
    private function extractContractIdsFromScValBase64(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        if (strlen($raw) > 2048) {
            return [];
        }
        if (preg_match('/^[A-Za-z0-9+\/=]+$/', $raw) !== 1) {
            return [];
        }

        try {
            $decoded = $this->sorobanScValMapper->scValToNative(XdrSCVal::fromBase64Xdr($raw));
        } catch (\Throwable) {
            return [];
        }

        return $this->extractContractIdsFromMixed($decoded, false, 1);
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

            foreach (preg_split('/[\s,]+/', trim($value)) ?: [] as $token) {
                if ($token === '') {
                    continue;
                }

                $normalized = $this->sorobanContractInspector->normalizeContractId(trim($token));
                if (!is_string($normalized) || $normalized === '') {
                    continue;
                }

                $result[strtoupper($normalized)] = true;
            }
        }

        return $result;
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
