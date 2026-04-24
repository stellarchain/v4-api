<?php

namespace App\Command\Import;

use Soneso\StellarSDK\Crypto\StrKey;
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
    name: 'app:external-db:extract',
    description: 'Import contracts and contract transactions from external DB.',
)]
final class ExtractExternalDbDataCommand extends Command
{
    private const TARGET_NETWORK = 1;
    private const EXTERNAL_WASM_TABLE = 'contract_wasms';

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private readonly Connection $localConnection,
        #[Autowire(service: 'doctrine.dbal.external_connection')]
        private readonly Connection $externalConnection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Rows per batch per table', 500)
            ->addOption('max-contracts', null, InputOption::VALUE_REQUIRED, 'Max external contracts rows to import')
            ->addOption('max-transactions', null, InputOption::VALUE_REQUIRED, 'Max external transactions rows to import')
            ->addOption('check-only', null, InputOption::VALUE_NONE, 'Only test external DB connection and exit')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Read and map rows without writing to local database');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $batchSize = (int) $input->getOption('batch-size');
        $maxContracts = $this->normalizeMaxRows($input->getOption('max-contracts'));
        $maxTransactions = $this->normalizeMaxRows($input->getOption('max-transactions'));
        $checkOnly = (bool) $input->getOption('check-only');
        $dryRun = (bool) $input->getOption('dry-run');

        if ($batchSize < 1) {
            $io->error('--batch-size must be >= 1.');
            return Command::FAILURE;
        }

        try {
            $connectionInfo = $this->checkExternalConnection();
            $io->writeln(sprintf(
                'External DB connection OK (database=%s).',
                $connectionInfo['database'] ?? 'unknown'
            ));

            if ($checkOnly) {
                $io->success('Connection check completed.');
                return Command::SUCCESS;
            }

            $externalTransactionsSource = $this->resolveExternalTransactionsSource();
            $supportsWasmSources = $this->externalTableExists(self::EXTERNAL_WASM_TABLE);
            $wasmSourceTotal = $supportsWasmSources ? $this->countExternalContractWasms() : 0;
            $contractTotal = $this->countExternalContracts();
            $transactionTotal = $this->countExternalTransactions($externalTransactionsSource['table']);
            $externalWasmIdMap = $supportsWasmSources ? $this->loadExternalWasmIdMap() : [];

            $wasmSummary = ['read' => 0, 'written' => 0];
            if ($supportsWasmSources) {
                $io->section('Importing contract wasm sources');
                $io->progressStart($this->resolveProgressMax($wasmSourceTotal, null));
                $wasmSummary = $this->importContractWasms($batchSize, $dryRun, function () use ($io): void {
                    $io->progressAdvance();
                });
                $io->progressFinish();
            }

            $io->section('Importing contracts');
            $io->progressStart($this->resolveProgressMax($contractTotal, $maxContracts));
            $contractSummary = $this->importContracts($batchSize, $maxContracts, $dryRun, $externalWasmIdMap, function () use ($io): void {
                $io->progressAdvance();
            });
            $io->progressFinish();

            $io->section(sprintf('Importing transactions (%s)', $externalTransactionsSource['table']));
            $io->progressStart($this->resolveProgressMax($transactionTotal, $maxTransactions));
            $externalContractAddressById = $this->loadExternalContractAddressMapById();
            $transactionSummary = $this->importTransactions($batchSize, $maxTransactions, $dryRun, $externalTransactionsSource, $externalContractAddressById, function () use ($io): void {
                $io->progressAdvance();
            });
            $io->progressFinish();
        } catch (\Throwable $exception) {
            $io->error(sprintf('Import failed: %s', $exception->getMessage()));
            return Command::FAILURE;
        }

        $io->table(
            ['Segment', 'Read', 'Written', 'Skipped'],
            [
                ['contract_wasms', (string) $wasmSummary['read'], (string) $wasmSummary['written'], '0'],
                ['contracts', (string) $contractSummary['read'], (string) $contractSummary['written'], '0'],
                ['transactions', (string) $transactionSummary['read'], (string) $transactionSummary['written'], (string) $transactionSummary['skipped']],
            ]
        );
        $io->success($dryRun ? 'Dry-run completed.' : 'Import completed.');

