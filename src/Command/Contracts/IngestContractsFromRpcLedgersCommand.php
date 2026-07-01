<?php

declare(strict_types=1);

namespace App\Command\Contracts;

use App\Command\Support\NetworkOptionTrait;
use App\Service\ContractMetricsRefreshService;
use App\Service\ContractTxUpsertService;
use App\Service\Stellar\Soroban\LedgerJsonContractExtractor;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Soneso\StellarSDK\Crypto\StrKey;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:contracts:ingest-rpc-ledgers',
    description: 'Ingest historical Soroban contract transactions, events and storage from Stellar RPC getLedgers JSON.',
)]
final class IngestContractsFromRpcLedgersCommand extends Command
{
    use NetworkOptionTrait;

    private const DEFAULT_PAGE_SIZE = 10;
    private const MAX_PAGE_SIZE = 200;

    /** @var array<string,int> */
    private array $contractIdCache = [];

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.contracts_connection')]
        private readonly Connection $contractsConnection,
        private readonly HttpClientInterface $httpClient,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
        private readonly LedgerJsonContractExtractor $extractor,
        private readonly ContractTxUpsertService $contractTxUpsertService,
        private readonly ?ContractMetricsRefreshService $contractMetricsRefreshService = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addNetworkOption('mainnet|testnet|futurenet', 'mainnet')
            ->addOption('rpc-url', null, InputOption::VALUE_REQUIRED, 'Stellar RPC endpoint. Defaults to SOROBAN_RPC_* env for the selected network.')
            ->addOption('start-ledger', null, InputOption::VALUE_REQUIRED, 'Inclusive start ledger.')
            ->addOption('end-ledger', null, InputOption::VALUE_REQUIRED, 'Inclusive end ledger.')
            ->addOption('page-size', null, InputOption::VALUE_REQUIRED, 'getLedgers page size, max 200.', (string) self::DEFAULT_PAGE_SIZE)
            ->addOption('resume', null, InputOption::VALUE_NONE, 'Resume from the checkpoint if it is ahead of --start-ledger.')
            ->addOption('checkpoint-source', null, InputOption::VALUE_REQUIRED, 'Checkpoint source name.', 'rpc_getLedgers_json')
            ->addOption('skip-diagnostic-events', null, InputOption::VALUE_NONE, 'Skip diagnostic contract events.')
            ->addOption('refresh-metrics', null, InputOption::VALUE_NONE, 'Refresh contract aggregate counters during ingest. For full backfills, run app:contracts:refresh-metrics after ingest instead.')
            ->addOption('auto-maintenance', null, InputOption::VALUE_NONE, 'After successful ingest, rebuild queued derived indexes and refresh queued metrics automatically.')
            ->addOption('maintenance-batch-size', null, InputOption::VALUE_REQUIRED, 'Contracts per automatic maintenance batch.', '500')
            ->addOption('derived-index-batch-size', null, InputOption::VALUE_REQUIRED, 'Transaction batch size per contract for derived argument index rebuild.', '5000')
            ->addOption('maintenance-max-contracts', null, InputOption::VALUE_REQUIRED, 'Max queued contracts to maintain after this run, 0 for all.', '0')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Parse ledgers without database writes.')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'RPC request timeout in seconds.', '120');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $network = $this->resolveNetworkOption($input, $this->stellarNetworkResolver);
        $networkCode = $this->resolveNetworkCodeOption($input, $this->stellarNetworkResolver);
        $rpcUrl = $this->resolveRpcUrl($input, $network);
        $startLedger = $this->parsePositiveInt($input->getOption('start-ledger'));
        $endLedger = $this->parsePositiveInt($input->getOption('end-ledger'));
        $pageSize = min(self::MAX_PAGE_SIZE, max(1, $this->parsePositiveInt($input->getOption('page-size')) ?? self::DEFAULT_PAGE_SIZE));
        $resume = (bool) $input->getOption('resume');
        $sourceName = trim((string) $input->getOption('checkpoint-source')) ?: 'rpc_getLedgers_json';
        $includeDiagnosticEvents = !(bool) $input->getOption('skip-diagnostic-events');
        $refreshMetrics = (bool) $input->getOption('refresh-metrics');
        $autoMaintenance = (bool) $input->getOption('auto-maintenance');
        $maintenanceBatchSize = max(1, $this->parsePositiveInt($input->getOption('maintenance-batch-size')) ?? 500);
        $derivedIndexBatchSize = max(1, $this->parsePositiveInt($input->getOption('derived-index-batch-size')) ?? 5000);
        $maintenanceMaxContracts = max(0, (int) ($input->getOption('maintenance-max-contracts') ?? 0));
        $dryRun = (bool) $input->getOption('dry-run');
        $timeout = max(5, (int) ($input->getOption('timeout') ?? 120));

