<?php

namespace App\Command\Horizon;

use App\Command\Support\NetworkOptionTrait;
use App\Service\ContractMetricsRefreshService;
use App\Service\ContractTxUpsertService;
use App\Service\Stellar\Soroban\SorobanContractInspector;
use App\Service\Stellar\Soroban\SorobanScValMapper;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Soneso\StellarSDK\Crypto\StrKey;
use Soneso\StellarSDK\Xdr\XdrSCVal;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:horizon:scan-contract-range',
    description: 'Backup scanner: read Horizon operations by ledger range and persist contract transactions/argument links locally.',
)]
final class ScanContractRangeFromHorizonCommand extends Command
{
    use NetworkOptionTrait;

    /** @var list<int> */
    private const CONTRACT_OPERATION_TYPES = [24, 25, 26];
    private const PAGE_SIZE = 200;

    /** @var array<string,array<string,mixed>|null> */
    private array $txMetaCache = [];
    /** @var array<string,int> */
    private array $contractDbIdCache = [];
    /** @var array<string,int> */
    private array $eventIndexCounters = [];

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.contracts_connection')]
        private readonly Connection $connection,
        private readonly HttpClientInterface $httpClient,
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
            ->addOption('horizon-url', null, InputOption::VALUE_REQUIRED, 'Horizon base URL.', 'https://horizon.stellar.org')
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
        $horizonUrl = rtrim(trim((string) ($input->getOption('horizon-url') ?? 'https://horizon.stellar.org')), '/');
        $dryRun = (bool) $input->getOption('dry-run');
        $deriveEvents = (bool) $input->getOption('derive-events');

        if ($startLedger === null || $endLedger === null) {
            $io->error('--start-ledger and --end-ledger must be positive integers.');
            return Command::FAILURE;
        }
        if ($startLedger > $endLedger) {
            [$startLedger, $endLedger] = [$endLedger, $startLedger];
        }
        if ($horizonUrl === '') {
            $io->error('--horizon-url cannot be empty.');
            return Command::FAILURE;
        }

