<?php

namespace App\Service\Stellar\Soroban;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class SorobanContractSourceService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'doctrine.dbal.contracts_connection')]
        private readonly Connection $connection,
    ) {
    }

    public function resolveOrDecompileContractSource(string $contractSourceId, string $wasmBytes, bool $forceRecompile = false): ?string
    {
        $wasmSha256 = hash('sha256', $wasmBytes);
        $cached = $this->loadCachedSource($contractSourceId);
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $hasStoredWasm = is_array($cached)
            && $this->nullableString($cached['wasm_blob_sha256'] ?? null) === $wasmSha256
            && $this->databaseBool($cached['has_wasm_blob'] ?? false);
        $cachedSourceCode = is_array($cached) ? $this->nullableString($cached['source_code'] ?? null) : null;

        if (
            !$forceRecompile
            && is_array($cached)
            && (int) ($cached['status'] ?? 0) === 1
            && $cachedSourceCode !== null
        ) {
            if (!$hasStoredWasm) {
                $this->upsertContractSource(
                    $contractSourceId,
                    $wasmBytes,
                    $wasmSha256,
                    $cachedSourceCode,
                    $this->nullableString($cached['source_code_sha256'] ?? null),
                    1,
                    null,
                    $this->nullableString($cached['decompiled_at'] ?? null),
                    $now,
                );
            }

            return $cachedSourceCode;
        }

        $sourceCode = $this->decompileWasmWithSorobanAuditor($wasmBytes);
        $sourceCode = is_string($sourceCode) && trim($sourceCode) !== '' ? $sourceCode : null;
        $sourceCodeSha256 = $sourceCode !== null ? hash('sha256', $sourceCode) : null;

        $this->upsertContractSource(
            $contractSourceId,
            $wasmBytes,
            $wasmSha256,
            $sourceCode,
            $sourceCodeSha256,
            $sourceCode !== null ? 1 : 2,
            $sourceCode !== null ? null : 'soroban-auditor returned empty output or non-zero exit',
            $sourceCode !== null ? $now : null,
            $now,
        );

        return $sourceCode;
    }

    /**
     * @return array<string,mixed>|false
     */
    private function loadCachedSource(string $contractSourceId): array|false
    {
        return $this->connection->fetchAssociative(
            'SELECT
                wasm_id,
                source_code,
                source_code_sha256,
                wasm_blob_sha256,
                wasm_blob IS NOT NULL AS has_wasm_blob,
                status,
                error_message,
                decompiled_at
             FROM contract_sources
             WHERE wasm_id = :wasm_id
             LIMIT 1',
            ['wasm_id' => $contractSourceId],
        );
    }

    private function upsertContractSource(
        string $wasmId,
        string $wasmBytes,
        string $wasmSha256,
        ?string $sourceCode,
        ?string $sourceCodeSha256,
        int $status,
        ?string $errorMessage,
        ?string $decompiledAt,
        string $updatedAt,
    ): void {
        $this->connection->executeStatement(
            'INSERT INTO contract_sources (
                wasm_id,
                source_code,
                source_code_sha256,
                wasm_blob,
                wasm_blob_sha256,
                status,
                error_message,
                decompiled_at,
                created_at,
                updated_at
             ) VALUES (
                :wasm_id,
                :source_code,
                :source_code_sha256,
                :wasm_blob,
                :wasm_blob_sha256,
                :status,
                :error_message,
                :decompiled_at,
                :created_at,
                :updated_at
             )
             ON CONFLICT (wasm_id) DO UPDATE SET
                source_code = EXCLUDED.source_code,
                source_code_sha256 = EXCLUDED.source_code_sha256,
                wasm_blob = EXCLUDED.wasm_blob,
                wasm_blob_sha256 = EXCLUDED.wasm_blob_sha256,
                status = EXCLUDED.status,
                error_message = EXCLUDED.error_message,
                decompiled_at = EXCLUDED.decompiled_at,
                updated_at = EXCLUDED.updated_at',
            [
                'wasm_id' => $wasmId,
                'source_code' => $sourceCode,
                'source_code_sha256' => $sourceCodeSha256,
                'wasm_blob' => $wasmBytes,
                'wasm_blob_sha256' => $wasmSha256,
                'status' => $status,
                'error_message' => $errorMessage,
                'decompiled_at' => $decompiledAt,
                'created_at' => $updatedAt,
                'updated_at' => $updatedAt,
            ],
            [
                'source_code' => $sourceCode !== null ? ParameterType::STRING : ParameterType::NULL,
                'source_code_sha256' => $sourceCodeSha256 !== null ? ParameterType::STRING : ParameterType::NULL,
                'wasm_blob' => ParameterType::BINARY,
                'status' => ParameterType::INTEGER,
                'error_message' => $errorMessage !== null ? ParameterType::STRING : ParameterType::NULL,
                'decompiled_at' => $decompiledAt !== null ? ParameterType::STRING : ParameterType::NULL,
            ],
        );
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

    private function decompileWasmWithSorobanAuditor(string $wasmBytes): ?string
    {
        $projectRoot = dirname(__DIR__, 4);
        $auditorBinary = $this->resolveSorobanAuditorBinary($projectRoot);

        if ($auditorBinary === null) {
            return null;
        }

        $tmpDir = rtrim((string) (getenv('SOROBAN_AUDITOR_TMP_DIR') ?: sys_get_temp_dir()), DIRECTORY_SEPARATOR);
        $tmpWasmPath = tempnam($tmpDir, 'soroban_wasm_');
        $tmpRsPath = tempnam($tmpDir, 'soroban_rs_');
        if (!is_string($tmpWasmPath) || $tmpWasmPath === '' || !is_string($tmpRsPath) || $tmpRsPath === '') {
            return null;
        }

        // Ensure .rs extension for tools/editors and deterministic behavior.
        $tmpRsPathWithExt = $tmpRsPath . '.rs';
        @rename($tmpRsPath, $tmpRsPathWithExt);
        $tmpRsPath = $tmpRsPathWithExt;

        if (@file_put_contents($tmpWasmPath, $wasmBytes) === false) {
            @unlink($tmpWasmPath);
            @unlink($tmpRsPath);
            return null;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $command = sprintf(
            '%s %s %s',
            escapeshellcmd($auditorBinary),
            escapeshellarg($tmpWasmPath),
            escapeshellarg($tmpRsPath)
        );

        try {
            $process = proc_open($command, $descriptors, $pipes);
            if (!is_resource($process)) {
                @unlink($tmpWasmPath);
                @unlink($tmpRsPath);
                return null;
            }

            $stdinWriteResult = fwrite($pipes[0], '');
            fclose($pipes[0]);

            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            $exitCode = proc_close($process);
            $stdout = is_string($stdout) ? $stdout : '';
            $stderr = is_string($stderr) ? $stderr : '';
            $sourceCode = @file_get_contents($tmpRsPath);
            $sourceCode = is_string($sourceCode) ? $sourceCode : '';

            @unlink($tmpWasmPath);
            @unlink($tmpRsPath);

            if ($stdinWriteResult === false || $exitCode !== 0 || $sourceCode === '') {
                $this->logger->warning('soroban-auditor failed to decompile contract WASM.', [
                    'stdinWriteResult' => $stdinWriteResult,
                    'exitCode' => $exitCode,
                    'stdout' => $stdout !== '' ? $stdout : null,
                    'stderr' => $stderr !== '' ? $stderr : null,
                ]);
                return null;
            }

            return $sourceCode;
        } catch (\Throwable $exception) {
            @unlink($tmpWasmPath);
            @unlink($tmpRsPath);
            $this->logger->warning('soroban-auditor failed with exception while decompiling contract WASM.', [
                'message' => $exception->getMessage(),
            ]);
            return null;
        }
    }

    private function resolveSorobanAuditorBinary(string $projectRoot): ?string
    {
        $envOverride = trim((string) (getenv('SOROBAN_AUDITOR_BINARY') ?: ''));
        if ($envOverride !== '' && is_file($envOverride) && is_executable($envOverride)) {
            return $envOverride;
        }

        $os = strtolower((string) php_uname('s'));
        $candidates = match (true) {
            str_contains($os, 'darwin') => [
                $projectRoot . '/bin/soroban-auditor-macos',
                $projectRoot . '/bin/soroban-auditor',
                $projectRoot . '/bin/soroban-auditor-debian13',
            ],
            default => [
                $projectRoot . '/bin/soroban-auditor',
                $projectRoot . '/bin/soroban-auditor-debian13',
                $projectRoot . '/bin/soroban-auditor-macos',
            ],
        };

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
