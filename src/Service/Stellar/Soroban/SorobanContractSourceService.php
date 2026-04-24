<?php

namespace App\Service\Stellar\Soroban;

use App\Entity\ContractSource;
use App\Repository\ContractSourceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class SorobanContractSourceService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ContractSourceRepository $contractSourceRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function resolveOrDecompileContractSource(string $contractSourceId, string $wasmBytes, bool $forceRecompile = false): ?string
    {
        $wasmSha256 = hash('sha256', $wasmBytes);
        $cached = $this->contractSourceRepository->findOneByWasmId($contractSourceId);
        $now = new \DateTimeImmutable();
        $record = $cached ?? (new ContractSource())->setWasmId($contractSourceId)->setCreatedAt($now);
        $hasStoredWasm = $record->getWasmBlobSha256() === $wasmSha256 && $record->getWasmBlob() !== null;
        if (!$hasStoredWasm) {
            $record
                ->setWasmBlob($wasmBytes)
                ->setWasmBlobSha256($wasmSha256)
                ->setUpdatedAt($now);
        }

        $cachedSourceCode = is_string($cached?->getSourceCode()) ? trim((string) $cached?->getSourceCode()) : '';
        if (
            !$forceRecompile
            &&
            $cached instanceof ContractSource
            && $cached->getStatus() === 1
            && $cachedSourceCode !== ''
        ) {
            if (!$hasStoredWasm) {
                $this->entityManager->persist($record);
                $this->entityManager->flush();
            }
            return $cached->getSourceCode();
        }

        $sourceCode = $this->decompileWasmWithSorobanAuditor($wasmBytes);
        $hasSourceCode = is_string($sourceCode) && trim($sourceCode) !== '';

        $record
            ->setSourceCode($hasSourceCode ? $sourceCode : null)
            ->setSourceCodeSha256($hasSourceCode ? hash('sha256', (string) $sourceCode) : null)
            ->setStatus($hasSourceCode ? 1 : 2)
            ->setErrorMessage($hasSourceCode ? null : 'soroban-auditor returned empty output or non-zero exit')
            ->setDecompiledAt($hasSourceCode ? $now : null)
            ->setUpdatedAt($now);

        $this->entityManager->persist($record);
        $this->entityManager->flush();

        return $hasSourceCode ? $sourceCode : null;
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
