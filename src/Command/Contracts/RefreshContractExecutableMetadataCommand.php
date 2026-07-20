<?php

declare(strict_types=1);

namespace App\Command\Contracts;

use App\Command\Support\NetworkOptionTrait;
use App\Service\Stellar\Soroban\SorobanContractInspector;
use App\Service\Stellar\Soroban\SorobanServerFactory;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Soneso\StellarSDK\Soroban\SorobanServer;
use Soneso\StellarSDK\Xdr\XdrContractExecutableType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:contracts:refresh-executable-metadata',
    description: 'Refresh contract executable metadata (SAC/WASM/wasm_id) from Stellar RPC.',
)]
final class RefreshContractExecutableMetadataCommand extends Command
{
    use NetworkOptionTrait;

    private const DEFAULT_BATCH_SIZE = 200;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.contracts_connection')]
        private readonly Connection $connection,
        private readonly SorobanServerFactory $sorobanServerFactory,
        private readonly SorobanContractInspector $contractInspector,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addNetworkOption('mainnet|testnet|futurenet', 'mainnet')
            ->addOption('rpc-url', null, InputOption::VALUE_REQUIRED, 'Override Stellar RPC URL. If omitted, SOROBAN_RPC_* env is used.')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Rows per DB page.', (string) self::DEFAULT_BATCH_SIZE)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max contracts to inspect.')
            ->addOption('after-id', null, InputOption::VALUE_REQUIRED, 'Start after local contracts.id.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Refresh all contracts, not only rows missing executable metadata.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Inspect RPC but do not write updates.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $network = $this->resolveNetworkOption($input, $this->stellarNetworkResolver);
        $networkCode = $this->resolveNetworkCodeOption($input, $this->stellarNetworkResolver);
        $batchSize = $this->parsePositiveInt($input->getOption('batch-size')) ?? self::DEFAULT_BATCH_SIZE;
        $limit = $this->parsePositiveInt($input->getOption('limit'));
        $cursor = $this->parseNonNegativeInt($input->getOption('after-id')) ?? 0;
        $refreshAll = (bool) $input->getOption('all');
        $dryRun = (bool) $input->getOption('dry-run');
        $server = $this->createServer($input, $network);

        $metrics = [
            'processed' => 0,
            'updated' => 0,
            'sac' => 0,
            'wasm' => 0,
            'missing' => 0,
            'errors' => 0,
        ];
        $remaining = $limit;

        $io->writeln(sprintf(
            'Executable metadata refresh started | network=%s batch_size=%d limit=%s after_id=%d all=%d dry_run=%d',
            $network,
            $batchSize,
            $limit === null ? 'none' : (string) $limit,
            $cursor,
            $refreshAll ? 1 : 0,
            $dryRun ? 1 : 0,
        ));

        while (true) {
            $pageSize = $remaining !== null ? min($batchSize, $remaining) : $batchSize;
            if ($pageSize <= 0) {
                break;
            }

            $rows = $this->loadCandidates($networkCode, $cursor, $pageSize, $refreshAll);
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                $contractId = $this->normalizeContractId($row['contract_id'] ?? null);
                $cursor = max($cursor, $id);
                if ($id <= 0 || $contractId === null) {
                    continue;
                }

                $metrics['processed']++;

                try {
                    $meta = $this->contractInspector->loadContractExecutableMetaForContractId($server, $contractId);
                } catch (\Throwable $exception) {
                    $metrics['errors']++;
                    $io->writeln(sprintf('[error] id=%d contract=%s %s', $id, $contractId, $exception->getMessage()));
                    continue;
                }

                $executableType = is_int($meta['executableType'] ?? null) ? (int) $meta['executableType'] : null;
                $wasmId = is_string($meta['wasmId'] ?? null) && preg_match('/^[0-9a-fA-F]{64}$/', (string) $meta['wasmId']) === 1
                    ? strtolower((string) $meta['wasmId'])
                    : null;

                if ($executableType === null) {
                    $metrics['missing']++;
                    continue;
                }

                $isSac = $executableType === XdrContractExecutableType::CONTRACT_EXECUTABLE_STELLAR_ASSET;
                if ($isSac) {
                    $metrics['sac']++;
                    $wasmId = null;
                } else {
                    $metrics['wasm']++;
                }

                if ($dryRun) {
                    continue;
                }

                $this->updateContract($id, $executableType, $isSac, $wasmId);
                if ($wasmId !== null) {
                    $this->upsertContractSource($wasmId);
                }
                $metrics['updated']++;
            }

            if ($remaining !== null) {
                $remaining -= count($rows);
                if ($remaining <= 0) {
                    break;
                }
            }

            $io->writeln(sprintf(
                'Processed through id %d | processed=%d updated=%d sac=%d wasm=%d missing=%d errors=%d',
                $cursor,
                $metrics['processed'],
                $metrics['updated'],
                $metrics['sac'],
                $metrics['wasm'],
                $metrics['missing'],
                $metrics['errors'],
            ));
        }

        $io->table(
            ['Metric', 'Value'],
            [
                ['network', $network],
                ['processed', (string) $metrics['processed']],
                ['updated', (string) $metrics['updated']],
                ['sac', (string) $metrics['sac']],
                ['wasm', (string) $metrics['wasm']],
                ['missing_rpc_instance', (string) $metrics['missing']],
                ['errors', (string) $metrics['errors']],
                ['last_id', (string) $cursor],
                ['dry_run', $dryRun ? '1' : '0'],
            ]
        );

        $io->success($dryRun ? 'Executable metadata dry-run completed.' : 'Executable metadata refresh completed.');

        return Command::SUCCESS;
    }

    private function createServer(InputInterface $input, string $network): SorobanServer
    {
        $rpcUrl = $this->normalizeNullableString($input->getOption('rpc-url'));
        if ($rpcUrl !== null) {
            return new SorobanServer($rpcUrl);
        }

        return $this->sorobanServerFactory->create($network);
    }

    /**
     * @return list<array{id:int,contract_id:string}>
     */
    private function loadCandidates(int $networkCode, int $afterId, int $limit, bool $refreshAll): array
    {
        $filterSql = $refreshAll
            ? ''
            : 'AND (executable_type IS NULL OR (executable_type = 0 AND wasm_id IS NULL))';

        return $this->connection->fetchAllAssociative(
            'SELECT id, contract_id
             FROM contracts
             WHERE network = :network
               AND id > :after_id
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

    private function updateContract(int $id, int $executableType, bool $isSac, ?string $wasmId): void
    {
        $this->connection->update(
            'contracts',
            [
                'executable_type' => $executableType,
                'is_sac' => $isSac,
                'wasm_id' => $wasmId,
            ],
            ['id' => $id],
            [
                'id' => ParameterType::INTEGER,
                'executable_type' => ParameterType::INTEGER,
                'is_sac' => ParameterType::BOOLEAN,
                'wasm_id' => $wasmId !== null ? ParameterType::STRING : ParameterType::NULL,
            ]
        );
    }

    private function upsertContractSource(string $wasmId): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $this->connection->executeStatement(
            'INSERT INTO contract_sources (wasm_id, created_at, updated_at)
             VALUES (:wasm_id, :created_at, :updated_at)
             ON CONFLICT (wasm_id) DO UPDATE SET updated_at = contract_sources.updated_at',
            [
                'wasm_id' => $wasmId,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    private function normalizeContractId(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $normalized = $this->contractInspector->normalizeContractId(trim($value));

        return is_string($normalized) && $normalized !== '' ? strtoupper($normalized) : null;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value !== '' ? $value : null;
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
}
