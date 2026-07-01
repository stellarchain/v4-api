<?php

namespace App\Command\Soroban;

use App\Command\Support\NetworkOptionTrait;
use App\Service\SorobanRpcService;
use App\Service\Stellar\Soroban\Sep55ContractVerificationService;
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
    name: 'app:soroban:decompile-contracts',
    description: 'Decompile WASM source in batch for Soroban contracts from local DB.',
)]
final class DecompileWasmContractsCommand extends Command
{
    use NetworkOptionTrait;

    private const DEFAULT_BATCH_SIZE = 50;

    public function __construct(
        private readonly SorobanRpcService $sorobanRpcService,
        private readonly Sep55ContractVerificationService $sep55ContractVerificationService,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
        #[Autowire(service: 'doctrine.dbal.contracts_connection')]
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addNetworkOption('mainnet|testnet|futurenet', 'mainnet')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Batch size per DB page', (string) self::DEFAULT_BATCH_SIZE)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max contracts to process')
            ->addOption('after-id', null, InputOption::VALUE_REQUIRED, 'Start after local contract id (cursor)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Process all WASM contracts, not only missing/unverified')
            ->addOption('save-wasm-files', null, InputOption::VALUE_NONE, 'Save WASM files to var/wasm/<contractId>.wasm')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not persist updates');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $network = $this->resolveNetworkOption($input, $this->stellarNetworkResolver);
        $networkCode = $this->resolveNetworkCodeOption($input, $this->stellarNetworkResolver);
        $batchSize = $this->parsePositiveInt($input->getOption('batch-size'));
        $limit = $this->parsePositiveInt($input->getOption('limit'));
        $afterId = $this->parseNonNegativeInt($input->getOption('after-id')) ?? 0;
        $processAll = (bool) $input->getOption('all');
        $saveWasmFiles = (bool) $input->getOption('save-wasm-files');
        $dryRun = (bool) $input->getOption('dry-run');

        if ($batchSize === null) {
            $io->error('--batch-size must be a positive integer.');
            return Command::FAILURE;
        }

        $metrics = [
            'processed' => 0,
            'decompiled' => 0,
            'already_verified' => 0,
            'sac_skipped' => 0,
            'wasm_files_saved' => 0,
            'sep55_verified' => 0,
            'sep55_failed' => 0,
            'errors' => 0,
            'updated' => 0,
        ];

        $cursor = $afterId;
        $remaining = $limit;
        while (true) {
            $pageSize = $remaining !== null ? min($batchSize, $remaining) : $batchSize;
            if ($pageSize <= 0) {
                break;
            }

            $rows = $this->loadCandidates($networkCode, $cursor, $pageSize, $processAll);
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                $contractId = trim((string) ($row['contract_id'] ?? ''));
                $cursor = max($cursor, $id);
                if ($id <= 0 || $contractId === '') {
                    continue;
                }

                $metrics['processed']++;
                if ((bool) ($row['source_code_verified'] ?? false) && !$processAll) {
                    $metrics['already_verified']++;
                    continue;
                }

                $result = $this->sorobanRpcService->getContractWasmByContractId(
                    $contractId,
                    $network,
                    true,
                );
                if (!is_array($result)) {
                    $metrics['errors']++;
                    continue;
                }

                $isSac = (bool) ($result['isSac'] ?? false);
                if ($isSac) {
                    $metrics['sac_skipped']++;
                    if (!$dryRun) {
                        $this->connection->update(
                            'contracts',
                            [
                                'is_sac' => 1,
                                'executable_type' => isset($result['executableType']) && is_int($result['executableType']) ? (int) $result['executableType'] : null,
                            ],
                            ['id' => $id],
                            [
                                'id' => ParameterType::INTEGER,
                                'is_sac' => ParameterType::INTEGER,
                                'executable_type' => isset($result['executableType']) && is_int($result['executableType']) ? ParameterType::INTEGER : ParameterType::NULL,
                            ]
                        );
                        $metrics['updated']++;
                    }
                    continue;
                }

                $source = is_string($result['contractSourceCode'] ?? null) ? trim($result['contractSourceCode']) : '';
                if ($source !== '') {
                    $metrics['decompiled']++;
                }
                if (!$dryRun && $saveWasmFiles && $this->saveWasmFile($contractId, $result)) {
                    $metrics['wasm_files_saved']++;
                }

                $sep55Verification = $this->runSep55Verification($result);
                if ($sep55Verification['isVerified'] === true) {
                    $metrics['sep55_verified']++;
                } else {
                    $metrics['sep55_failed']++;
                }

                if ($dryRun) {
                    continue;
                }

                $checkedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
                $updated = $this->connection->update(
                    'contracts',
                    [
                        'contract_id_hex' => is_string($result['contractIdHex'] ?? null) ? $result['contractIdHex'] : null,
                        'source_code_verified' => $source !== '' ? 1 : 0,
                        'sep55_verified' => $sep55Verification['isVerified'] ? 1 : 0,
                        'github_address' => $sep55Verification['githubAddress'],
                        'sep55_commit_hash' => $sep55Verification['commitHash'],
                        'sep55_attestation_url' => $sep55Verification['attestationUrl'],
                        'sep55_error' => $sep55Verification['error'],
                        'sep55_last_checked_at' => $checkedAt,
                        'wasm_id' => is_string($result['wasmId'] ?? null) && $result['wasmId'] !== ''
                            ? $result['wasmId']
                            : (is_string($result['wasmSha256'] ?? null) ? $result['wasmSha256'] : null),
                        'executable_type' => isset($result['executableType']) && is_int($result['executableType']) ? (int) $result['executableType'] : null,
                        'is_sac' => 0,
                    ],
                    ['id' => $id],
                    [
                        'id' => ParameterType::INTEGER,
                        'source_code_verified' => ParameterType::INTEGER,
                        'sep55_verified' => ParameterType::INTEGER,
                        'github_address' => $sep55Verification['githubAddress'] !== null ? ParameterType::STRING : ParameterType::NULL,
                        'sep55_commit_hash' => $sep55Verification['commitHash'] !== null ? ParameterType::STRING : ParameterType::NULL,
                        'sep55_attestation_url' => $sep55Verification['attestationUrl'] !== null ? ParameterType::STRING : ParameterType::NULL,
                        'sep55_error' => $sep55Verification['error'] !== null ? ParameterType::STRING : ParameterType::NULL,
                        'sep55_last_checked_at' => ParameterType::STRING,
                        'executable_type' => isset($result['executableType']) && is_int($result['executableType']) ? ParameterType::INTEGER : ParameterType::NULL,
                        'is_sac' => ParameterType::INTEGER,
                    ]
                );
                if ($updated > 0) {
                    $metrics['updated']++;
                }
            }