        if ($startLedger === null || $endLedger === null) {
            $io->error('--start-ledger and --end-ledger must be positive integers.');
            return Command::FAILURE;
        }
        if ($startLedger > $endLedger) {
            [$startLedger, $endLedger] = [$endLedger, $startLedger];
        }

        if (!$dryRun) {
            $this->ensureAuxiliarySchema();
        }

        if ($resume && !$dryRun) {
            $checkpointLedger = $this->loadCheckpointLedger($sourceName, $networkCode);
            if ($checkpointLedger !== null && $checkpointLedger >= $startLedger && $checkpointLedger < $endLedger) {
                $startLedger = $checkpointLedger + 1;
            }
        }

        $io->writeln(sprintf(
            'Contract RPC ledger ingest started | network=%s ledgers=%d..%d page_size=%d diagnostics=%d dry_run=%d rpc=%s',
            $network,
            $startLedger,
            $endLedger,
            $pageSize,
            $includeDiagnosticEvents ? 1 : 0,
            $dryRun ? 1 : 0,
            $rpcUrl
        ));

        $metrics = [
            'ledgers' => 0,
            'transactions' => 0,
            'contracts' => 0,
            'events' => 0,
            'storage_entries' => 0,
            'maintenance_contracts' => 0,
            'maintenance_metrics_refreshed' => 0,
            'errors' => 0,
        ];
        /** @var array<int,bool> $affectedContractDbIds */
        $affectedContractDbIds = [];

        if (!$dryRun) {
            $this->upsertCheckpoint($sourceName, $networkCode, $startLedger, $endLedger, $startLedger - 1, 'running', null, $metrics);
        }

        $currentLedger = $startLedger;
        while ($currentLedger <= $endLedger) {
            $limit = min($pageSize, $endLedger - $currentLedger + 1);
            try {
                $ledgers = $this->fetchLedgerPage($rpcUrl, $currentLedger, $limit, $timeout);
            } catch (\Throwable $e) {
                if (!$dryRun) {
                    $this->upsertCheckpoint($sourceName, $networkCode, $startLedger, $endLedger, $currentLedger - 1, 'failed', $e->getMessage(), $metrics);
                }
                $io->error(sprintf('RPC getLedgers failed at ledger %d: %s', $currentLedger, $e->getMessage()));

                return Command::FAILURE;
            }

            if ($ledgers === []) {
                if (!$dryRun) {
                    $this->upsertCheckpoint($sourceName, $networkCode, $startLedger, $endLedger, $currentLedger - 1, 'failed', 'RPC returned no ledgers.', $metrics);
                }
                $io->error(sprintf('RPC returned no ledgers at requested start ledger %d.', $currentLedger));

                return Command::FAILURE;
            }

            $lastProcessedLedger = $currentLedger - 1;
            foreach ($ledgers as $ledger) {
                if (!is_array($ledger)) {
                    continue;
                }

                $extracted = $this->extractor->extract($ledger, $includeDiagnosticEvents);
                $ledgerSequence = (int) ($extracted['sequence'] ?? 0);
                if ($ledgerSequence <= 0) {
                    continue;
                }

                try {
                    $ledgerAffectedContracts = $this->persistExtractedLedger($extracted, $networkCode, $dryRun);
                    foreach ($ledgerAffectedContracts as $contractDbId) {
                        $affectedContractDbIds[$contractDbId] = true;
                    }

                    $metrics['ledgers']++;
                    $metrics['transactions'] += count($extracted['transactions'] ?? []);
                    $metrics['contracts'] += count($ledgerAffectedContracts);
                    foreach ($extracted['transactions'] ?? [] as $tx) {
                        if (!is_array($tx)) {
                            continue;
                        }
                        foreach (($tx['eventsByContract'] ?? []) as $events) {
                            $metrics['events'] += is_array($events) ? count($events) : 0;
                        }
                        foreach (($tx['storageByContract'] ?? []) as $entries) {
                            $metrics['storage_entries'] += is_array($entries) ? count($entries) : 0;
                        }
                    }
                } catch (\Throwable $e) {
                    $metrics['errors']++;
                    if (!$dryRun) {
                        $this->upsertCheckpoint($sourceName, $networkCode, $startLedger, $endLedger, $ledgerSequence - 1, 'failed', $e->getMessage(), $metrics);
                    }
                    $io->error(sprintf('Failed to persist ledger %d: %s', $ledgerSequence, $e->getMessage()));

                    return Command::FAILURE;
                }

                $lastProcessedLedger = $ledgerSequence;
                if (!$dryRun) {
                    $this->upsertCheckpoint($sourceName, $networkCode, $startLedger, $endLedger, $lastProcessedLedger, 'running', null, $metrics);
                }
            }

            if ($refreshMetrics && !$dryRun && $affectedContractDbIds !== []) {
                $this->contractMetricsRefreshService?->refreshForContractIds(array_keys($affectedContractDbIds));
                $affectedContractDbIds = [];
            }

            $io->writeln(sprintf(
                'Processed through ledger %d | ledgers=%d tx=%d events=%d storage=%d errors=%d',
                $lastProcessedLedger,
                $metrics['ledgers'],
                $metrics['transactions'],
                $metrics['events'],
                $metrics['storage_entries'],
                $metrics['errors']
            ));

            $currentLedger = $lastProcessedLedger + 1;
        }

