<?php

declare(strict_types=1);

namespace App\Service\ContractRead;

use App\Service\Stellar\Soroban\SorobanWasmDecompiler;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class ContractDecompilationService
{
    public function __construct(
        private readonly ContractReadGateway $gateway,
        private readonly SorobanWasmDecompiler $decompiler,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.cache_dir%')]
        private readonly string $cacheDir,
    ) {
    }

    public function decompile(string $contractId, string $network, string $expectedHash): array
    {
        $wasm = $this->gateway->wasm($contractId, $network);
        if ($wasm === null) {
            throw new NotFoundHttpException('No WASM executable is available. Stellar Asset Contracts have no WASM to decompile.');
        }
        if (!hash_equals($expectedHash, $wasm['hash'])) {
            throw new ConflictHttpException('The contract executable changed. Reload the contract page.');
        }
        $item = $this->cache->getItem('contract_decompiled_v1_'.$wasm['hash']);
        if ($item->isHit()) {
            return $this->cachedResult($item->get(), $wasm['hash']);
        }
        // One on-demand process per API instance, including different WASM hashes.
        $lock = fopen($this->cacheDir.'/soroban-decompilation.lock', 'c');
        if ($lock === false) {
            throw new ServiceUnavailableHttpException(null, 'Decompilation is temporarily unavailable.');
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new TooManyRequestsHttpException(2, 'The decompiler is busy. Please retry shortly.');
            }
            $item = $this->cache->getItem($item->getKey());
            if ($item->isHit()) {
                return $this->cachedResult($item->get(), $wasm['hash']);
            }
            try {
                $source = $this->decompiler->decompile($wasm['binary']);
            } catch (\Throwable $exception) {
                $this->logger->warning('On-demand contract decompilation failed.', ['wasmHash' => $wasm['hash'], 'exception' => $exception]);
                $item->set(null)->expiresAfter(30);
                $this->cache->save($item);
                throw new ServiceUnavailableHttpException(30, 'Soroban Auditor could not decompile this module. Please retry later.', $exception);
            }
            $item->set($source)->expiresAfter(86400);
            $this->cache->save($item);

            return ['wasmHash' => $wasm['hash'], 'sourceCode' => $source, 'type' => 'decompiled', 'cached' => false];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function cachedResult(mixed $source, string $hash): array
    {
        if (!is_string($source) || $source === '') {
            throw new ServiceUnavailableHttpException(30, 'Decompilation recently failed. Please retry later.');
        }

        return ['wasmHash' => $hash, 'sourceCode' => $source, 'type' => 'decompiled', 'cached' => true];
    }
}
