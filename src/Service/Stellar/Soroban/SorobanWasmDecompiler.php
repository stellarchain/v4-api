<?php

declare(strict_types=1);

namespace App\Service\Stellar\Soroban;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

final class SorobanWasmDecompiler
{
    private const MAX_OUTPUT_BYTES = 2 * 1024 * 1024;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function decompile(string $wasmBytes): string
    {
        if (strlen($wasmBytes) > 2 * 1024 * 1024 || !str_starts_with($wasmBytes, "\0asm\x01\0\0\0")) {
            throw new \InvalidArgumentException('Invalid WASM module.');
        }
        $directory = rtrim((string) (getenv('SOROBAN_AUDITOR_TMP_DIR') ?: sys_get_temp_dir()), DIRECTORY_SEPARATOR);
        $input = tempnam($directory, 'soroban_wasm_');
        $output = tempnam($directory, 'soroban_source_');
        try {
            if ($input === false || $output === false || file_put_contents($input, $wasmBytes) !== strlen($wasmBytes)) {
                throw new \RuntimeException('Cannot create decompiler input.');
            }
            $process = new Process([$this->binary(), $input, $output], timeout: 20);
            $process->disableOutput();
            $process->start();
            while ($process->isRunning()) {
                $process->checkTimeout();
                clearstatcache(true, $output);
                if (filesize($output) > self::MAX_OUTPUT_BYTES) {
                    $process->stop(0);
                    throw new \RuntimeException('Decompiled output exceeds the size limit.');
                }
                usleep(20_000);
            }
            clearstatcache(true, $output);
            if (!$process->isSuccessful() || filesize($output) > self::MAX_OUTPUT_BYTES) {
                throw new \RuntimeException('Soroban Auditor could not decompile this module.');
            }
            $source = file_get_contents($output);
            if ($source === false || trim($source) === '' || !mb_check_encoding($source, 'UTF-8')) {
                throw new \RuntimeException('Soroban Auditor returned no valid source code.');
            }

            return $source;
        } finally {
            if (isset($process) && $process->isRunning()) {
                $process->stop(0);
            }
            if (is_string($input)) {
                @unlink($input);
            }
            if (is_string($output)) {
                @unlink($output);
            }
        }
    }

    private function binary(): string
    {
        $override = trim((string) (getenv('SOROBAN_AUDITOR_BINARY') ?: ''));
        if ($override !== '') {
            $candidates = [$override];
        } else {
            $platform = PHP_OS_FAMILY === 'Darwin' ? 'macos' : 'debian13';
            $candidates = [$this->projectDir.'/bin/soroban-auditor', $this->projectDir.'/bin/soroban-auditor-'.$platform];
        }
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Soroban Auditor is not installed or executable.');
    }
}
