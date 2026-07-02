<?php

declare(strict_types=1);

namespace App\DataProvider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

final class ContractCollectionDataProvider implements ProviderInterface
{
    private const DEFAULT_ITEMS_PER_PAGE = 30;
    private const MAX_ITEMS_PER_PAGE = 200;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.contracts_connection')]
        private readonly Connection $connection,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $request = $this->requestStack->getCurrentRequest();
        $queryParams = $request?->query->all() ?? [];
        $filters = is_array($context['filters'] ?? null) ? $context['filters'] : [];
        $network = $this->stellarNetworkResolver->normalizeNetwork(
            is_string($request?->query->get('network')) ? $request?->query->get('network') : (is_string($filters['network'] ?? null) ? $filters['network'] : null),
            'mainnet'
        );
        $networkCode = $this->stellarNetworkResolver->resolveNetworkCode($network);
        if ($networkCode === null) {
            return [];
        }

        $page = max(1, (int) ($request?->query->get('page', $filters['page'] ?? 1) ?? 1));
        $itemsPerPage = (int) ($request?->query->get('itemsPerPage', $filters['itemsPerPage'] ?? self::DEFAULT_ITEMS_PER_PAGE) ?? self::DEFAULT_ITEMS_PER_PAGE);
        $itemsPerPage = max(1, min(self::MAX_ITEMS_PER_PAGE, $itemsPerPage));
        $offset = ($page - 1) * $itemsPerPage;

        $where = ['c.network = :network'];
        $params = [
            'network' => $networkCode,
            'limit_rows' => $itemsPerPage,
            'offset_rows' => $offset,
        ];
        $types = [
            'network' => ParameterType::INTEGER,
            'limit_rows' => ParameterType::INTEGER,
            'offset_rows' => ParameterType::INTEGER,
        ];

        $contractId = $this->nullableString($request?->query->get('contract_id', $filters['contract_id'] ?? null));
        if ($contractId !== null) {
            $where[] = 'c.contract_id = :contract_id';
            $params['contract_id'] = strtoupper($contractId);
        }

        $contractIds = $this->normalizeContractIds($queryParams['contractIds'] ?? ($filters['contractIds'] ?? $filters['contract_ids'] ?? null));
        if ($contractIds !== []) {
            $where[] = 'c.contract_id IN (:contract_ids)';
            $params['contract_ids'] = $contractIds;
            $types['contract_ids'] = ArrayParameterType::STRING;
        }

        $assetCode = $this->nullableString($request?->query->get('asset_code', $filters['asset_code'] ?? $filters['assetCode'] ?? null));
        if ($assetCode !== null) {
            $where[] = 'c.asset_code = :asset_code';
            $params['asset_code'] = strtoupper($assetCode);
        }

        $sac = $this->parseNullableBool($request?->query->get('sac', $filters['sac'] ?? null));
        if ($sac !== null) {
            $where[] = 'c.is_sac = :is_sac';
            $params['is_sac'] = $sac;
            $types['is_sac'] = ParameterType::BOOLEAN;
        }

        $sourceVerified = $this->parseNullableBool($request?->query->get('sourceCodeVerified', $filters['sourceCodeVerified'] ?? $filters['source_code_verified'] ?? null));
        if ($sourceVerified !== null) {
            $where[] = 'c.source_code_verified = :source_code_verified';
            $params['source_code_verified'] = $sourceVerified;
            $types['source_code_verified'] = ParameterType::BOOLEAN;
        }