        if ($refreshMetrics && !$dryRun && $affectedContractDbIds !== []) {
            $this->contractMetricsRefreshService?->refreshForContractIds(array_keys($affectedContractDbIds));
        }
        if (!$dryRun) {
            $this->upsertCheckpoint($sourceName, $networkCode, $startLedger, $endLedger, $endLedger, $autoMaintenance ? 'maintenance' : 'completed', null, $metrics);
        }

        if ($autoMaintenance && !$dryRun) {
            $io->writeln('Automatic contract index maintenance started.');
            $maintenance = $this->processQueuedMaintenance(
                $networkCode,
                $maintenanceBatchSize,
                $derivedIndexBatchSize,
                $maintenanceMaxContracts
            );
            $metrics['maintenance_contracts'] = $maintenance['contracts'];
            $metrics['maintenance_metrics_refreshed'] = $maintenance['metrics_refreshed'];
            $this->upsertCheckpoint($sourceName, $networkCode, $startLedger, $endLedger, $endLedger, 'completed', null, $metrics);
            $io->writeln(sprintf(
                'Automatic maintenance completed | contracts=%d metrics_refreshed=%d',
                $maintenance['contracts'],
                $maintenance['metrics_refreshed']
            ));
        }

        $io->table(
            ['Metric', 'Value'],
            [
                ['network', $network],
                ['start_ledger', (string) $startLedger],
                ['end_ledger', (string) $endLedger],
                ['ledgers_processed', (string) $metrics['ledgers']],
                ['transactions_indexed', (string) $metrics['transactions']],
                ['contract_touches', (string) $metrics['contracts']],
                ['events_indexed', (string) $metrics['events']],
                ['storage_entries_indexed', (string) $metrics['storage_entries']],
                ['maintenance_contracts', (string) $metrics['maintenance_contracts']],
                ['maintenance_metrics_refreshed', (string) $metrics['maintenance_metrics_refreshed']],
                ['errors', (string) $metrics['errors']],
            ]
        );

        $io->success($dryRun ? 'Contract RPC ledger ingest dry-run completed.' : 'Contract RPC ledger ingest completed.');

        return Command::SUCCESS;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchLedgerPage(string $rpcUrl, int $startLedger, int $limit, int $timeout): array
    {
        $response = $this->httpClient->request('POST', $rpcUrl, [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => [
                'jsonrpc' => '2.0',
                'id' => sprintf('contracts-%d', $startLedger),
                'method' => 'getLedgers',
                'params' => [
                    'startLedger' => $startLedger,
                    'pagination' => ['limit' => $limit],
                    'xdrFormat' => 'json',
                ],
            ],
            'timeout' => $timeout,
        ]);

        $payload = $response->toArray(false);
        if (isset($payload['error'])) {
            $message = is_array($payload['error']) ? json_encode($payload['error'], JSON_UNESCAPED_SLASHES) : (string) $payload['error'];
            throw new \RuntimeException(is_string($message) ? $message : 'RPC returned an error.');
        }

        $ledgers = $payload['result']['ledgers'] ?? null;
        if (!is_array($ledgers)) {
            throw new \RuntimeException('RPC response does not contain result.ledgers.');
        }

        return array_values($ledgers);
    }

