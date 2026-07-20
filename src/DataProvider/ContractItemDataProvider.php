<?php

declare(strict_types=1);

namespace App\DataProvider;

use ApiPlatform\DependencyInjection\Attribute\AsTaggedItem;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Service\ContractTransparency\ContractVisibilitySql;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

#[AsTaggedItem('api_platform.state.provider')]
final class ContractItemDataProvider implements ProviderInterface
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.contracts_connection')]
        private readonly Connection $connection,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $contractId = strtoupper(trim((string) ($uriVariables['contractId'] ?? $uriVariables['id'] ?? '')));
        if ($contractId === '') {
            return null;
        }

        $request = $this->requestStack->getCurrentRequest();
        $filters = is_array($context['filters'] ?? null) ? $context['filters'] : [];
        $network = $this->stellarNetworkResolver->normalizeNetwork(
            is_string($request?->query->get('network')) ? $request?->query->get('network') : (is_string($filters['network'] ?? null) ? $filters['network'] : null),
            'mainnet'
        );
        $networkCode = $this->stellarNetworkResolver->resolveNetworkCode($network);
        if ($networkCode === null) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT
                c.*,
                cvm.display_name AS verified_display_name,
                cvm.metadata_type AS verified_metadata_type,
                cvm.is_sep41 AS verified_is_sep41,
                cvm.symbol AS verified_symbol,
                cvm.decimals AS verified_decimals,
                cvm.is_verified AS verified_metadata_is_verified,
                cvm.website AS verified_website,
                cvm.description AS verified_description,
                cvm.icon_url AS verified_icon_url,
                cvm.added_at AS verified_added_at,
                cvm.raw_payload AS verified_raw_payload,
                cvm.source_name AS verified_source_name,
                cs.source_code,
                cs.source_code_sha256,
                cs.wasm_blob_sha256,
                cs.status AS source_status,
                cs.error_message AS source_error_message,
                cs.decompiled_at AS source_decompiled_at
             FROM contracts c
             LEFT JOIN contract_verified_metadata cvm ON cvm.contract_id = c.id
             LEFT JOIN contract_sources cs ON cs.wasm_id = c.wasm_id
             WHERE c.contract_id = :contract_id
               AND c.network = :network
               AND '.ContractVisibilitySql::confirmedPredicate('c').'
             LIMIT 1',
            [
                'contract_id' => $contractId,
                'network' => $networkCode,
            ],
            [
                'network' => ParameterType::INTEGER,
            ]
        );

        if (!is_array($row)) {
            return null;
        }

        return $this->toResponseObject($this->formatContractRow($row, $network));
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
        $sourceCode = $this->nullableString($row['source_code'] ?? null);
        $sourceError = $this->nullableString($row['source_error_message'] ?? null);
        $sourceCodeAvailable = $sourceCodeVerified || $sourceCode !== null;
        $hasVerifiedMetadata =
            $this->nullableString($row['verified_display_name'] ?? null) !== null
            || $this->nullableString($row['verified_metadata_type'] ?? null) !== null
            || $row['verified_is_sep41'] !== null
            || $this->nullableString($row['verified_symbol'] ?? null) !== null
            || $row['verified_decimals'] !== null
            || $row['verified_metadata_is_verified'] !== null
            || $this->nullableString($row['verified_website'] ?? null) !== null
            || $this->nullableString($row['verified_description'] ?? null) !== null
            || $this->nullableString($row['verified_icon_url'] ?? null) !== null
            || $row['verified_added_at'] !== null
            || $row['verified_raw_payload'] !== null
            || $this->nullableString($row['verified_source_name'] ?? null) !== null;

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
            'verifiedMetadata' => $hasVerifiedMetadata ? [
                'displayName' => $this->nullableString($row['verified_display_name'] ?? null),
                'metadataType' => $this->nullableString($row['verified_metadata_type'] ?? null),
                'isSep41' => $row['verified_is_sep41'] !== null ? $this->databaseBool($row['verified_is_sep41']) : null,
                'symbol' => $this->nullableString($row['verified_symbol'] ?? null),
                'decimals' => isset($row['verified_decimals']) ? (int) $row['verified_decimals'] : null,
                'isVerified' => $row['verified_metadata_is_verified'] !== null ? $this->databaseBool($row['verified_metadata_is_verified']) : null,
                'website' => $this->nullableString($row['verified_website'] ?? null),
                'description' => $this->nullableString($row['verified_description'] ?? null),
                'iconUrl' => $this->nullableString($row['verified_icon_url'] ?? null),
                'addedAt' => $this->toDate($row['verified_added_at'] ?? null),
                'sourceName' => $this->nullableString($row['verified_source_name'] ?? null),
                'rawPayload' => $this->decodeJsonValue($row['verified_raw_payload'] ?? null),
            ] : null,
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
            'sourceCode' => $sourceCode,
            'verificationStatus' => match (true) {
                $sep55Verified => 'verified',
                $sourceCodeAvailable => 'source_available',
                $sep55Error !== null || $sourceError !== null => 'failed',
                $this->nullableString($row['wasm_id'] ?? null) !== null => 'unverified',
                default => 'unknown',
            },
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value !== '' ? $value : null;
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

    private function toDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function decodeJsonValue(mixed $value): mixed
    {
        if ($value === null || is_array($value)) {
            return $this->normalizeResponseValue($value);
        }
        if (!is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $value;
        }

        return $this->normalizeResponseValue($decoded);
    }

    /**
     * API Platform's JSON-LD normalizer treats nested arrays returned from an item
     * provider as collections. Convert associative maps to objects while keeping
     * real lists as arrays.
     *
     * @param array<string,mixed> $data
     */
    private function toResponseObject(array $data): object
    {
        $value = $this->normalizeResponseValue($data);

        return is_object($value) ? $value : (object) $data;
    }

    private function normalizeResponseValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalizeResponseValue($item), $value);
        }

        $object = new \stdClass();
        foreach ($value as $key => $child) {
            $object->{(string) $key} = $this->normalizeResponseValue($child);
        }

        return $object;
    }
}