        $search = $this->nullableString($request?->query->get('search', $request?->query->get('q', $filters['search'] ?? $filters['q'] ?? null)));
        if ($search !== null) {
            $where[] = '(c.contract_id ILIKE :search OR c.asset_code ILIKE :search OR c.asset_issuer ILIKE :search OR cvm.display_name ILIKE :search OR cvm.symbol ILIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT
                    c.*,
                    cvm.display_name AS verified_display_name,
                    cvm.symbol AS verified_symbol,
                    cvm.is_verified AS verified_metadata_is_verified,
                    cs.source_code_sha256,
                    cs.wasm_blob_sha256,
                    cs.status AS source_status,
                    cs.error_message AS source_error_message,
                    cs.decompiled_at AS source_decompiled_at
                 FROM contracts c
                 LEFT JOIN contract_verified_metadata cvm ON cvm.contract_id = c.id
                 LEFT JOIN contract_sources cs ON cs.wasm_id = c.wasm_id
                 WHERE %s
                 ORDER BY %s
                 LIMIT :limit_rows OFFSET :offset_rows',
                implode(' AND ', $where),
                $this->resolveOrderSql($queryParams['order'] ?? ($filters['order'] ?? null))
            ),
            $params,
            $types
        );

        return array_map(fn (array $row): array => $this->formatContractRow($row, $network), $rows);
    }

    private function resolveOrderSql(mixed $order): string
    {
        $order = is_array($order) ? $order : [];
        foreach ([
            'totalTransactions' => 'c.total_transactions',
            'total_transactions' => 'c.total_transactions',
            'totalInvokes' => 'c.total_invokes',
            'total_invokes' => 'c.total_invokes',
            'asset_code' => 'c.asset_code',
            'createdAt' => 'c.created_at',
            'created_at' => 'c.created_at',
        ] as $input => $column) {
            $direction = $this->normalizeDirection($order[$input] ?? null);
            if ($direction !== null) {
                return sprintf('%s %s NULLS LAST, c.id DESC', $column, $direction);
            }
        }

        return 'c.id DESC';
    }

    private function normalizeDirection(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            'asc' => 'ASC',
            'desc' => 'DESC',
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private function normalizeContractIds(mixed $value): array
    {
        if (is_string($value)) {
            $value = str_contains($value, ',') ? explode(',', $value) : [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $candidate = strtoupper(trim($candidate));
            if ($candidate !== '') {
                $ids[] = $candidate;
            }
        }

        return array_values(array_unique(array_slice($ids, 0, 15)));
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function formatContractRow(array $row, string $network): array
    {
        $sep55Verified = $this->databaseBool($row['sep55_verified'] ?? false);
        $sourceCodeVerified = $this->databaseBool($row['source_code_verified'] ?? false);
        $sep55Error = $this->nullableString($row['sep55_error'] ?? null);
        $sourceError = $this->nullableString($row['source_error_message'] ?? null);
        $sourceCodeAvailable = $sourceCodeVerified || $this->nullableString($row['source_code_sha256'] ?? null) !== null;

        return [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'contractId' => $this->nullableString($row['contract_id'] ?? null),
            'contractIdHex' => $this->nullableString($row['contract_id_hex'] ?? null),
            'network' => $network,
            'assetCode' => $this->nullableString($row['asset_code'] ?? null),
            'assetAddress' => $this->nullableString($row['asset_address'] ?? null),
            'assetIssuer' => $this->nullableString($row['asset_issuer'] ?? null),
            'createdAt' => $this->toAtom($row['created_at'] ?? null),
            'deployedAt' => $this->toAtom($row['deployed_at'] ?? null),
            'deployedLedger' => isset($row['deployed_ledger']) ? (int) $row['deployed_ledger'] : null,
            'deployTxHash' => $this->nullableString($row['deploy_tx_hash'] ?? null),
            'deploySourceAccount' => $this->nullableString($row['deploy_source_account'] ?? null),
            'deploymentKind' => $this->nullableString($row['deployment_kind'] ?? null),
            'sourceCodeVerified' => $sourceCodeVerified,
            'sep55Verified' => $sep55Verified,
            'githubAddress' => $this->nullableString($row['github_address'] ?? null),
            'sep55CommitHash' => $this->nullableString($row['sep55_commit_hash'] ?? null),
            'sep55AttestationUrl' => $this->nullableString($row['sep55_attestation_url'] ?? null),
            'sep55Error' => $sep55Error,
            'sep55LastCheckedAt' => $this->toAtom($row['sep55_last_checked_at'] ?? null),
            'contractType' => isset($row['contract_type']) ? (int) $row['contract_type'] : null,
            'wasmId' => $this->nullableString($row['wasm_id'] ?? null),
            'wasmHash' => $this->nullableString($row['wasm_id'] ?? null) ?? $this->nullableString($row['wasm_blob_sha256'] ?? null),
            'executableType' => isset($row['executable_type']) ? (int) $row['executable_type'] : null,
            'isSac' => $this->databaseBool($row['is_sac'] ?? false),
            'totalTransactions' => isset($row['total_transactions']) ? (int) $row['total_transactions'] : 0,
            'totalOperations' => isset($row['total_operations']) ? (int) $row['total_operations'] : 0,
            'totalEvents' => isset($row['total_events']) ? (int) $row['total_events'] : 0,
            'totalEffects' => isset($row['total_effects']) ? (int) $row['total_effects'] : 0,
            'totalStorageEntries' => isset($row['total_storage_entries']) ? (int) $row['total_storage_entries'] : 0,
            'totalInvokes' => isset($row['total_invokes']) ? (int) $row['total_invokes'] : 0,
            'totalInvokeTransactions' => isset($row['total_invokes']) ? (int) $row['total_invokes'] : 0,
            'verifiedMetadata' => [
                'displayName' => $this->nullableString($row['verified_display_name'] ?? null),
                'symbol' => $this->nullableString($row['verified_symbol'] ?? null),
                'isVerified' => $row['verified_metadata_is_verified'] !== null ? $this->databaseBool($row['verified_metadata_is_verified']) : null,
            ],
            'source' => [
                'type' => $sep55Verified ? 'sep55' : ($sourceCodeVerified ? 'decompiled' : null),
                'githubAddress' => $this->nullableString($row['github_address'] ?? null),
                'commitHash' => $this->nullableString($row['sep55_commit_hash'] ?? null),
                'attestationUrl' => $this->nullableString($row['sep55_attestation_url'] ?? null),
                'sourceCodeSha256' => $this->nullableString($row['source_code_sha256'] ?? null),
                'wasmBlobSha256' => $this->nullableString($row['wasm_blob_sha256'] ?? null),
                'status' => isset($row['source_status']) ? (int) $row['source_status'] : null,
                'decompiledAt' => $this->toAtom($row['source_decompiled_at'] ?? null),
                'lastCheckedAt' => $this->toAtom($row['sep55_last_checked_at'] ?? null),
                'sourceCodeAvailable' => $sourceCodeAvailable,
                'error' => $sep55Error ?? $sourceError,
            ],
            'sourceMetadata' => [
                'type' => $sep55Verified ? 'sep55' : ($sourceCodeVerified ? 'decompiled' : null),
                'githubAddress' => $this->nullableString($row['github_address'] ?? null),
                'commitHash' => $this->nullableString($row['sep55_commit_hash'] ?? null),
                'attestationUrl' => $this->nullableString($row['sep55_attestation_url'] ?? null),
                'sourceCodeSha256' => $this->nullableString($row['source_code_sha256'] ?? null),
                'lastCheckedAt' => $this->toAtom($row['sep55_last_checked_at'] ?? null),
                'sourceCodeAvailable' => $sourceCodeAvailable,
                'error' => $sep55Error ?? $sourceError,
            ],
            'verificationStatus' => match (true) {
                $sep55Verified => 'verified',
                $sourceCodeAvailable => 'source_available',
                $sep55Error !== null || $sourceError !== null => 'failed',
                $this->nullableString($row['wasm_id'] ?? null) !== null => 'unverified',
                default => 'unknown',
            },
        ];
    }

    private function parseNullableBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => null,
        };
    }

    private function databaseBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                '1', 't', 'true', 'yes', 'on' => true,
                default => false,
            };
        }

        return false;
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function toAtom(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format(\DateTimeInterface::ATOM);
        } catch (\Throwable) {
            return null;
        }
    }
}
