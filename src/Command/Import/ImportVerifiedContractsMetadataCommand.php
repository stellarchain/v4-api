<?php

declare(strict_types=1);

namespace App\Command\Import;

use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:contracts:import-verified-metadata',
    description: 'Import verified contract metadata (e.g. SAC asset code) from a JSON file into known local contracts.',
)]
final class ImportVerifiedContractsMetadataCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.contracts_connection')]
        private readonly Connection $connection,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to verified-contracts.json')
            ->addOption('network', null, InputOption::VALUE_REQUIRED, 'mainnet|testnet|futurenet', 'mainnet')
            ->addOption('only-sac', null, InputOption::VALUE_NEGATABLE, 'Update only SAC contracts (default: true)', true)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show changes without writing to DB');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = trim((string) $input->getOption('file'));
        if ($file === '' || !is_file($file)) {
            $io->error('--file is required and must point to an existing JSON file.');
            return Command::INVALID;
        }

        $network = $this->stellarNetworkResolver->normalizeNetwork(
            is_string($input->getOption('network')) ? $input->getOption('network') : null,
            'mainnet'
        );
        $networkCode = $this->stellarNetworkResolver->resolveNetworkCode($network);
        if ($networkCode === null) {
            $io->error('Invalid --network value.');
            return Command::INVALID;
        }

        $onlySac = (bool) $input->getOption('only-sac');
        $dryRun = (bool) $input->getOption('dry-run');

        $contracts = $this->loadContractsPayload($file);
        if ($contracts === []) {
            $io->warning('No contracts found in input JSON.');
            return Command::SUCCESS;
        }

        $metrics = [
            'input_rows' => count($contracts),
            'known_contracts' => 0,
            'contracts_updated' => 0,
            'metadata_upserted' => 0,
            'unchanged' => 0,
            'skipped_not_sac' => 0,
            'skipped_missing' => 0,
            'dry_run' => $dryRun ? 1 : 0,
        ];

        foreach ($contracts as $row) {
            $contractId = $this->normalizeContractId($row['id'] ?? null);

            if ($contractId === null) {
                continue;
            }

            $local = $this->connection->fetchAssociative(
                'SELECT id, asset_code, asset_address, executable_type, is_sac
                 FROM contracts
                 WHERE contract_id = :contract_id
                   AND network = :network
                 LIMIT 1',
                [
                    'contract_id' => $contractId,
                    'network' => $networkCode,
                ],
                [
                    'network' => ParameterType::INTEGER,
                ]
            );

            if (!is_array($local)) {
                $metrics['skipped_missing']++;
                continue;
            }
            $metrics['known_contracts']++;

            $isSac = ((int) ($local['executable_type'] ?? 0) === 1) || ((int) ($local['is_sac'] ?? 0) === 1);
            if ($onlySac && !$isSac) {
                $metrics['skipped_not_sac']++;
                continue;
            }

            $symbol = $this->normalizeSymbol($row['symbol'] ?? null);
            $currentAssetCode = $this->normalizeSymbol($local['asset_code'] ?? null);
            $currentAssetAddress = $this->normalizeContractId($local['asset_address'] ?? null);
            $newAssetAddress = $isSac && $currentAssetAddress === null ? $contractId : $currentAssetAddress;
            $shouldUpdateContract = $symbol !== null && ($currentAssetCode !== $symbol || $currentAssetAddress !== $newAssetAddress);

            $metadataRecord = [
                'display_name' => $this->normalizeNullableString($row['name'] ?? null, 255),
                'metadata_type' => $this->normalizeNullableString($row['type'] ?? null, 64),
                'is_sep41' => $this->normalizeNullableBool($row['sep41'] ?? null),
                'symbol' => $symbol,
                'decimals' => $this->normalizeNullableInt($row['decimals'] ?? null, 0, 255),
                'is_verified' => $this->normalizeNullableBool($row['verified'] ?? null),
                'website' => $this->normalizeNullableString($row['website'] ?? null, 255),
                'description' => $this->normalizeNullableString($row['description'] ?? null),
                'icon_url' => $this->normalizeNullableString($row['iconUrl'] ?? null, 255),
                'added_at' => $this->normalizeNullableDate($row['addedAt'] ?? null),
                'source_name' => 'verified_contracts_json',
            ];
            $existingMetadata = $this->connection->fetchAssociative(
                'SELECT display_name, metadata_type, is_sep41, symbol, decimals, is_verified, website, description, icon_url, added_at, source_name
                 FROM contract_verified_metadata
                 WHERE contract_id = :contract_id
                 LIMIT 1',
                ['contract_id' => (int) $local['id']],
                ['contract_id' => ParameterType::INTEGER]
            );
            $metadataChanged = $this->hasMetadataChanged($existingMetadata, $metadataRecord);

            if (!$shouldUpdateContract && !$metadataChanged) {
                $metrics['unchanged']++;
                continue;
            }

            if (!$dryRun) {
                if ($shouldUpdateContract) {
                    $this->connection->update(
                        'contracts',
                        [
                            'asset_code' => $symbol,
                            'asset_address' => $newAssetAddress,
                        ],
                        ['id' => (int) $local['id']],
                        ['id' => ParameterType::INTEGER]
                    );
                }

                if ($metadataChanged) {
                    $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
                    $this->connection->executeStatement(
                        $this->buildMetadataUpsertSql(),
                        [
                            'contract_id' => (int) $local['id'],
                            'display_name' => $metadataRecord['display_name'],
                            'metadata_type' => $metadataRecord['metadata_type'],
                            'is_sep41' => $metadataRecord['is_sep41'],
                            'symbol' => $metadataRecord['symbol'],
                            'decimals' => $metadataRecord['decimals'],
                            'is_verified' => $metadataRecord['is_verified'],
                            'website' => $metadataRecord['website'],
                            'description' => $metadataRecord['description'],
                            'icon_url' => $metadataRecord['icon_url'],
                            'added_at' => $metadataRecord['added_at'],
                            'source_name' => $metadataRecord['source_name'],
                            'created_at' => $now,
                            'updated_at' => $now,
                        ],
                        [
                            'contract_id' => ParameterType::INTEGER,
                            'is_sep41' => $metadataRecord['is_sep41'] !== null ? ParameterType::BOOLEAN : ParameterType::NULL,
                            'decimals' => $metadataRecord['decimals'] !== null ? ParameterType::INTEGER : ParameterType::NULL,
                            'is_verified' => $metadataRecord['is_verified'] !== null ? ParameterType::BOOLEAN : ParameterType::NULL,
                            'added_at' => $metadataRecord['added_at'] !== null ? ParameterType::STRING : ParameterType::NULL,
                        ]
                    );
                }
            }

            if ($shouldUpdateContract) {
                $metrics['contracts_updated']++;
            }
            if ($metadataChanged) {
                $metrics['metadata_upserted']++;
            }
        }

        $io->table(
            ['Metric', 'Value'],
            [
                ['network', $network],
                ['file', $file],
                ['input_rows', (string) $metrics['input_rows']],
                ['known_contracts', (string) $metrics['known_contracts']],
                ['contracts_updated', (string) $metrics['contracts_updated']],
                ['metadata_upserted', (string) $metrics['metadata_upserted']],
                ['unchanged', (string) $metrics['unchanged']],
                ['skipped_not_sac', (string) $metrics['skipped_not_sac']],
                ['skipped_missing', (string) $metrics['skipped_missing']],
                ['dry_run', (string) $metrics['dry_run']],
            ]
        );

        $io->success($dryRun ? 'Dry-run completed.' : 'Verified metadata import completed.');

        return Command::SUCCESS;
    }

    private function buildMetadataUpsertSql(): string
    {
        if ($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return 'INSERT INTO contract_verified_metadata (
                        contract_id, display_name, metadata_type, is_sep41, symbol, decimals, is_verified,
                        website, description, icon_url, added_at, source_name, created_at, updated_at
                     ) VALUES (
                        :contract_id, :display_name, :metadata_type, :is_sep41, :symbol, :decimals, :is_verified,
                        :website, :description, :icon_url, :added_at, :source_name, :created_at, :updated_at
                     )
                     ON CONFLICT (contract_id) DO UPDATE SET
                        display_name = EXCLUDED.display_name,
                        metadata_type = EXCLUDED.metadata_type,
                        is_sep41 = EXCLUDED.is_sep41,
                        symbol = EXCLUDED.symbol,
                        decimals = EXCLUDED.decimals,
                        is_verified = EXCLUDED.is_verified,
                        website = EXCLUDED.website,
                        description = EXCLUDED.description,
                        icon_url = EXCLUDED.icon_url,
                        added_at = EXCLUDED.added_at,
                        source_name = EXCLUDED.source_name,
                        updated_at = EXCLUDED.updated_at';
        }

        return 'INSERT INTO contract_verified_metadata (
                    contract_id, display_name, metadata_type, is_sep41, symbol, decimals, is_verified,
                    website, description, icon_url, added_at, source_name, created_at, updated_at
                 ) VALUES (
                    :contract_id, :display_name, :metadata_type, :is_sep41, :symbol, :decimals, :is_verified,
                    :website, :description, :icon_url, :added_at, :source_name, :created_at, :updated_at
                 )
                 ON DUPLICATE KEY UPDATE
                    display_name = VALUES(display_name),
                    metadata_type = VALUES(metadata_type),
                    is_sep41 = VALUES(is_sep41),
                    symbol = VALUES(symbol),
                    decimals = VALUES(decimals),
                    is_verified = VALUES(is_verified),
                    website = VALUES(website),
                    description = VALUES(description),
                    icon_url = VALUES(icon_url),
                    added_at = VALUES(added_at),
                    source_name = VALUES(source_name),
                    updated_at = VALUES(updated_at)';
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadContractsPayload(string $file): array
    {
        $raw = @file_get_contents($file);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $rows = $decoded['contracts'] ?? null;
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, static fn (mixed $row): bool => is_array($row)));
    }

    private function normalizeContractId(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $id = strtoupper(trim($value));
        if ($id === '' || str_starts_with($id, 'C') === false) {
            return null;
        }

        return $id;
    }

    private function normalizeSymbol(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $symbol = strtoupper(trim($value));
        if ($symbol === '' || strlen($symbol) > 12) {
            return null;
        }

        return $symbol;
    }

    private function normalizeNullableString(mixed $value, ?int $maxLength = null): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }
        if ($maxLength !== null && strlen($normalized) > $maxLength) {
            $normalized = substr($normalized, 0, $maxLength);
        }

        return $normalized;
    }

    private function normalizeNullableBool(mixed $value): ?int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_int($value)) {
            return $value > 0 ? 1 : 0;
        }
        if (!is_string($value)) {
            return null;
        }
        $normalized = strtolower(trim($value));
        if ($normalized === '1' || $normalized === 'true' || $normalized === 'yes') {
            return 1;
        }
        if ($normalized === '0' || $normalized === 'false' || $normalized === 'no') {
            return 0;
        }

        return null;
    }

    private function normalizeNullableInt(mixed $value, int $min, int $max): ?int
    {
        if (is_int($value)) {
            return max($min, min($max, $value));
        }
        if (!is_numeric($value)) {
            return null;
        }

        return max($min, min($max, (int) $value));
    }

    private function normalizeNullableDate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($normalized))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string,mixed>|false $existing
     * @param array<string,mixed> $incoming
     */
    private function hasMetadataChanged(array|false $existing, array $incoming): bool
    {
        if (!is_array($existing)) {
            return true;
        }

        $current = [
            'display_name' => $this->normalizeNullableString($existing['display_name'] ?? null, 255),
            'metadata_type' => $this->normalizeNullableString($existing['metadata_type'] ?? null, 64),
            'is_sep41' => $this->normalizeNullableBool($existing['is_sep41'] ?? null),
            'symbol' => $this->normalizeSymbol($existing['symbol'] ?? null),
            'decimals' => $this->normalizeNullableInt($existing['decimals'] ?? null, 0, 255),
            'is_verified' => $this->normalizeNullableBool($existing['is_verified'] ?? null),
            'website' => $this->normalizeNullableString($existing['website'] ?? null, 255),
            'description' => $this->normalizeNullableString($existing['description'] ?? null),
            'icon_url' => $this->normalizeNullableString($existing['icon_url'] ?? null, 255),
            'added_at' => $this->normalizeNullableDate($existing['added_at'] ?? null),
            'source_name' => $this->normalizeNullableString($existing['source_name'] ?? null, 64) ?? 'verified_contracts_json',
        ];

        foreach (['display_name', 'metadata_type', 'is_sep41', 'symbol', 'decimals', 'is_verified', 'website', 'description', 'icon_url', 'added_at', 'source_name'] as $field) {
            if (($current[$field] ?? null) !== ($incoming[$field] ?? null)) {
                return true;
            }
        }

        return false;
    }
}