            if ($remaining !== null) {
                $remaining -= count($rows);
                if ($remaining <= 0) {
                    break;
                }
            }
        }

        $io->table(
            ['Metric', 'Value'],
            [
                ['network', $network],
                ['processed', (string) $metrics['processed']],
                ['decompiled', (string) $metrics['decompiled']],
                ['already_verified', (string) $metrics['already_verified']],
                ['sac_skipped', (string) $metrics['sac_skipped']],
                ['wasm_files_saved', (string) $metrics['wasm_files_saved']],
                ['sep55_verified', (string) $metrics['sep55_verified']],
                ['sep55_failed', (string) $metrics['sep55_failed']],
                ['updated', (string) $metrics['updated']],
                ['errors', (string) $metrics['errors']],
                ['dry_run', $dryRun ? '1' : '0'],
            ]
        );

        $io->success($dryRun ? 'Dry-run completed.' : 'Batch decompile completed.');

        return Command::SUCCESS;
    }

    /**
     * @param array<string,mixed> $result
     * @return array{
     *   isVerified:bool,
     *   githubAddress:?string,
     *   commitHash:?string,
     *   attestationUrl:?string,
     *   error:?string
     * }
     */
    private function runSep55Verification(array $result): array
    {
        $wasmSha256 = is_string($result['wasmSha256'] ?? null) ? strtolower(trim((string) $result['wasmSha256'])) : '';
        $wasmCodeBase64 = is_string($result['wasmCodeBase64'] ?? null) ? trim((string) $result['wasmCodeBase64']) : '';
        if ($wasmSha256 === '' || $wasmCodeBase64 === '') {
            return [
                'isVerified' => false,
                'githubAddress' => null,
                'commitHash' => null,
                'attestationUrl' => null,
                'error' => 'WASM bytes are missing for SEP-55 verification.',
            ];
        }

        $wasmBytes = base64_decode($wasmCodeBase64, true);
        if (!is_string($wasmBytes) || $wasmBytes === '') {
            return [
                'isVerified' => false,
                'githubAddress' => null,
                'commitHash' => null,
                'attestationUrl' => null,
                'error' => 'Invalid WASM base64 payload.',
            ];
        }

        $verified = $this->sep55ContractVerificationService->verifyFromWasm($wasmSha256, $wasmBytes);

        return [
            'isVerified' => (bool) ($verified['isVerified'] ?? false),
            'githubAddress' => is_string($verified['githubAddress'] ?? null) ? $verified['githubAddress'] : null,
            'commitHash' => is_string($verified['commitHash'] ?? null) ? $verified['commitHash'] : null,
            'attestationUrl' => is_string($verified['attestationUrl'] ?? null) ? $verified['attestationUrl'] : null,
            'error' => is_string($verified['error'] ?? null) ? $verified['error'] : null,
        ];
    }

    /**
     * @return list<array{id:int,contract_id:string,source_code_verified:int|bool}>
     */
    private function loadCandidates(int $networkCode, int $afterId, int $limit, bool $processAll): array
    {
        $filterSql = $processAll
            ? ''
            : 'AND (source_code_verified = 0 OR source_code_verified IS NULL OR wasm_id IS NULL OR executable_type IS NULL)';

        return $this->connection->fetchAllAssociative(
            'SELECT id, contract_id, source_code_verified
             FROM contracts
             WHERE network = :network
               AND id > :after_id
               AND (is_sac = 0 OR is_sac IS NULL)
               AND contract_id IS NOT NULL
               AND contract_id <> \'\'
               ' . $filterSql . '
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
    }

    private function parsePositiveInt(mixed $value): ?int
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

    private function parseNonNegativeInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[0-9]+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }

    /**
     * @param array<string,mixed> $result
     */
    private function saveWasmFile(string $contractId, array $result): bool
    {
        $wasmCodeBase64 = is_string($result['wasmCodeBase64'] ?? null) ? $result['wasmCodeBase64'] : null;
        if ($wasmCodeBase64 === null || $wasmCodeBase64 === '') {
            return false;
        }

        $wasmBytes = base64_decode($wasmCodeBase64, true);
        if (!is_string($wasmBytes) || $wasmBytes === '') {
            return false;
        }

        $projectRoot = dirname(__DIR__, 3);
        $targetDir = $projectRoot . '/var/wasm';
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            return false;
        }

        $targetPath = sprintf('%s/%s.wasm', $targetDir, $contractId);
        $written = @file_put_contents($targetPath, $wasmBytes);

        return is_int($written) && $written > 0;
    }
}
