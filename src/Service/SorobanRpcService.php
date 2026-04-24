<?php

namespace App\Service;

use App\Service\Stellar\Soroban\SorobanContractInspector;
use App\Service\Stellar\Soroban\SorobanContractSourceService;
use App\Service\Stellar\Soroban\SorobanRpcDataService;
use App\Service\Stellar\Soroban\SorobanServerFactory;
use Psr\Log\LoggerInterface;
use Soneso\StellarSDK\Xdr\XdrContractCodeEntry;
use Soneso\StellarSDK\Crypto\StrKey;
use Soneso\StellarSDK\Xdr\XdrContractExecutableType;

final class SorobanRpcService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly SorobanServerFactory $sorobanServerFactory,
        private readonly SorobanContractInspector $sorobanContractInspector,
        private readonly SorobanContractSourceService $sorobanContractSourceService,
        private readonly SorobanRpcDataService $sorobanRpcDataService,
    ) {
    }

    /**
     * @return array{
     *     contractId:string,
     *     contractIdHex:string,
     *     executableType?:int|null,
     *     contractKind?:string|null,
     *     isSac?:bool,
     *     wasmId?:string|null,
     *     wasmCodeBase64?:string|null,
     *     wasmSha256?:string|null,
     *     contractSourceCode?:string|null
     * }|null
     */
    public function getContractWasmByContractId(
        string $contractId,
        ?string $network = null,
        bool $includeWasmCodeBase64 = true,
        bool $forceRecompile = false,
    ): ?array {
        try {
            return $this->buildContractWasmPayload($contractId, $network, $includeWasmCodeBase64, $forceRecompile);
        } catch (\Throwable $error) {
            $this->logger->error('Error loading contract wasm from Soroban.', [
                'contractId' => $contractId,
                'network' => $network,
                'exception' => $error,
            ]);

            return null;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getContractRpcDataByContractId(
        string $contractId,
        ?string $network = null,
        bool $includeRawXdr = false,
    ): ?array {
        $normalizedContractId = $this->sorobanContractInspector->normalizeContractId($contractId);
        if ($normalizedContractId === null) {
            return null;
        }

        $instanceLedgerKeyBase64 = $this->sorobanContractInspector->buildContractInstanceLedgerKeyBase64($normalizedContractId);

        return $this->sorobanRpcDataService->fetchContractRpcData(
            $normalizedContractId,
            $network,
            $instanceLedgerKeyBase64,
            $includeRawXdr,
        );
    }

    /**
     * @return array{
     *     ok:bool,
     *     contractId?:string,
     *     latestLedger?:int,
     *     startLedger?:int,
     *     events?:array<int,array<string,mixed>>,
     *     storageEntries?:array<int,array<string,mixed>>,
     *     transactions?:array<int,array<string,mixed>>,
     *     error?:string
     * }
     */
    public function collectContractTransactions(
        string $contractId,
        ?string $network = null,
        ?int $startLedger = null,
        ?int $endLedger = null,
        ?callable $onProgress = null,
        ?callable $onChunk = null,
    ): array {
        $normalizedContractId = $this->sorobanContractInspector->normalizeContractId($contractId);
        if ($normalizedContractId === null) {
            return ['ok' => false, 'error' => 'Invalid contract id.'];
        }

        return $this->sorobanRpcDataService->collectContractTransactions(
            $normalizedContractId,
            $network,
            $startLedger,
            $endLedger,
            $onProgress,
            $onChunk,
        );
    }

    /**
     * @return array{
     *     contractId:string,
     *     contractIdHex:string,
     *     executableType:?int,
     *     contractKind:string,
     *     isSac:bool,
     *     wasmId:?string,
     *     contractCodeEntry:?XdrContractCodeEntry
     * }|null
     */
    private function loadContractWasmContext(string $contractId, ?string $network): ?array
    {
        $normalizedContractId = $this->sorobanContractInspector->normalizeContractId($contractId);
        if ($normalizedContractId === null) {
            return null;
        }

        $server = $this->sorobanServerFactory->create($network);
        $executableMeta = $this->sorobanContractInspector->loadContractExecutableMetaForContractId($server, $normalizedContractId);
        $wasmId = $executableMeta['wasmId'];
        $executableType = $executableMeta['executableType'];
        $isSac = $executableType === XdrContractExecutableType::CONTRACT_EXECUTABLE_STELLAR_ASSET;
        $contractCodeEntry = (!$isSac && is_string($wasmId) && $wasmId !== '')
            ? $server->loadContractCodeForWasmId($wasmId)
            : null;

        return [
            'contractId' => $normalizedContractId,
            'contractIdHex' => StrKey::decodeContractIdHex($normalizedContractId),
            'executableType' => $executableType,
            'contractKind' => $isSac ? 'sac' : 'wasm',
            'isSac' => $isSac,
            'wasmId' => $wasmId,
            'contractCodeEntry' => $contractCodeEntry,
        ];
    }

    /**
     * @return array{
     *     contractId:string,
     *     contractIdHex:string,
     *     executableType:?int,
     *     contractKind:string,
     *     isSac:bool,
     *     wasmId:?string,
     *     wasmCodeBase64:?string,
     *     wasmSha256:?string,
     *     contractSourceCode:?string
     * }|null
     */
    private function buildContractWasmPayload(
        string $contractId,
        ?string $network,
        bool $includeWasmCodeBase64,
        bool $forceRecompile = false,
    ): ?array {
        $loaded = $this->loadContractWasmContext($contractId, $network);
        if ($loaded === null) {
            return null;
        }

        $payload = [
            'contractId' => $loaded['contractId'],
            'contractIdHex' => $loaded['contractIdHex'],
            'executableType' => $loaded['executableType'],
            'contractKind' => $loaded['contractKind'],
            'isSac' => $loaded['isSac'],
            'wasmId' => $loaded['wasmId'],
            'wasmCodeBase64' => null,
            'wasmSha256' => null,
            'contractSourceCode' => null,
        ];

        $contractCodeEntry = $loaded['contractCodeEntry'];
        if ($contractCodeEntry === null) {
            return $payload;
        }

        $wasmBytes = $contractCodeEntry->code->value;
        $wasmSha256 = hash('sha256', $wasmBytes);
        $contractSourceId = $loaded['wasmId'] ?? $wasmSha256;
        $payload['wasmSha256'] = $wasmSha256;
        $payload['contractSourceCode'] = $this->sorobanContractSourceService
            ->resolveOrDecompileContractSource($contractSourceId, $wasmBytes, $forceRecompile);

        if ($includeWasmCodeBase64) {
            $payload['wasmCodeBase64'] = base64_encode($wasmBytes);
        }

        return $payload;
    }

}