    /**
     * @param array<string,mixed> $extracted
     * @return list<int>
     */
    private function persistExtractedLedger(array $extracted, int $networkCode, bool $dryRun): array
    {
        $affected = [];
        foreach (($extracted['transactions'] ?? []) as $tx) {
            if (!is_array($tx)) {
                continue;
            }

            $contractIds = is_array($tx['contractIds'] ?? null) ? $tx['contractIds'] : [];
            foreach ($contractIds as $contractId) {
                if (!is_string($contractId) || $contractId === '') {
                    continue;
                }

                $meta = is_array(($tx['contractMetaByContract'] ?? [])[$contractId] ?? null)
                    ? ($tx['contractMetaByContract'] ?? [])[$contractId]
                    : [];
                $contractDbId = $this->resolveOrCreateContractDbId(
                    $contractId,
                    $networkCode,
                    $tx['createdAt'] ?? null,
                    $meta['wasmId'] ?? null,
                    isset($meta['executableType']) ? (int) $meta['executableType'] : null,
                    $dryRun
                );
                if ($contractDbId === null) {
                    continue;
                }
                $affected[$contractDbId] = true;

                if ($dryRun) {
                    continue;
                }

                $this->upsertTransaction($contractDbId, $tx);
                $this->markMaintenanceNeeded($contractDbId, $networkCode);

                $events = is_array(($tx['eventsByContract'] ?? [])[$contractId] ?? null)
                    ? ($tx['eventsByContract'] ?? [])[$contractId]
                    : [];
                foreach ($events as $event) {
                    if (is_array($event)) {
                        $this->upsertEvent($contractDbId, $event);
                    }
                }

                $storageEntries = is_array(($tx['storageByContract'] ?? [])[$contractId] ?? null)
                    ? ($tx['storageByContract'] ?? [])[$contractId]
                    : [];
                foreach ($storageEntries as $storageEntry) {
                    if (is_array($storageEntry)) {
                        $this->upsertStorageEntry($contractDbId, $storageEntry);
                    }
                }
            }
        }

        return array_keys($affected);
    }

    /**
     * @param array<string,mixed> $tx
     */
    private function upsertTransaction(int $contractDbId, array $tx): void
    {
        $hostFunctions = [
            'source' => 'rpc_getLedgers_json',
            'operationTypes' => is_array($tx['operationTypes'] ?? null) ? $tx['operationTypes'] : [],
            'operationsCount' => isset($tx['totalOperations']) ? (int) $tx['totalOperations'] : 0,
            'effectsCount' => isset($tx['effectsCount']) ? (int) $tx['effectsCount'] : 0,
            'invokeContracts' => is_array($tx['invokeCalls'] ?? null) ? $tx['invokeCalls'] : [],
        ];

        $this->contractsConnection->executeStatement(
            <<<'SQL'
INSERT INTO contract_transactions (
    contract_id,
    tx_hash,
    source_account,
    host_functions,
    fee_charged,
    max_fee,
    ledger,
    total_operations,
    created_at,
    envelope_decoded,
    meta_decoded,
    return_value_decoded,
    resource_fee_charged
) VALUES (
    :contract_id,
    :tx_hash,
    :source_account,
    :host_functions,
    :fee_charged,
    :max_fee,
    :ledger,
    :total_operations,
    :created_at,
    CAST(:envelope_decoded AS JSONB),
    CAST(:meta_decoded AS JSONB),
    CAST(:return_value_decoded AS JSONB),
    :resource_fee_charged
)
ON CONFLICT (contract_id, tx_hash) DO UPDATE SET
    source_account = EXCLUDED.source_account,
    host_functions = EXCLUDED.host_functions,
    fee_charged = EXCLUDED.fee_charged,
    max_fee = EXCLUDED.max_fee,
    ledger = EXCLUDED.ledger,
    total_operations = EXCLUDED.total_operations,
    created_at = EXCLUDED.created_at,
    envelope_decoded = EXCLUDED.envelope_decoded,
    meta_decoded = EXCLUDED.meta_decoded,
    return_value_decoded = EXCLUDED.return_value_decoded,
    resource_fee_charged = EXCLUDED.resource_fee_charged
SQL,
            [
                'contract_id' => $contractDbId,
                'tx_hash' => (string) $tx['txHash'],
                'source_account' => $this->normalizeNullableString($tx['sourceAccount'] ?? null),
                'host_functions' => $this->encodeJson($hostFunctions),
                'fee_charged' => isset($tx['feeCharged']) ? (int) $tx['feeCharged'] : 0,
                'max_fee' => isset($tx['maxFee']) ? (int) $tx['maxFee'] : 0,
                'ledger' => isset($tx['ledger']) ? (int) $tx['ledger'] : null,
                'total_operations' => isset($tx['totalOperations']) ? (int) $tx['totalOperations'] : null,
                'created_at' => $this->normalizeDateTime($tx['createdAt'] ?? null),
                'envelope_decoded' => $this->encodeJson($tx['envelopeDecoded'] ?? null),
                'meta_decoded' => $this->encodeJson($tx['metaDecoded'] ?? null),
                'return_value_decoded' => $this->encodeJson($tx['returnValue'] ?? null),
                'resource_fee_charged' => isset($tx['resourceFeeCharged']) ? (int) $tx['resourceFeeCharged'] : null,
            ],
            [
                'contract_id' => ParameterType::INTEGER,
                'fee_charged' => ParameterType::INTEGER,
                'max_fee' => ParameterType::INTEGER,
                'ledger' => isset($tx['ledger']) ? ParameterType::INTEGER : ParameterType::NULL,
                'total_operations' => isset($tx['totalOperations']) ? ParameterType::INTEGER : ParameterType::NULL,
                'resource_fee_charged' => isset($tx['resourceFeeCharged']) ? ParameterType::INTEGER : ParameterType::NULL,
            ]
        );
    }

