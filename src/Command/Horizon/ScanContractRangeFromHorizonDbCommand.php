<?php

declare(strict_types=1);

namespace App\Command\Horizon;

use App\Command\Support\NetworkOptionTrait;
use App\Service\ContractMetricsRefreshService;
use App\Service\ContractTxUpsertService;
use App\Service\Stellar\Soroban\SorobanContractInspector;
use App\Service\Stellar\Soroban\SorobanScValMapper;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\Persistence\ManagerRegistry;
use Soneso\StellarSDK\Crypto\StrKey;
use Soneso\StellarSDK\Xdr\XdrSCVal;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:horizon:scan-contract-range-db',
    description: 'Backup scanner: read Horizon DB operations by ledger range and persist contract transactions/argument links locally.',
)]
final class ScanContractRangeFromHorizonDbCommand extends Command
{
    use NetworkOptionTrait;

    /** @var list<int> */
    private const CONTRACT_OPERATION_TYPES = [24, 25, 26];
    private const DEFAULT_BATCH_SIZE = 5000;

    /** @var array<string,int> */
    private array $contractDbIdCache = [];
    /** @var array<string,int> */
    private array $eventIndexCounters = [];

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        #[Autowire(service: 'doctrine.dbal.contracts_connection')]
        private readonly Connection $connection,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
        private readonly SorobanContractInspector $sorobanContractInspector,
        private readonly SorobanScValMapper $sorobanScValMapper,
        private readonly ContractTxUpsertService $txUpsertService,
        private readonly ?ContractMetricsRefreshService $contractMetricsRefreshService = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addNetworkOption('mainnet|testnet|futurenet', 'mainnet')
            ->addOption('start-ledger', null, InputOption::VALUE_REQUIRED, 'Inclusive start ledger.')
            ->addOption('end-ledger', null, InputOption::VALUE_REQUIRED, 'Inclusive end ledger.')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Rows batch size from Horizon DB.', (string) self::DEFAULT_BATCH_SIZE)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Run without writes.')
            ->addOption('derive-events', null, InputOption::VALUE_NONE, 'Derive transfer/mint/burn events from invoke args for balances fallback.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $network = $this->resolveNetworkOption($input, $this->stellarNetworkResolver);
        $networkCode = $this->resolveNetworkCodeOption($input, $this->stellarNetworkResolver);
        $startLedger = $this->parsePositiveInt($input->getOption('start-ledger'));
        $endLedger = $this->parsePositiveInt($input->getOption('end-ledger'));
        $batchSize = $this->parsePositiveInt($input->getOption('batch-size')) ?? self::DEFAULT_BATCH_SIZE;
        $dryRun = (bool) $input->getOption('dry-run');
        $deriveEvents = (bool) $input->getOption('derive-events');

        if ($startLedger === null || $endLedger === null) {
            $io->error('--start-ledger and --end-ledger must be positive integers.');
            return Command::FAILURE;
        }
        if ($startLedger > $endLedger) {
            [$startLedger, $endLedger] = [$endLedger, $startLedger];
        }

        $horizonConnection = $this->resolveHorizonConnection($network);
        if ($horizonConnection === null) {
            $io->error(sprintf('Missing Horizon DB connection for network=%s', $network));
            return Command::FAILURE;
        }

        $metrics = [
            'operations_scanned' => 0,
            'operations_contract_types' => 0,
            'transactions_inserted' => 0,
            'transactions_updated' => 0,
            'transactions_unchanged' => 0,
            'events_derived' => 0,
            'errors' => 0,
        ];
        /** @var array<int,bool> $affectedContracts */
        $affectedContracts = [];

        $io->writeln(sprintf(
            'Horizon DB contract scan started | network=%s ledgers=%d..%d batch=%d dry_run=%d derive_events=%d',
            $network,
            $startLedger,
            $endLedger,
            $batchSize,
            $dryRun ? 1 : 0,
            $deriveEvents ? 1 : 0
        ));

        $afterId = 0;
        while (true) {
            $rows = $horizonConnection->fetchAllAssociative(
                'SELECT
                    ho.id AS operation_id,
                    ho.type AS operation_type,
                    ho.details AS operation_details,
                    ho.source_account AS operation_source_account,
                    ht.transaction_hash,
                    ht.ledger_sequence,
                    ht.account AS tx_source_account,
                    ht.fee_charged,
                    ht.max_fee,
                    ht.operation_count,
                    ht.created_at
                 FROM history_operations ho
                 INNER JOIN history_transactions ht ON ht.id = ho.transaction_id
                 WHERE ht.ledger_sequence BETWEEN :start_ledger AND :end_ledger
                   AND ho.type IN (:types)
                   AND ho.id > :after_id
                 ORDER BY ho.id ASC
                 LIMIT :batch_size',
                [
                    'start_ledger' => $startLedger,
                    'end_ledger' => $endLedger,
                    'types' => self::CONTRACT_OPERATION_TYPES,
                    'after_id' => $afterId,
                    'batch_size' => $batchSize,
                ],
                [
                    'start_ledger' => ParameterType::INTEGER,
                    'end_ledger' => ParameterType::INTEGER,
                    'types' => ArrayParameterType::INTEGER,
                    'after_id' => ParameterType::INTEGER,
                    'batch_size' => ParameterType::INTEGER,
                ]
            );
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $afterId = max($afterId, (int) ($row['operation_id'] ?? 0));
                $metrics['operations_scanned']++;
                $metrics['operations_contract_types']++;

                try {
                    $parsed = $this->parseOperationRow($row);
                } catch (\Throwable) {
                    $metrics['errors']++;
                    continue;
                }
                if ($parsed === null) {
                    continue;
                }

                $targetContractId = $parsed['targetContractId'];
                $allContractIds = $parsed['allContractIds'];
                $transactionRow = $parsed['transactionRow'];
                $eventRows = $parsed['eventRows'];

                foreach ($allContractIds as $contractId) {
                    $contractDbId = $this->resolveOrCreateContractDbId($contractId, $networkCode, $transactionRow['createdAt'], $dryRun);
                    if ($contractDbId !== null) {
                        $affectedContracts[$contractDbId] = true;
                    }
                }

                $targetContractDbId = $this->resolveOrCreateContractDbId($targetContractId, $networkCode, $transactionRow['createdAt'], $dryRun);
                if ($targetContractDbId === null) {
                    continue;
                }

                $affectedContracts[$targetContractDbId] = true;

                if (!$dryRun) {
                    [$inserted, $updated, $unchanged] = $this->txUpsertService->upsertTransactionsForContract(
                        $targetContractDbId,
                        [$transactionRow]
                    );
                    $metrics['transactions_inserted'] += $inserted;
                    $metrics['transactions_updated'] += $updated;
                    $metrics['transactions_unchanged'] += $unchanged;

                    if ($deriveEvents && $eventRows !== []) {
                        $this->txUpsertService->upsertEventsForContract($targetContractDbId, $eventRows);
                        $metrics['events_derived'] += count($eventRows);
                    }
                }
            }
        }

        if (!$dryRun && $this->contractMetricsRefreshService !== null && $affectedContracts !== []) {
            $this->contractMetricsRefreshService->refreshForContractIds(array_keys($affectedContracts));
        }

        $io->table(
            ['Metric', 'Value'],
            [
                ['network', $network],
                ['scan_start_ledger', (string) $startLedger],
                ['scan_end_ledger', (string) $endLedger],
                ['operations_scanned', (string) $metrics['operations_scanned']],
                ['operations_contract_types', (string) $metrics['operations_contract_types']],
                ['contracts_touched', (string) count($affectedContracts)],
                ['transactions_inserted', (string) $metrics['transactions_inserted']],
                ['transactions_updated', (string) $metrics['transactions_updated']],
                ['transactions_unchanged', (string) $metrics['transactions_unchanged']],
                ['events_derived', (string) $metrics['events_derived']],
                ['errors', (string) $metrics['errors']],
            ]
        );

        $io->success($dryRun ? 'Horizon DB contract scan dry-run completed.' : 'Horizon DB contract scan completed.');
        return Command::SUCCESS;
    }

    /**
     * @return array{
     *   targetContractId:string,
     *   allContractIds:list<string>,
     *   transactionRow:array<string,mixed>,
     *   eventRows:list<array<string,mixed>>
     * }|null
     */
    private function parseOperationRow(array $row): ?array
    {
        $txHash = trim((string) ($row['transaction_hash'] ?? ''));
        if ($txHash === '') {
            return null;
        }

        $details = $this->decodeOperationDetails($row['operation_details'] ?? null);
        $invokeCall = $this->extractInvokeCallFromDetails($details);
        if ($invokeCall === null) {
            return null;
        }

        $targetContractId = $invokeCall['contractId'];
        $argumentContractIds = $this->extractContractIdsFromMixed($invokeCall['args']);
        $allContractIds = array_values(array_unique(array_merge([$targetContractId], $argumentContractIds)));

        $operationType = (int) ($row['operation_type'] ?? 24);
        $hostFunctions = [
            'operationTypes' => [$this->mapOperationType($operationType)],
            'operationsCount' => isset($row['operation_count']) ? (int) $row['operation_count'] : 1,
            'effectsCount' => 0,
            'invokeContracts' => [$invokeCall],
        ];
        $hostFunctionsJson = json_encode($hostFunctions, JSON_UNESCAPED_SLASHES);

        $createdAt = $this->normalizeDateTime($row['created_at'] ?? null);
        $transactionRow = [
            'txHash' => $txHash,
            'sourceAccount' => $this->normalizeNullableString($row['tx_source_account'] ?? ($row['operation_source_account'] ?? null)),
            'hostFunctions' => is_string($hostFunctionsJson) ? $hostFunctionsJson : null,
            'feeCharged' => isset($row['fee_charged']) ? (int) $row['fee_charged'] : 0,
            'maxFee' => isset($row['max_fee']) ? (int) $row['max_fee'] : 0,
            'ledger' => isset($row['ledger_sequence']) ? (int) $row['ledger_sequence'] : null,
            'totalOperations' => isset($row['operation_count']) ? (int) $row['operation_count'] : 1,
            'createdAt' => $createdAt,
        ];

        return [
            'targetContractId' => $targetContractId,
            'allContractIds' => $allContractIds,
            'transactionRow' => $transactionRow,
            'eventRows' => $this->buildDerivedEvents($targetContractId, $txHash, $invokeCall, $transactionRow),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeOperationDetails(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * @return array{contractId:string,functionName:string,args:list<mixed>}|null
     */
    private function extractInvokeCallFromDetails(array $details): ?array
    {
        $parameters = $details['parameters'] ?? null;
        if (!is_array($parameters) || $parameters === []) {
            return null;
        }

        $decodedParams = [];
        foreach ($parameters as $param) {
            if (!is_array($param)) {
                continue;
            }
            $rawValue = $param['value'] ?? null;
            if (!is_string($rawValue) || trim($rawValue) === '') {
                $decodedParams[] = null;
                continue;
            }
            $decodedParams[] = $this->decodeScValBase64ToNative($rawValue);
        }

        $targetContractId = $this->normalizeContractId($decodedParams[0] ?? null);
        if ($targetContractId === null) {
            return null;
        }

        $functionName = trim((string) ($decodedParams[1] ?? 'unknown'));
        if ($functionName === '') {
            $functionName = 'unknown';
        }

        $args = [];
        $total = count($decodedParams);
        for ($i = 2; $i < $total; $i++) {
            $args[] = $decodedParams[$i];
        }

        return [
            'contractId' => $targetContractId,
            'functionName' => $functionName,
            'args' => $args,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buildDerivedEvents(string $targetContractId, string $txHash, array $invokeCall, array $transactionRow): array
    {
        $function = strtolower(trim((string) ($invokeCall['functionName'] ?? '')));
        if (!in_array($function, ['transfer', 'mint', 'burn'], true)) {
            return [];
        }

        $args = is_array($invokeCall['args'] ?? null) ? $invokeCall['args'] : [];
        $addresses = [];
        $amountRaw = null;
        $valueDecoded = null;

        if ($function === 'transfer') {
            $from = $this->normalizeAddress($args[0] ?? null);
            $to = $this->normalizeAddress($args[1] ?? null);
            $amountRaw = $this->normalizeAmountRaw($args[2] ?? null);
            $addresses = array_values(array_filter([$from, $to], static fn (?string $v): bool => $v !== null));
            $valueDecoded = ['from' => $from, 'to' => $to, 'amount' => $amountRaw];
        } elseif ($function === 'mint' || $function === 'burn') {
            $address = $this->normalizeAddress($args[0] ?? null);
            $amountRaw = $this->normalizeAmountRaw($args[1] ?? null);
            $addresses = array_values(array_filter([$address], static fn (?string $v): bool => $v !== null));
            $valueDecoded = ['address' => $address, 'amount' => $amountRaw];
        }

        if ($addresses === [] || $amountRaw === null) {
            return [];
        }

        $counterKey = $targetContractId . '|' . $txHash;
        $this->eventIndexCounters[$counterKey] = (int) (($this->eventIndexCounters[$counterKey] ?? 0) + 1);
        $eventIndex = $this->eventIndexCounters[$counterKey];

        return [[
            'txHash' => $txHash,
            'eventIndex' => $eventIndex,
            'ledger' => isset($transactionRow['ledger']) ? (int) $transactionRow['ledger'] : null,
            'ledgerClosedAt' => $transactionRow['createdAt'] ?? null,
            'eventType' => $function,
            'topicDecoded' => [$function],
            'valueDecoded' => $valueDecoded,
            'addresses' => $addresses,
            'amountRaw' => $amountRaw,
        ]];
    }

    private function resolveOrCreateContractDbId(string $contractId, int $networkCode, ?string $createdAt, bool $dryRun): ?int
    {
        if (isset($this->contractDbIdCache[$contractId])) {
            return $this->contractDbIdCache[$contractId];
        }

        $existingId = $this->connection->fetchOne(
            'SELECT id
             FROM contracts
             WHERE contract_id = :contract_id AND network = :network
             LIMIT 1',
            [
                'contract_id' => $contractId,
                'network' => $networkCode,
            ],
            [
                'network' => ParameterType::INTEGER,
            ]
        );
        if ($existingId !== false) {
            $id = (int) $existingId;
            if ($id > 0) {
                $this->contractDbIdCache[$contractId] = $id;
                return $id;
            }
        }

        if ($dryRun) {
            return null;
        }

        $contractHex = $this->decodeContractIdHexOrNull($contractId);
        $createdAt = $createdAt ?? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $this->connection->executeStatement(
            $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform
                ? 'INSERT INTO contracts (contract_id, contract_id_hex, network, created_at)
                   VALUES (:contract_id, :contract_id_hex, :network, :created_at)
                   ON CONFLICT (contract_id, network) DO NOTHING'
                : 'INSERT INTO contracts (contract_id, contract_id_hex, network, created_at)
                   VALUES (:contract_id, :contract_id_hex, :network, :created_at)
                   ON DUPLICATE KEY UPDATE contract_id = contract_id',
            [
                'contract_id' => $contractId,
                'contract_id_hex' => $contractHex,
                'network' => $networkCode,
                'created_at' => $createdAt,
            ],
            [
                'contract_id_hex' => $contractHex !== null ? ParameterType::STRING : ParameterType::NULL,
                'network' => ParameterType::INTEGER,
                'created_at' => ParameterType::STRING,
            ]
        );

        $resolvedId = $this->connection->fetchOne(
            'SELECT id
             FROM contracts
             WHERE contract_id = :contract_id AND network = :network
             LIMIT 1',
            [
                'contract_id' => $contractId,
                'network' => $networkCode,
            ],
            [
                'network' => ParameterType::INTEGER,
            ]
        );
        if ($resolvedId === false) {
            return null;
        }

        $id = (int) $resolvedId;
        if ($id <= 0) {
            return null;
        }
        $this->contractDbIdCache[$contractId] = $id;

        return $id;
    }

    /**
     * @return list<string>
     */
    private function extractContractIdsFromMixed(mixed $value, int $depth = 0): array
    {
        if ($depth > 8) {
            return [];
        }

        $found = [];
        if (is_string($value)) {
            $normalized = $this->normalizeContractId($value);
            if ($normalized !== null) {
                $found[$normalized] = true;
            }

            $jsonDecoded = json_decode($value, true);
            if (is_array($jsonDecoded)) {
                foreach ($jsonDecoded as $nested) {
                    foreach ($this->extractContractIdsFromMixed($nested, $depth + 1) as $nestedId) {
                        $found[$nestedId] = true;
                    }
                }
            }
        } elseif (is_array($value)) {
            foreach ($value as $nested) {
                foreach ($this->extractContractIdsFromMixed($nested, $depth + 1) as $nestedId) {
                    $found[$nestedId] = true;
                }
            }
        }

        return array_keys($found);
    }

    private function normalizeAddress(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (preg_match('/^[GC][A-Z2-7]{55}$/', $trimmed) !== 1) {
            return null;
        }

        return $trimmed;
    }

    private function normalizeContractId(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $normalized = $this->sorobanContractInspector->normalizeContractId(trim($value));
        if (!is_string($normalized) || $normalized === '') {
            return null;
        }

        return strtoupper($normalized);
    }

    private function normalizeAmountRaw(mixed $amount): ?string
    {
        if (is_int($amount)) {
            return (string) $amount;
        }
        if (is_string($amount) && preg_match('/^-?[0-9]+$/', $amount) === 1) {
            return $amount;
        }
        if (
            is_array($amount)
            && array_key_exists('hi', $amount)
            && array_key_exists('lo', $amount)
        ) {
            $hi = $amount['hi'];
            $lo = $amount['lo'];
            if ((is_int($hi) || is_string($hi)) && (is_int($lo) || is_string($lo))) {
                $hiNum = (string) $hi;
                $loNum = (string) $lo;
                if (preg_match('/^-?[0-9]+$/', $hiNum) !== 1 || preg_match('/^-?[0-9]+$/', $loNum) !== 1) {
                    return null;
                }
                if ($hiNum === '0') {
                    return $loNum;
                }
                if (!function_exists('gmp_init')) {
                    return null;
                }
                $result = gmp_add(gmp_mul(gmp_init($hiNum, 10), gmp_pow(2, 64)), gmp_init($loNum, 10));
                return gmp_strval($result, 10);
            }
        }

        return null;
    }

    private function decodeScValBase64ToNative(string $raw): mixed
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        try {
            return $this->sorobanScValMapper->scValToNative(XdrSCVal::fromBase64Xdr($raw));
        } catch (\Throwable) {
            return $raw;
        }
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

    private function mapOperationType(int $type): string
    {
        return match ($type) {
            24 => 'invoke_host_function',
            25 => 'extend_footprint_ttl',
            26 => 'restore_footprint',
            default => 'contract_operation',
        };
    }

    private function decodeContractIdHexOrNull(string $contractId): ?string
    {
        try {
            return StrKey::decodeContractIdHex($contractId);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveHorizonConnection(string $network): ?Connection
    {
        $connectionName = match ($network) {
            'mainnet' => 'horizon_mainnet',
            'testnet' => 'horizon_testnet',
            default => 'horizon_connection',
        };

        try {
            return $this->doctrine->getConnection($connectionName);
        } catch (\Throwable) {
            if ($connectionName === 'horizon_connection') {
                return null;
            }
        }

        try {
            return $this->doctrine->getConnection('horizon_connection');
        } catch (\Throwable) {
            return null;
        }
    }
}
