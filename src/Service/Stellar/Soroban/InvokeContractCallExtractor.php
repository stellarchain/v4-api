<?php

namespace App\Service\Stellar\Soroban;

use Soneso\StellarSDK\Xdr\XdrHostFunctionType;
use Soneso\StellarSDK\Xdr\XdrLedgerEntryType;
use Soneso\StellarSDK\Xdr\XdrLedgerKey;
use Soneso\StellarSDK\Xdr\XdrOperation;
use Soneso\StellarSDK\Xdr\XdrOperationType;
use Soneso\StellarSDK\Xdr\XdrTransactionEnvelope;

final class InvokeContractCallExtractor
{
    public function __construct(
        private readonly SorobanScValMapper $sorobanScValMapper,
    ) {
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function fromEnvelopeXdr(?string $envelopeXdrBase64): array
    {
        $calls = $this->extractCallsWithContractId($envelopeXdrBase64);
        if ($calls === []) {
            return [];
        }

        return array_map(
            static function (array $call): array {
                unset($call['contractId']);
                return $call;
            },
            $calls
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function fromEnvelopeXdrForContract(?string $envelopeXdrBase64, string $contractId): array
    {
        $normalizedContractId = strtoupper(trim($contractId));
        if ($normalizedContractId === '') {
            return [];
        }

        $calls = $this->extractCallsWithContractId($envelopeXdrBase64);
        if ($calls === []) {
            return [];
        }

        $filtered = [];
        foreach ($calls as $call) {
            $callContractId = strtoupper((string) ($call['contractId'] ?? ''));
            if ($callContractId !== $normalizedContractId) {
                continue;
            }
            unset($call['contractId']);
            $filtered[] = $call;
        }

        return $filtered;
    }

    /**
     * @return list<string> Base64 XDR ledger keys (CONTRACT_DATA) for the given contract.
     */
    public function extractContractStorageLedgerKeysFromEnvelopeXdr(?string $envelopeXdrBase64, string $contractId): array
    {
        if (!is_string($envelopeXdrBase64) || $envelopeXdrBase64 === '') {
            return [];
        }

        $normalizedContractId = strtoupper(trim($contractId));
        if ($normalizedContractId === '') {
            return [];
        }

        try {
            $envelope = XdrTransactionEnvelope::fromEnvelopeBase64XdrString($envelopeXdrBase64);
        } catch (\Throwable) {
            return [];
        }

        $tx = $this->extractTransactionFromEnvelope($envelope);
        if ($tx === null) {
            return [];
        }

        $ext = $tx->getExt();
        if ($ext->getDiscriminant() !== 1 || $ext->sorobanTransactionData === null) {
            return [];
        }

        $footprint = $ext->sorobanTransactionData->getResources()->getFootprint();
        $keys = [];
        foreach (array_merge($footprint->getReadOnly(), $footprint->getReadWrite()) as $ledgerKey) {
            if (!$ledgerKey instanceof XdrLedgerKey) {
                continue;
            }
            if ($ledgerKey->getType()->getValue() !== XdrLedgerEntryType::CONTRACT_DATA) {
                continue;
            }

            $contractData = $ledgerKey->getContractData();
            if ($contractData === null) {
                continue;
            }

            $keyContractId = '';
            try {
                $keyContractId = strtoupper($contractData->getContract()->toStrKey());
            } catch (\Throwable) {
                continue;
            }
            if ($keyContractId !== $normalizedContractId) {
                continue;
            }

            $keys[$ledgerKey->toBase64Xdr()] = true;
        }

        return array_keys($keys);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function extractCallsWithContractId(?string $envelopeXdrBase64): array
    {
        if (!is_string($envelopeXdrBase64) || $envelopeXdrBase64 === '') {
            return [];
        }

        try {
            $envelope = XdrTransactionEnvelope::fromEnvelopeBase64XdrString($envelopeXdrBase64);
        } catch (\Throwable) {
            return [];
        }

        $calls = [];
        foreach ($this->extractOperationsFromEnvelope($envelope) as $operation) {
            $body = $operation->getBody();
            if ($body->getType()->getValue() !== XdrOperationType::INVOKE_HOST_FUNCTION) {
                continue;
            }

            $invoke = $body->getInvokeHostFunctionOperation();
            $hostFunction = $invoke?->getHostFunction();
            if ($hostFunction === null || $hostFunction->getType()->getValue() !== XdrHostFunctionType::HOST_FUNCTION_TYPE_INVOKE_CONTRACT) {
                continue;
            }

            $invokeArgs = $hostFunction->getInvokeContract();
            if ($invokeArgs === null) {
                continue;
            }

            $argsDecoded = [];
            foreach ($invokeArgs->getArgs() as $arg) {
                try {
                    $argsDecoded[] = $this->sorobanScValMapper->scValToNative($arg);
                } catch (\Throwable) {
                    $argsDecoded[] = null;
                }
            }

            $calls[] = [
                'contractId' => $this->extractContractId($invokeArgs),
                'functionName' => $invokeArgs->getFunctionName(),
            ] + $this->buildEssentialCallFields($invokeArgs->getFunctionName(), $argsDecoded);
        }

        return $calls;
    }

    private function extractContractId(mixed $invokeArgs): ?string
    {
        try {
            $contractAddress = $invokeArgs?->getContractAddress();
            if ($contractAddress === null) {
                return null;
            }

            $strKey = $contractAddress->toStrKey();
            return is_string($strKey) && $strKey !== '' ? $strKey : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<int,mixed> $args
     * @return array<string,mixed>
     */
    private function buildEssentialCallFields(?string $functionName, array $args): array
    {
        $name = is_string($functionName) ? strtolower(trim($functionName)) : '';
        $from = $this->asAddress($args[0] ?? null);
        $to = $this->asAddress($args[1] ?? null);
        $amount = $this->normalizeAmount($args[2] ?? null);

        if ($name === 'transfer') {
            return [
                'from' => $from,
                'to' => $to,
                'amount' => $amount,
            ];
        }

        if ($name === 'approve') {
            return [
                'from' => $from,
                'spender' => $to,
                'amount' => $amount,
                'expiration' => $this->normalizeAmount($args[3] ?? null),
            ];
        }

        if ($name === 'mint') {
            return [
                'to' => $from,
                'amount' => $this->normalizeAmount($args[1] ?? null),
            ];
        }

        if (in_array($name, ['burn', 'clawback'], true)) {
            return [
                'from' => $from,
                'amount' => $this->normalizeAmount($args[1] ?? null),
            ];
        }

        return ['args' => $this->trimTrailingNullArgs($args)];
    }

    /**
     * @param array<int,mixed> $args
     * @return array<int,mixed>
     */
    private function trimTrailingNullArgs(array $args): array
    {
        while ($args !== [] && end($args) === null) {
            array_pop($args);
        }

        return $args;
    }

    private function asAddress(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        return preg_match('/^[GCMBL][A-Z2-7]{55,}$/', $trimmed) === 1 ? $trimmed : null;
    }

    private function normalizeAmount(mixed $value): mixed
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?[0-9]+$/', $value) === 1) {
            return $value;
        }

        if (
            is_array($value)
            && array_key_exists('hi', $value)
            && array_key_exists('lo', $value)
            && (is_int($value['hi']) || is_string($value['hi']))
            && (is_int($value['lo']) || is_string($value['lo']))
        ) {
            $hi = (int) $value['hi'];
            $lo = (int) $value['lo'];
            if ($hi === 0) {
                return (string) $lo;
            }

            return ['hi' => $hi, 'lo' => $lo];
        }

        return $value;
    }

    /**
     * @return array<XdrOperation>
     */
    private function extractOperationsFromEnvelope(XdrTransactionEnvelope $envelope): array
    {
        $tx = $this->extractTransactionFromEnvelope($envelope);
        if ($tx !== null) {
            return $tx->getOperations();
        }

        if ($envelope->getV0() !== null && method_exists($envelope->getV0()->getTx(), 'getOperations')) {
            /** @var array<XdrOperation> $ops */
            $ops = $envelope->getV0()->getTx()->getOperations();
            return $ops;
        }

        return [];
    }

    private function extractTransactionFromEnvelope(XdrTransactionEnvelope $envelope): mixed
    {
        if ($envelope->getV1() !== null) {
            return $envelope->getV1()->getTx();
        }

        if ($envelope->getFeeBump() !== null) {
            return $envelope->getFeeBump()->getTx()->getInnerTx()->getV1()->getTx();
        }

        return null;
    }
}