    /**
     * @param array<string,mixed> $event
     */
    private function upsertEvent(int $contractDbId, array $event): void
    {
        $this->contractsConnection->executeStatement(
            <<<'SQL'
INSERT INTO contract_events (
    contract_id,
    tx_hash,
    event_idx,
    ledger,
    ledger_closed_at,
    event_type,
    topic_decoded,
    value_decoded,
    addresses,
    amount_raw,
    created_at,
    event_raw,
    is_diagnostic
) VALUES (
    :contract_id,
    :tx_hash,
    :event_idx,
    :ledger,
    :ledger_closed_at,
    :event_type,
    CAST(:topic_decoded AS JSONB),
    CAST(:value_decoded AS JSONB),
    CAST(:addresses AS JSONB),
    :amount_raw,
    :created_at,
    CAST(:event_raw AS JSONB),
    :is_diagnostic
)
ON CONFLICT (contract_id, tx_hash, event_idx) DO UPDATE SET
    ledger = EXCLUDED.ledger,
    ledger_closed_at = EXCLUDED.ledger_closed_at,
    event_type = EXCLUDED.event_type,
    topic_decoded = EXCLUDED.topic_decoded,
    value_decoded = EXCLUDED.value_decoded,
    addresses = EXCLUDED.addresses,
    amount_raw = EXCLUDED.amount_raw,
    created_at = EXCLUDED.created_at,
    event_raw = EXCLUDED.event_raw,
    is_diagnostic = EXCLUDED.is_diagnostic
SQL,
            [
                'contract_id' => $contractDbId,
                'tx_hash' => (string) $event['txHash'],
                'event_idx' => isset($event['eventIndex']) ? (int) $event['eventIndex'] : 0,
                'ledger' => isset($event['ledger']) ? (int) $event['ledger'] : null,
                'ledger_closed_at' => $this->normalizeDateTime($event['ledgerClosedAt'] ?? null),
                'event_type' => $this->normalizeNullableString($event['eventType'] ?? null) ?? 'unknown',
                'topic_decoded' => $this->encodeJson($event['topicDecoded'] ?? []),
                'value_decoded' => $this->encodeJson($event['valueDecoded'] ?? null),
                'addresses' => $this->encodeJson($event['addresses'] ?? []),
                'amount_raw' => $this->normalizeNullableString($event['amountRaw'] ?? null),
                'created_at' => $this->normalizeDateTime($event['ledgerClosedAt'] ?? null),
                'event_raw' => $this->encodeJson($event['raw'] ?? null),
                'is_diagnostic' => (bool) ($event['isDiagnostic'] ?? false),
            ],
            [
                'contract_id' => ParameterType::INTEGER,
                'event_idx' => ParameterType::INTEGER,
                'ledger' => isset($event['ledger']) ? ParameterType::INTEGER : ParameterType::NULL,
                'is_diagnostic' => ParameterType::BOOLEAN,
            ]
        );
    }