        $metrics = [
            'ledgers_scanned' => 0,
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
            'Horizon contract scan started | network=%s ledgers=%d..%d dry_run=%d derive_events=%d horizon=%s',
            $network,
            $startLedger,
            $endLedger,
            $dryRun ? 1 : 0,
            $deriveEvents ? 1 : 0,
            $horizonUrl
        ));
        $io->progressStart(($endLedger - $startLedger) + 1);

        for ($ledger = $startLedger; $ledger <= $endLedger; $ledger++) {
            try {
                $ops = $this->loadLedgerOperations($horizonUrl, $ledger);
            } catch (\Throwable $e) {
                $metrics['errors']++;
                $io->newLine();
                $io->writeln(sprintf('[ledger=%d] failed: %s', $ledger, $e->getMessage()));
                $io->progressAdvance();
                continue;
            }

            $metrics['ledgers_scanned']++;
            $metrics['operations_scanned'] += count($ops);

            foreach ($ops as $operation) {
                $typeI = isset($operation['type_i']) ? (int) $operation['type_i'] : 0;
                if (!in_array($typeI, self::CONTRACT_OPERATION_TYPES, true)) {
                    continue;
                }
                $metrics['operations_contract_types']++;

                $parsed = $this->parseOperation($operation, $horizonUrl);
                if ($parsed === null) {
                    continue;
                }

                $targetContractId = $parsed['targetContractId'];
                $allContractIds = $parsed['allContractIds'];
                $transactionRow = $parsed['transactionRow'];
                $eventRows = $parsed['eventRows'];

                foreach ($allContractIds as $contractId) {
                    $contractDbId = $this->resolveOrCreateContractDbId($contractId, $networkCode, $transactionRow['createdAt'], $dryRun);
                    if ($contractDbId === null) {
                        continue;
                    }
                    $affectedContracts[$contractDbId] = true;
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

            $io->progressAdvance();
        }

        $io->progressFinish();
        $io->newLine();

        if (!$dryRun && $this->contractMetricsRefreshService !== null && $affectedContracts !== []) {
            $this->contractMetricsRefreshService->refreshForContractIds(array_keys($affectedContracts));
        }

        $io->table(
            ['Metric', 'Value'],
            [
                ['network', $network],
                ['scan_start_ledger', (string) $startLedger],
                ['scan_end_ledger', (string) $endLedger],
                ['ledgers_scanned', (string) $metrics['ledgers_scanned']],
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

        $io->success($dryRun ? 'Horizon contract scan dry-run completed.' : 'Horizon contract scan completed.');
        return Command::SUCCESS;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadLedgerOperations(string $horizonUrl, int $ledger): array
    {
        $url = sprintf('%s/ledgers/%d/operations?order=asc&limit=%d', $horizonUrl, $ledger, self::PAGE_SIZE);
        $seen = [];
        $all = [];

        while ($url !== '') {
            if (isset($seen[$url])) {
                break;
            }
            $seen[$url] = true;

            $payload = $this->fetchJson($url);
            $records = $payload['_embedded']['records'] ?? null;
            if (!is_array($records) || $records === []) {
                break;
            }
            foreach ($records as $record) {
                if (is_array($record)) {
                    $all[] = $record;
                }
            }

            $nextUrl = $payload['_links']['next']['href'] ?? null;
            if (!is_string($nextUrl) || trim($nextUrl) === '') {
                break;
            }
            if (count($records) < self::PAGE_SIZE) {
                break;
            }
            $url = $nextUrl;
        }

        return $all;
    }

    /**
     * @return array{
     *   targetContractId:string,
     *   allContractIds:list<string>,
     *   transactionRow:array<string,mixed>,
     *   eventRows:list<array<string,mixed>>
     * }|null
     */
    private function parseOperation(array $operation, string $horizonUrl): ?array
    {
        $txHash = trim((string) ($operation['transaction_hash'] ?? ''));
        if ($txHash === '') {
            return null;
        }

        $details = $operation;
        $type = strtolower(trim((string) ($operation['type'] ?? '')));
        $meta = $this->loadTransactionMeta($horizonUrl, $txHash);

        $invokeCall = $this->extractInvokeCallFromOperation($details);
        if ($invokeCall === null) {
            return null;
        }

        $targetContractId = $invokeCall['contractId'];
        $argumentContractIds = $this->extractContractIdsFromMixed($invokeCall['args']);
        $allContractIds = [$targetContractId];
        foreach ($argumentContractIds as $contractId) {
            $allContractIds[] = $contractId;
        }
        $allContractIds = array_values(array_unique($allContractIds));

        $hostFunctions = [
            'operationTypes' => [$type !== '' ? $type : 'invoke_host_function'],
            'operationsCount' => (int) ($meta['operation_count'] ?? 1),
            'effectsCount' => 0,
            'invokeContracts' => [$invokeCall],
        ];
        $hostFunctionsJson = json_encode($hostFunctions, JSON_UNESCAPED_SLASHES);

        $transactionRow = [
            'txHash' => $txHash,
            'sourceAccount' => $this->normalizeNullableString($meta['source_account'] ?? ($operation['source_account'] ?? null)),
            'hostFunctions' => is_string($hostFunctionsJson) ? $hostFunctionsJson : null,
            'feeCharged' => (int) ($meta['fee_charged'] ?? 0),
            'maxFee' => (int) ($meta['max_fee'] ?? 0),
            'ledger' => isset($meta['ledger']) ? (int) $meta['ledger'] : (isset($operation['ledger']) ? (int) $operation['ledger'] : null),
            'totalOperations' => isset($meta['operation_count']) ? (int) $meta['operation_count'] : 1,
            'createdAt' => $this->normalizeDateTime($meta['created_at'] ?? ($operation['created_at'] ?? null)),
        ];

        return [
            'targetContractId' => $targetContractId,
            'allContractIds' => $allContractIds,
            'transactionRow' => $transactionRow,
            'eventRows' => $this->buildDerivedEvents($targetContractId, $txHash, $invokeCall, $transactionRow),
        ];
    }

    /**
     * @return array{contractId:string,functionName:string,args:list<mixed>}|null
     */
    private function extractInvokeCallFromOperation(array $operation): ?array
    {
        $parameters = $operation['parameters'] ?? null;
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

        $functionName = trim((string) ($decodedParams[1] ?? ($operation['function'] ?? 'unknown')));
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

    /**
     * @return array<string,mixed>|null
     */
    private function loadTransactionMeta(string $horizonUrl, string $txHash): ?array
    {
        if (array_key_exists($txHash, $this->txMetaCache)) {
            return $this->txMetaCache[$txHash];
        }

        try {
            $payload = $this->fetchJson(sprintf('%s/transactions/%s', $horizonUrl, $txHash));
        } catch (\Throwable) {
            $this->txMetaCache[$txHash] = null;
            return null;
        }

        $meta = [
            'source_account' => $this->normalizeNullableString($payload['source_account'] ?? null),
            'fee_charged' => isset($payload['fee_charged']) ? (int) $payload['fee_charged'] : 0,
            'max_fee' => isset($payload['max_fee']) ? (int) $payload['max_fee'] : 0,
            'operation_count' => isset($payload['operation_count']) ? (int) $payload['operation_count'] : 1,
            'created_at' => $this->normalizeDateTime($payload['created_at'] ?? null),
            'ledger' => isset($payload['ledger']) ? (int) $payload['ledger'] : null,
        ];
        $this->txMetaCache[$txHash] = $meta;

        return $meta;
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

    /**
     * @return array<string,mixed>
     */
    private function fetchJson(string $url): array
    {
        $response = $this->httpClient->request('GET', $url, [
            'timeout' => 30,
            'headers' => ['Accept' => 'application/json'],
        ]);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf('HTTP %d for %s', $status, $url));
        }

        $payload = $response->toArray(false);
        if (!is_array($payload)) {
            throw new \RuntimeException(sprintf('Invalid JSON payload from %s', $url));
        }

        return $payload;
    }

    private function decodeContractIdHexOrNull(string $contractId): ?string
    {
        try {
            return StrKey::decodeContractIdHex($contractId);
        } catch (\Throwable) {
            return null;
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
}