        return Command::SUCCESS;
    }

    /**
     * @param array<int,string> $externalWasmIdMap
     */
    private function importContracts(int $batchSize, ?int $maxRows, bool $dryRun, array $externalWasmIdMap, ?callable $onRead = null): array
    {
        $read = 0;
        $written = 0;
        $lastId = 0;
        $seenContractKeys = [];

        while (true) {
            $rows = $this->externalConnection->fetchAllAssociative(
                <<<SQL
SELECT id, contract_id, asset_code, asset_address, asset_issuer, created_at,
       contract_code, source_code_verified, contract_type, network
FROM contracts
WHERE id > :last_id
  AND network = :target_network
ORDER BY id ASC
LIMIT :batch_size
SQL,
                [
                    'last_id' => $lastId,
                    'target_network' => self::TARGET_NETWORK,
                    'batch_size' => $batchSize,
                ],
                [
                    'last_id' => ParameterType::INTEGER,
                    'target_network' => ParameterType::INTEGER,
                    'batch_size' => ParameterType::INTEGER,
                ],
            );

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                $read++;
                $onRead?->__invoke();

                if ($maxRows !== null && $read > $maxRows) {
                    return ['read' => $maxRows, 'written' => $written];
                }

                if ($dryRun) {
                    continue;
                }

                $normalizedContractId = $this->normalizeContractIdForStorage((string) $row['contract_id']);
                $contractIdHex = $this->decodeContractIdHexOrNull($normalizedContractId);
                $externalWasmPk = isset($row['wasm_id']) ? (int) $row['wasm_id'] : null;
                $contractCode = $this->nullableString($row['contract_code'] ?? null);
                $sha256sum = $this->nullableString($row['sha256sum'] ?? null);
                $wasmId = ($externalWasmPk !== null && $externalWasmPk > 0)
                    ? ($externalWasmIdMap[$externalWasmPk] ?? null)
                    : null;
                if ($wasmId === null) {
                    $wasmId = $this->extractWasmIdFromContractCode($sha256sum) ?? $this->extractWasmIdFromContractCode($contractCode);
                }
                $executableType = $row['contract_type'] !== null ? (int) $row['contract_type'] : null;
                $isSac = $executableType === 1;

                $contractKey = $normalizedContractId . "\0" . (int) ($row['network'] ?? self::TARGET_NETWORK);
                if (isset($seenContractKeys[$contractKey])) {
                    continue;
                }
                $seenContractKeys[$contractKey] = true;

                $this->localConnection->executeStatement(
                    <<<SQL
INSERT INTO contracts (
    contract_id, contract_id_hex, asset_code, asset_address, asset_issuer, created_at,
    source_code_verified, contract_type, network, wasm_id, executable_type, is_sac
)
VALUES (
    :contract_id, :contract_id_hex, :asset_code, :asset_address, :asset_issuer, :created_at,
    :source_code_verified, :contract_type, :network, :wasm_id, :executable_type, :is_sac
)
ON DUPLICATE KEY UPDATE
    contract_id_hex = VALUES(contract_id_hex),
    asset_code = VALUES(asset_code),
    asset_address = VALUES(asset_address),
    asset_issuer = VALUES(asset_issuer),
    created_at = VALUES(created_at),
    source_code_verified = VALUES(source_code_verified),
    contract_type = VALUES(contract_type),
    network = VALUES(network),
    wasm_id = VALUES(wasm_id),
    executable_type = VALUES(executable_type),
    is_sac = VALUES(is_sac)
SQL,
                    [
                        'contract_id' => $normalizedContractId,
                        'contract_id_hex' => $contractIdHex,
                        'asset_code' => $this->nullableString($row['asset_code'] ?? null),
                        'asset_address' => $this->nullableString($row['asset_address'] ?? null),
                        'asset_issuer' => $this->nullableString($row['asset_issuer'] ?? null),
                        'created_at' => $this->normalizeDateTime($row['created_at'] ?? null),
                        'source_code_verified' => (int) ($row['source_code_verified'] ?? 0),
                        'contract_type' => $executableType,
                        'network' => $row['network'] !== null ? (int) $row['network'] : null,
                        'wasm_id' => $wasmId,
                        'executable_type' => $executableType,
                        'is_sac' => $isSac ? 1 : 0,
                    ],
                    [
                        'source_code_verified' => ParameterType::INTEGER,
                        'contract_type' => $row['contract_type'] !== null ? ParameterType::INTEGER : ParameterType::NULL,
                        'network' => $row['network'] !== null ? ParameterType::INTEGER : ParameterType::NULL,
                        'executable_type' => $executableType !== null ? ParameterType::INTEGER : ParameterType::NULL,
                        'is_sac' => ParameterType::INTEGER,
                    ],
                );
                $written++;
            }
        }

        return ['read' => $read, 'written' => $written];
    }

    private function importContractWasms(int $batchSize, bool $dryRun, ?callable $onRead = null): array
    {
        $read = 0;
        $written = 0;
        $lastId = 0;

        while (true) {
            $rows = $this->externalConnection->fetchAllAssociative(
                <<<SQL
SELECT id, contract_code, created_at, updated_at
FROM contract_wasms
WHERE id > :last_id
ORDER BY id ASC
LIMIT :batch_size
SQL,
                [
                    'last_id' => $lastId,
                    'batch_size' => $batchSize,
                ],
                [
                    'last_id' => ParameterType::INTEGER,
                    'batch_size' => ParameterType::INTEGER,
                ],
            );

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                $read++;
                $onRead?->__invoke();

                $wasmId = $this->extractWasmIdFromContractCode($this->nullableString($row['contract_code'] ?? null));
                if ($wasmId === null || $dryRun) {
                    continue;
                }

                $this->localConnection->executeStatement(
                    <<<SQL
INSERT INTO contract_sources (
    wasm_id, source_code, source_code_sha256, wasm_blob, wasm_blob_sha256, status, error_message, decompiled_at, created_at, updated_at
)
VALUES (
    :wasm_id, NULL, NULL, NULL, :wasm_blob_sha256, 0, NULL, NULL, :created_at, :updated_at
)
ON DUPLICATE KEY UPDATE
    wasm_blob_sha256 = VALUES(wasm_blob_sha256),
    updated_at = VALUES(updated_at)
SQL,
                    [
                        'wasm_id' => $wasmId,
                        'wasm_blob_sha256' => $wasmId,
                        'created_at' => $this->normalizeDateTime($row['created_at'] ?? null) ?? (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                        'updated_at' => $this->normalizeDateTime($row['updated_at'] ?? null) ?? (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                    ],
                );
                $written++;
            }
        }

        return ['read' => $read, 'written' => $written];
    }

    /**
     * @param array<int,string> $externalContractAddressById
     */
    private function importTransactions(
        int $batchSize,
        ?int $maxRows,
        bool $dryRun,
        array $externalTransactionsSource,
        array $externalContractAddressById,
        ?callable $onRead = null
    ): array
    {
        $externalTable = $externalTransactionsSource['table'];
        $txHashColumn = $externalTransactionsSource['tx_hash_column'];
        $localContractIdByAddress = $this->loadContractMapByContractAddress();
        $read = 0;
        $written = 0;
        $skipped = 0;
        $lastId = 0;

        while (true) {
            $candidateRows = $this->externalConnection->fetchAllAssociative(
                sprintf(
                    <<<SQL
SELECT t.id, t.contract_id AS external_contract_id
FROM %s t
INNER JOIN contracts c ON c.id = t.contract_id
WHERE c.network = :target_network
  AND t.id > :last_id
ORDER BY t.id ASC
LIMIT :batch_size
SQL,
                    $externalTable,
                ),
                [
                    'last_id' => $lastId,
                    'target_network' => self::TARGET_NETWORK,
                    'batch_size' => $batchSize,
                ],
                [
                    'last_id' => ParameterType::INTEGER,
                    'target_network' => ParameterType::INTEGER,
                    'batch_size' => ParameterType::INTEGER,
                ],
            );

            if ($candidateRows === []) {
                break;
            }

            $ids = [];
            $externalContractIdByTxId = [];
            foreach ($candidateRows as $candidateRow) {
                $txId = (int) $candidateRow['id'];
                if ($txId <= 0) {
                    continue;
                }
                $ids[] = $txId;
                $externalContractIdByTxId[$txId] = (int) ($candidateRow['external_contract_id'] ?? 0);
            }

            if ($ids === []) {
                $lastId = (int) $candidateRows[array_key_last($candidateRows)]['id'];
                continue;
            }

            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $detailRows = $this->externalConnection->fetchAllAssociative(
                sprintf(
                    <<<SQL
SELECT t.id, t.%s AS tx_hash_value, t.source_account, t.host_functions, t.fee_charged, t.max_fee, t.ledger, t.total_operations, t.created_at
FROM %s t
WHERE t.id IN (%s)
ORDER BY t.id ASC
SQL,
                    $txHashColumn,
                    $externalTable,
                    $placeholders,
                ),
                $ids,
                array_fill(0, count($ids), ParameterType::INTEGER),
            );

            $lastId = (int) $candidateRows[array_key_last($candidateRows)]['id'];

            foreach ($detailRows as $row) {
                $txRowId = (int) $row['id'];
                $externalContractId = isset($row['external_contract_id']) ? (int) $row['external_contract_id'] : 0;
                if ($externalContractId <= 0) {
                    $externalContractId = $externalContractIdByTxId[$txRowId] ?? 0;
                }
                $externalContractAddress = $externalContractAddressById[$externalContractId] ?? null;
                if ($externalContractAddress === null) {
                    continue;
                }

                $read++;
                $onRead?->__invoke();

                if ($maxRows !== null && $read > $maxRows) {
                    return ['read' => $maxRows, 'written' => $written, 'skipped' => $skipped];
                }

                $normalizedContractAddress = $this->normalizeContractIdForStorage($externalContractAddress);
                $localContractId = $localContractIdByAddress[$normalizedContractAddress] ?? null;
                if ($localContractId === null) {
                    $skipped++;
                    continue;
                }

                if ($dryRun) {
                    continue;
                }

                $this->localConnection->executeStatement(
                    <<<SQL
INSERT INTO contract_transactions (
    contract_id, tx_hash, source_account, host_functions, fee_charged, max_fee, ledger, total_operations, created_at
)
VALUES (
    :contract_id, :tx_hash, :source_account, :host_functions, :fee_charged, :max_fee, :ledger, :total_operations, :created_at
)
ON DUPLICATE KEY UPDATE
    source_account = VALUES(source_account),
    host_functions = VALUES(host_functions),
    fee_charged = VALUES(fee_charged),
    max_fee = VALUES(max_fee),
    ledger = VALUES(ledger),
    total_operations = VALUES(total_operations),
    created_at = VALUES(created_at)
SQL,
                    [
                        'contract_id' => $localContractId,
                        'tx_hash' => (string) $row['tx_hash_value'],
                        'source_account' => $this->nullableString($row['source_account'] ?? null),
                        'host_functions' => $this->normalizeHostFunctionsJson($row['host_functions'] ?? null, $row['total_operations'] ?? null),
                        'fee_charged' => (int) ($row['fee_charged'] ?? 0),
                        'max_fee' => (int) ($row['max_fee'] ?? 0),
                        'ledger' => $row['ledger'] !== null ? (int) $row['ledger'] : null,
                        'total_operations' => $row['total_operations'] !== null ? (int) $row['total_operations'] : null,
                        'created_at' => $this->normalizeDateTime($row['created_at'] ?? null),
                    ],
                    [
                        'contract_id' => ParameterType::INTEGER,
                        'fee_charged' => ParameterType::INTEGER,
                        'max_fee' => ParameterType::INTEGER,
                        'ledger' => $row['ledger'] !== null ? ParameterType::INTEGER : ParameterType::NULL,
                        'total_operations' => $row['total_operations'] !== null ? ParameterType::INTEGER : ParameterType::NULL,
                    ],
                );
                $written++;
            }
        }

        return ['read' => $read, 'written' => $written, 'skipped' => $skipped];
    }

    /**
     * @return array{database:string|null}
     */
    private function checkExternalConnection(): array
    {
        $ping = $this->externalConnection->fetchOne('SELECT 1');
        if ((int) $ping !== 1) {
            throw new \RuntimeException('External DB connection ping failed.');
        }

        $database = $this->externalConnection->fetchOne('SELECT DATABASE()');

        return ['database' => is_string($database) ? $database : null];
    }

    private function countExternalContracts(): int
    {
        return (int) $this->externalConnection->fetchOne(
            'SELECT COUNT(*) FROM contracts WHERE network = :target_network',
            ['target_network' => self::TARGET_NETWORK],
            ['target_network' => ParameterType::INTEGER],
        );
    }

    private function countExternalContractWasms(): int
    {
        return (int) $this->externalConnection->fetchOne('SELECT COUNT(*) FROM contract_wasms');
    }

    private function countExternalTransactions(string $externalTable): int
    {
        return (int) $this->externalConnection->fetchOne(
            sprintf(
                <<<SQL
SELECT COUNT(*)
FROM %s t
INNER JOIN contracts c ON c.id = t.contract_id
WHERE c.network = :target_network
SQL,
                $externalTable,
            ),
            ['target_network' => self::TARGET_NETWORK],
            ['target_network' => ParameterType::INTEGER],
        );
    }

    private function resolveProgressMax(int $total, ?int $maxRows): int
    {
        if ($maxRows !== null) {
            return max(0, min($total, $maxRows));
        }

        return max(0, $total);
    }

    /**
     * @return array{table:string,tx_hash_column:string}
     */
    private function resolveExternalTransactionsSource(): array
    {
        if ($this->externalTableExists('transactions')) {
            return [
                'table' => 'transactions',
                'tx_hash_column' => 'hash',
            ];
        }

        if ($this->externalTableExists('contract_transactions')) {
            return [
                'table' => 'contract_transactions',
                'tx_hash_column' => 'tx_hash',
            ];
        }

        throw new \RuntimeException('Neither transactions nor contract_transactions table exists on external connection.');
    }

    private function externalTableExists(string $tableName): bool
    {
        $count = $this->externalConnection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name',
            ['table_name' => $tableName]
        );

        return ((int) $count) > 0;
    }

    /**
     * @return array<int,string>
     */
    private function loadExternalWasmIdMap(): array
    {
        $rows = $this->externalConnection->fetchAllAssociative(
            'SELECT id, contract_code FROM contract_wasms'
        );
        $map = [];
        foreach ($rows as $row) {
            $externalId = isset($row['id']) ? (int) $row['id'] : 0;
            if ($externalId <= 0) {
                continue;
            }

            $wasmId = $this->extractWasmIdFromContractCode($this->nullableString($row['contract_code'] ?? null));
            if ($wasmId === null) {
                continue;
            }

            $map[$externalId] = $wasmId;
        }

        return $map;
    }

    /**
     * @return array<int,string>
     */
    private function loadExternalContractAddressMapById(): array
    {
        $rows = $this->externalConnection->fetchAllAssociative(
            'SELECT id, contract_id FROM contracts WHERE network = :target_network',
            ['target_network' => self::TARGET_NETWORK],
            ['target_network' => ParameterType::INTEGER],
        );

        $map = [];
        foreach ($rows as $row) {
            $id = isset($row['id']) ? (int) $row['id'] : 0;
            $contractAddress = $this->nullableString($row['contract_id'] ?? null);
            if ($id <= 0 || $contractAddress === null) {
                continue;
            }
            $map[$id] = $contractAddress;
        }

        return $map;
    }

    /**
     * @return array<string, int>
     */
    private function loadContractMapByContractAddress(): array
    {
        $rows = $this->localConnection->fetchAllAssociative(
            'SELECT id, contract_id FROM contracts'
        );
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['contract_id']] = (int) $row['id'];
        }

        return $map;
    }

    private function normalizeMaxRows(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $value = (int) $raw;
        if ($value <= 0) {
            throw new \InvalidArgumentException('Max rows options must be positive integers.');
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $stringValue = trim((string) $value);
        if ($stringValue === '') {
            return null;
        }

        return $stringValue;
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return (string) $value;
    }

    private function normalizeHostFunctionsJson(mixed $value, mixed $totalOperations): ?string
    {
        $raw = $this->nullableString($value);
        if ($raw === null) {
            return null;
        }

        $operationsCount = is_numeric($totalOperations) ? max(0, (int) $totalOperations) : 1;

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return $this->buildHostFunctionsJsonFromLegacyString($raw, $operationsCount);
        }

        if (!is_array($decoded)) {
            return $this->buildHostFunctionsJsonFromLegacyString($raw, $operationsCount);
        }

        if (!isset($decoded['operationTypes']) || !is_array($decoded['operationTypes'])) {
            $decoded['operationTypes'] = $this->defaultOperationTypesFromPayload($decoded);
        }
        if (!array_key_exists('operationsCount', $decoded) || !is_numeric($decoded['operationsCount'])) {
            $decoded['operationsCount'] = $operationsCount;
        }
        if (!array_key_exists('effectsCount', $decoded) || !is_numeric($decoded['effectsCount'])) {
            $decoded['effectsCount'] = 0;
        }
        if (!isset($decoded['invokeContracts']) || !is_array($decoded['invokeContracts'])) {
            $decoded['invokeContracts'] = [];
        }
        if (!isset($decoded['payments']) || !is_array($decoded['payments'])) {
            $decoded['payments'] = [];
        }

        return json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function buildHostFunctionsJsonFromLegacyString(string $raw, int $operationsCount): string
    {
        $tokens = preg_split('/[\s,]+/', trim($raw)) ?: [];
        $tokens = array_values(array_filter($tokens, static fn (string $token): bool => $token !== ''));
        $functionName = $tokens[0] ?? 'unknown';
        $args = [];
        foreach (array_slice($tokens, 1) as $token) {
            $args[] = $this->normalizeLegacyToken($token);
        }

        $payload = [
            'operationTypes' => ['invoke_host_function'],
            'operationsCount' => $operationsCount,
            'effectsCount' => 0,
            'invokeContracts' => [[
                'functionName' => $functionName,
                'args' => $args,
            ]],
            'payments' => [],
        ];

        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $raw;
    }

    private function normalizeLegacyToken(string $token): int|string
    {
        if (preg_match('/^-?\d+$/', $token) === 1) {
            return (int) $token;
        }

        return $token;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,string>
     */
    private function defaultOperationTypesFromPayload(array $payload): array
    {
        if (is_array($payload['payments'] ?? null) && $payload['payments'] !== []) {
            return ['payment'];
        }

        return ['invoke_host_function'];
    }

    private function normalizeContractIdForStorage(string $contractId): string
    {
        $trimmed = trim($contractId);
        if ($trimmed === '') {
            return $trimmed;
        }

        if (StrKey::isValidContractId($trimmed)) {
            return $trimmed;
        }

        if (preg_match('/^[0-9a-fA-F]{64}$/', $trimmed) === 1) {
            return StrKey::encodeContractIdHex(strtolower($trimmed));
        }

        return $trimmed;
    }

    private function decodeContractIdHexOrNull(string $contractId): ?string
    {
        if (!StrKey::isValidContractId($contractId)) {
            return null;
        }

        return StrKey::decodeContractIdHex($contractId);
    }

    private function extractWasmIdFromContractCode(?string $contractCode): ?string
    {
        if ($contractCode === null) {
            return null;
        }

        $trimmed = trim($contractCode);
        if (preg_match('/^[0-9a-fA-F]{64}$/', $trimmed) !== 1) {
            return null;
        }

        return strtolower($trimmed);
    }
}