    /**
     * @param array<string,mixed> $entry
     */
    private function upsertStorageEntry(int $contractDbId, array $entry): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $this->contractsConnection->executeStatement(
            <<<'SQL'
INSERT INTO contract_storage_entries (
    contract_id,
    storage_key,
    entry_xdr,
    entry_decoded,
    entry_raw,
    last_modified_ledger_seq,
    live_until_ledger_seq,
    updated_at
) VALUES (
    :contract_id,
    :storage_key,
    :entry_xdr,
    CAST(:entry_decoded AS JSONB),
    CAST(:entry_raw AS JSONB),
    :last_modified_ledger_seq,
    :live_until_ledger_seq,
    :updated_at
)
ON CONFLICT (contract_id, storage_key) DO UPDATE SET
    entry_xdr = EXCLUDED.entry_xdr,
    entry_decoded = EXCLUDED.entry_decoded,
    entry_raw = EXCLUDED.entry_raw,
    last_modified_ledger_seq = EXCLUDED.last_modified_ledger_seq,
    live_until_ledger_seq = EXCLUDED.live_until_ledger_seq,
    updated_at = EXCLUDED.updated_at
SQL,
            [
                'contract_id' => $contractDbId,
                'storage_key' => (string) $entry['key'],
                'entry_xdr' => null,
                'entry_decoded' => $this->encodeJson($entry),
                'entry_raw' => $this->encodeJson($entry['contractData'] ?? null),
                'last_modified_ledger_seq' => isset($entry['lastModifiedLedgerSeq']) ? (int) $entry['lastModifiedLedgerSeq'] : null,
                'live_until_ledger_seq' => isset($entry['liveUntilLedgerSeq']) ? (int) $entry['liveUntilLedgerSeq'] : null,
                'updated_at' => $now,
            ],
            [
                'contract_id' => ParameterType::INTEGER,
                'last_modified_ledger_seq' => isset($entry['lastModifiedLedgerSeq']) ? ParameterType::INTEGER : ParameterType::NULL,
                'live_until_ledger_seq' => isset($entry['liveUntilLedgerSeq']) ? ParameterType::INTEGER : ParameterType::NULL,
            ]
        );
    }

    private function resolveOrCreateContractDbId(
        string $contractId,
        int $networkCode,
        mixed $createdAt,
        mixed $wasmId,
        ?int $executableType,
        bool $dryRun
    ): ?int {
        $contractId = strtoupper(trim($contractId));
        if ($contractId === '') {
            return null;
        }
        $cacheKey = $networkCode . ':' . $contractId;
        if (isset($this->contractIdCache[$cacheKey])) {
            return $this->contractIdCache[$cacheKey];
        }

        if ($dryRun) {
            return 0;
        }

        $createdAt = $this->normalizeDateTime($createdAt)
            ?? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $wasmId = is_string($wasmId) && preg_match('/^[0-9a-fA-F]{64}$/', $wasmId) === 1 ? strtolower($wasmId) : null;

        $id = $this->contractsConnection->fetchOne(
            <<<'SQL'
INSERT INTO contracts (
    contract_id,
    contract_id_hex,
    network,
    created_at,
    wasm_id,
    executable_type
) VALUES (
    :contract_id,
    :contract_id_hex,
    :network,
    :created_at,
    :wasm_id,
    :executable_type
)
ON CONFLICT (contract_id, network) DO UPDATE SET
    created_at = COALESCE(contracts.created_at, EXCLUDED.created_at),
    wasm_id = COALESCE(contracts.wasm_id, EXCLUDED.wasm_id),
    executable_type = COALESCE(contracts.executable_type, EXCLUDED.executable_type)
RETURNING id
SQL,
            [
                'contract_id' => $contractId,
                'contract_id_hex' => $this->decodeContractIdHexOrNull($contractId),
                'network' => $networkCode,
                'created_at' => $createdAt,
                'wasm_id' => $wasmId,
                'executable_type' => $executableType,
            ],
            [
                'network' => ParameterType::INTEGER,
                'wasm_id' => $wasmId !== null ? ParameterType::STRING : ParameterType::NULL,
                'executable_type' => $executableType !== null ? ParameterType::INTEGER : ParameterType::NULL,
            ]
        );
        $id = is_numeric($id) ? (int) $id : null;
        if ($id === null || $id <= 0) {
            return null;
        }

        if ($wasmId !== null) {
            $this->upsertContractSource($wasmId);
        }

        $this->contractIdCache[$cacheKey] = $id;

        return $id;
    }

    private function upsertContractSource(string $wasmId): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $this->contractsConnection->executeStatement(
            <<<'SQL'
INSERT INTO contract_sources (wasm_id, created_at, updated_at)
VALUES (:wasm_id, :created_at, :updated_at)
ON CONFLICT (wasm_id) DO UPDATE SET updated_at = contract_sources.updated_at
SQL,
            [
                'wasm_id' => $wasmId,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    private function ensureAuxiliarySchema(): void
    {
        foreach ([
            'ALTER TABLE contract_transactions ADD COLUMN IF NOT EXISTS envelope_decoded JSONB DEFAULT NULL',
            'ALTER TABLE contract_transactions ADD COLUMN IF NOT EXISTS meta_decoded JSONB DEFAULT NULL',
            'ALTER TABLE contract_transactions ADD COLUMN IF NOT EXISTS return_value_decoded JSONB DEFAULT NULL',
            'ALTER TABLE contract_transactions ADD COLUMN IF NOT EXISTS resource_fee_charged BIGINT DEFAULT NULL',
            'ALTER TABLE contract_events ADD COLUMN IF NOT EXISTS event_raw JSONB DEFAULT NULL',
            'ALTER TABLE contract_events ADD COLUMN IF NOT EXISTS is_diagnostic BOOLEAN NOT NULL DEFAULT FALSE',
            'ALTER TABLE contract_storage_entries ADD COLUMN IF NOT EXISTS entry_raw JSONB DEFAULT NULL',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS contract_index_maintenance_queue (
    contract_id BIGINT PRIMARY KEY,
    network INT NOT NULL,
    needs_derived BOOLEAN NOT NULL DEFAULT TRUE,
    needs_metrics BOOLEAN NOT NULL DEFAULT TRUE,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
)
SQL,
            'CREATE INDEX IF NOT EXISTS idx_contract_index_maintenance_network ON contract_index_maintenance_queue (network, updated_at)',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS contract_ingest_checkpoints (
    id BIGSERIAL PRIMARY KEY,
    source_name VARCHAR(128) NOT NULL,
    network INT NOT NULL,
    start_ledger INT NOT NULL,
    end_ledger INT DEFAULT NULL,
    last_processed_ledger INT NOT NULL DEFAULT 0,
    status VARCHAR(32) NOT NULL DEFAULT 'running',
    last_error TEXT DEFAULT NULL,
    stats JSONB DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
)
SQL,
            'CREATE UNIQUE INDEX IF NOT EXISTS uniq_contract_ingest_checkpoint_source_network ON contract_ingest_checkpoints (source_name, network)',
            'CREATE INDEX IF NOT EXISTS idx_contract_ingest_checkpoints_status ON contract_ingest_checkpoints (status, updated_at)',
        ] as $sql) {
            $this->contractsConnection->executeStatement($sql);
        }
    }

    private function markMaintenanceNeeded(int $contractDbId, int $networkCode): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $this->contractsConnection->executeStatement(
            <<<'SQL'
INSERT INTO contract_index_maintenance_queue (
    contract_id,
    network,
    needs_derived,
    needs_metrics,
    updated_at
) VALUES (
    :contract_id,
    :network,
    TRUE,
    TRUE,
    :updated_at
)
ON CONFLICT (contract_id) DO UPDATE SET
    network = EXCLUDED.network,
    needs_derived = TRUE,
    needs_metrics = TRUE,
    updated_at = EXCLUDED.updated_at
SQL,
            [
                'contract_id' => $contractDbId,
                'network' => $networkCode,
                'updated_at' => $now,
            ],
            [
                'contract_id' => ParameterType::INTEGER,
                'network' => ParameterType::INTEGER,
            ]
        );
    }

    /**
     * @return array{contracts:int,metrics_refreshed:int}
     */
    private function processQueuedMaintenance(
        int $networkCode,
        int $batchSize,
        int $derivedIndexBatchSize,
        int $maxContracts
    ): array {
        $processed = 0;
        $metricsRefreshed = 0;

        while ($maxContracts === 0 || $processed < $maxContracts) {
            $limit = $maxContracts === 0 ? $batchSize : min($batchSize, $maxContracts - $processed);
            if ($limit <= 0) {
                break;
            }

            $rows = $this->contractsConnection->fetchAllAssociative(
                'SELECT contract_id, needs_derived, needs_metrics
                 FROM contract_index_maintenance_queue
                 WHERE network = :network
                 ORDER BY updated_at ASC, contract_id ASC
                 LIMIT :limit_rows',
                [
                    'network' => $networkCode,
                    'limit_rows' => $limit,
                ],
                [
                    'network' => ParameterType::INTEGER,
                    'limit_rows' => ParameterType::INTEGER,
                ]
            );
            if ($rows === []) {
                break;
            }

            $metricIds = [];
            $processedIds = [];
            foreach ($rows as $row) {
                $contractId = isset($row['contract_id']) ? (int) $row['contract_id'] : 0;
                if ($contractId <= 0) {
                    continue;
                }

                $needsDerived = $this->toBool($row['needs_derived'] ?? true);
                $needsMetrics = $this->toBool($row['needs_metrics'] ?? true);
                if ($needsDerived) {
                    $this->contractTxUpsertService->rebuildArgumentUsageIndexForContract($contractId, $derivedIndexBatchSize);
                    $this->contractTxUpsertService->rebuildHolderBalancesForContract($contractId);
                }
                if ($needsMetrics) {
                    $metricIds[] = $contractId;
                }
                $processedIds[] = $contractId;
            }

            if ($metricIds !== [] && $this->contractMetricsRefreshService !== null) {
                $metricsRefreshed += $this->contractMetricsRefreshService->refreshForContractIds($metricIds);
            }

            if ($processedIds !== []) {
                $this->contractsConnection->executeStatement(
                    'DELETE FROM contract_index_maintenance_queue WHERE contract_id IN (:ids)',
                    ['ids' => $processedIds],
                    ['ids' => ArrayParameterType::INTEGER]
                );
            }

            $processed += count($processedIds);
            if (count($rows) < $limit || $processedIds === []) {
                break;
            }
        }

        return ['contracts' => $processed, 'metrics_refreshed' => $metricsRefreshed];
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 't', 'yes', 'y'], true);
        }

        return (bool) $value;
    }

    /**
     * @param array<string,int> $stats
     */
    private function upsertCheckpoint(
        string $sourceName,
        int $networkCode,
        int $startLedger,
        int $endLedger,
        int $lastProcessedLedger,
        string $status,
        ?string $lastError,
        array $stats
    ): void {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $this->contractsConnection->executeStatement(
            <<<'SQL'
INSERT INTO contract_ingest_checkpoints (
    source_name,
    network,
    start_ledger,
    end_ledger,
    last_processed_ledger,
    status,
    last_error,
    stats,
    created_at,
    updated_at
) VALUES (
    :source_name,
    :network,
    :start_ledger,
    :end_ledger,
    :last_processed_ledger,
    :status,
    :last_error,
    CAST(:stats AS JSONB),
    :created_at,
    :updated_at
)
ON CONFLICT (source_name, network) DO UPDATE SET
    start_ledger = EXCLUDED.start_ledger,
    end_ledger = EXCLUDED.end_ledger,
    last_processed_ledger = EXCLUDED.last_processed_ledger,
    status = EXCLUDED.status,
    last_error = EXCLUDED.last_error,
    stats = EXCLUDED.stats,
    updated_at = EXCLUDED.updated_at
SQL,
            [
                'source_name' => $sourceName,
                'network' => $networkCode,
                'start_ledger' => $startLedger,
                'end_ledger' => $endLedger,
                'last_processed_ledger' => max(0, $lastProcessedLedger),
                'status' => $status,
                'last_error' => $lastError,
                'stats' => $this->encodeJson($stats),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'network' => ParameterType::INTEGER,
                'start_ledger' => ParameterType::INTEGER,
                'end_ledger' => ParameterType::INTEGER,
                'last_processed_ledger' => ParameterType::INTEGER,
            ]
        );
    }

    private function loadCheckpointLedger(string $sourceName, int $networkCode): ?int
    {
        $value = $this->contractsConnection->fetchOne(
            'SELECT last_processed_ledger FROM contract_ingest_checkpoints WHERE source_name = :source_name AND network = :network LIMIT 1',
            [
                'source_name' => $sourceName,
                'network' => $networkCode,
            ],
            ['network' => ParameterType::INTEGER]
        );

        return is_numeric($value) ? (int) $value : null;
    }

    private function resolveRpcUrl(InputInterface $input, string $network): string
    {
        $explicit = $this->normalizeNullableString($input->getOption('rpc-url'));
        if ($explicit !== null) {
            return $explicit;
        }

        return $this->stellarNetworkResolver->resolveSorobanRpcUrl($network);
    }

    private function decodeContractIdHexOrNull(string $contractId): ?string
    {
        try {
            return StrKey::decodeContractIdHex($contractId);
        } catch (\Throwable) {
            return null;
        }
    }

    private function parsePositiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
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

    private function encodeJson(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : null;
    }
}
