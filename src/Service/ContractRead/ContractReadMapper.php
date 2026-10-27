<?php

declare(strict_types=1);

namespace App\Service\ContractRead;

use App\Service\Stellar\Soroban\InvokeContractCallExtractor;
use App\Service\Stellar\Soroban\SorobanScValMapper;
use Soneso\StellarSDK\Crypto\StrKey;
use Soneso\StellarSDK\AbstractTransaction;
use Soneso\StellarSDK\Network;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\Util\Hash;
use Soneso\StellarSDK\Xdr\XdrContractIDPreimage;
use Soneso\StellarSDK\Xdr\XdrEnvelopeType;
use Soneso\StellarSDK\Xdr\XdrHashIDPreimage;
use Soneso\StellarSDK\Xdr\XdrHashIDPreimageContractID;
use Soneso\StellarSDK\Xdr\XdrSCVal;

final class ContractReadMapper
{
    public function __construct(
        private readonly InvokeContractCallExtractor $callExtractor,
        private readonly SorobanScValMapper $scValMapper,
    ) {
    }

    public function contract(string $contractId, array $record): array
    {
        $this->assertContract($contractId, $record);
        $executable = $record['executable_type'] ?? null;
        if (!in_array($executable, ['wasm', 'stellar_asset'], true)) {
            throw new \UnexpectedValueException('Unsupported contract executable.');
        }
        $wasmHash = $executable === 'wasm' ? ($record['wasm_hash'] ?? null) : null;
        if ($executable === 'wasm' && (!is_string($wasmHash) || preg_match('/^[a-f0-9]{64}$/i', $wasmHash) !== 1)) {
            throw new \UnexpectedValueException('Missing contract WASM hash.');
        }

        return $this->sacMetadata($contractId, $record) + [
            'contract_id' => $contractId,
            'contract_id_hex' => StrKey::decodeContractIdHex($contractId),
            'wasm_id' => is_string($wasmHash) ? strtolower($wasmHash) : null,
            'executable_type' => $executable === 'wasm' ? 0 : 1,
            'is_sac' => $executable === 'stellar_asset',
            'total_transactions' => $this->count($record, 'transactions_count'),
            'total_operations' => $this->count($record, 'operations_count'),
            'total_events' => $this->count($record, 'events_count'),
            'total_effects' => $this->count($record, 'effects_count'),
            'total_storage_entries' => $this->count($record, 'storage_entries_count'),
            'total_invokes' => $this->count($record, 'invocations_count'),
            // First-seen and deployment are deliberately separate facts.
            'orion_first_seen_at' => $record['first_seen_at'] ?? null,
            'orion_functions' => is_array($record['functions'] ?? null) ? $record['functions'] : [],
            'orion_archived' => is_bool($record['archived'] ?? null) ? $record['archived'] : null,
        ];
    }

    private function sacMetadata(string $contractId, array $record): array
    {
        if (($record['executable_type'] ?? null) !== 'stellar_asset') {
            return [];
        }
        $result = [];
        $code = $record['asset_code'] ?? null;
        $issuer = $record['asset_issuer'] ?? null;
        if (is_string($code) && $code !== '') {
            if (($record['asset_type'] ?? null) === 'native' && ($code !== 'XLM' || $issuer !== null)) {
                throw new \UnexpectedValueException('Invalid native SAC asset identity.');
            }
            $asset = ($record['asset_type'] ?? null) === 'native'
                ? Asset::native() : Asset::createNonNativeAsset($code, (string) $issuer);
            $preimage = new XdrHashIDPreimage(new XdrEnvelopeType(XdrEnvelopeType::ENVELOPE_TYPE_CONTRACT_ID));
            $preimage->contractID = new XdrHashIDPreimageContractID(
                Hash::generate(Network::public()->getNetworkPassphrase()), XdrContractIDPreimage::forAsset($asset->toXdr()),
            );
            if (StrKey::encodeContractId(Hash::generate($preimage->encode())) !== $contractId) {
                throw new \UnexpectedValueException('SAC asset does not match the contract address.');
            }
            $result = ['asset_code' => $code, 'asset_issuer' => $issuer];
        }
        $metadata = [];
        foreach ($record['instance_storage'] ?? [] as $entry) {
            if (!is_string($entry['key_xdr'] ?? null) || !is_string($entry['val_xdr'] ?? null)) {
                continue;
            }
            $key = $this->scValMapper->scValToNative(XdrSCVal::fromBase64Xdr($entry['key_xdr']));
            $value = $this->scValMapper->scValToNative(XdrSCVal::fromBase64Xdr($entry['val_xdr']));
            if ($key === 'METADATA' && is_array($value)) {
                foreach (['decimal' => 'decimals', 'name' => 'name', 'symbol' => 'symbol'] as $field => $target) {
                    if (isset($value[$field]) && (is_string($value[$field]) || is_int($value[$field]))) {
                        $metadata[$target] = $value[$field];
                    }
                }
            }
            if ($key === ['Admin'] && is_string($value)) {
                $metadata['admin'] = $value;
            }
        }

        return $result + ['orion_sac_metadata' => $metadata];
    }

    public function event(string $contractId, array $record): array
    {
        $this->assertContract($contractId, $record);
        $topics = $record['topic_xdr'] ?? null;
        $value = $record['value_xdr'] ?? null;
        if (!is_array($topics) || !array_is_list($topics) || !is_string($value)) {
            throw new \UnexpectedValueException('Missing event XDR.');
        }
        foreach ($topics as $topic) {
            if (!is_string($topic)) {
                throw new \UnexpectedValueException('Invalid event topic.');
            }
        }
        $eventName = isset($topics[0]) ? $this->scValMapper->scValToNative(XdrSCVal::fromBase64Xdr($topics[0])) : null;

        return [
            'id' => null,
            'upstreamId' => $record['id'] ?? $record['cursor'] ?? null,
            'txHash' => $this->transactionHash($record['transaction_hash'] ?? null),
            'eventIndex' => (int) ($record['event_order'] ?? 0),
            'ledger' => $this->ledger($record['ledger_sequence'] ?? null),
            'ledgerClosedAt' => $record['ledger_close_time'] ?? null,
            'createdAt' => $record['ledger_close_time'] ?? null,
            'eventType' => is_string($eventName) ? $eventName : 'unknown',
            // The existing browser decoder expects base64 ScVals in these fields.
            'topicDecoded' => $topics,
            'valueDecoded' => $value,
            'addresses' => [],
            'amountRaw' => null,
            'eventRaw' => $record,
            'isDiagnostic' => ($record['record_kind'] ?? null) === 'diagnostic' || ($record['event_type'] ?? null) === 'diagnostic',
            'successful' => $record['successful'] ?? null,
            'inSuccessfulContractCall' => $record['in_successful_contract_call'] ?? null,
        ];
    }

    /** @return array{binary:string,hash:string,size:int} */
    public function wasm(string $contractId, string $expectedHash, array $record): array
    {
        $this->assertContract($contractId, $record);
        $encoded = $record['wasm_base64'] ?? null;
        if (($record['executable_type'] ?? null) !== 'wasm' || !is_string($encoded)) {
            throw new \UnexpectedValueException('Missing WASM binary.');
        }
        $binary = base64_decode($encoded, true);
        if ($binary === false || strlen($binary) > 2 * 1024 * 1024
            || !str_starts_with($binary, "\0asm\x01\0\0\0")) {
            throw new \UnexpectedValueException('Invalid or oversized WASM binary.');
        }
        $hash = hash('sha256', $binary);
        if (!hash_equals($expectedHash, $hash) || ($record['wasm_hash'] ?? null) !== $hash) {
            throw new \UnexpectedValueException('WASM binary does not match the contract executable hash.');
        }
        if (isset($record['wasm_size_bytes']) && $record['wasm_size_bytes'] !== strlen($binary)) {
            throw new \UnexpectedValueException('WASM binary size mismatch.');
        }

        return ['binary' => $binary, 'hash' => $hash, 'size' => strlen($binary)];
    }

    public function transaction(string $contractId, array $record, bool $invocationsOnly): array
    {
        $envelope = $record['envelope_xdr'] ?? null;
        if (!is_string($envelope)) {
            throw new \UnexpectedValueException('Missing transaction envelope.');
        }
        if (!is_bool($record['successful'] ?? null)) {
            throw new \UnexpectedValueException('Missing transaction outcome.');
        }
        $hash = $this->transactionHash($record['hash'] ?? null);
        $transaction = AbstractTransaction::fromEnvelopeBase64XdrString($envelope);
        if (!hash_equals($hash, bin2hex($transaction->hash(Network::public())))) {
            throw new \UnexpectedValueException('Transaction hash and envelope disagree.');
        }
        $calls = $this->callExtractor->fromEnvelopeXdrForContract($envelope, $contractId);
        $storageKeys = $this->callExtractor->extractContractStorageLedgerKeysFromEnvelopeXdr($envelope, $contractId);
        if (($invocationsOnly && $calls === []) || ($calls === [] && $storageKeys === [])) {
            // Reject the entire upstream page; never relabel unrelated DEX activity.
            throw new \UnexpectedValueException('Transaction does not prove an association with the requested contract.');
        }

        return [
            'id' => null,
            'upstreamId' => $record['paging_token'] ?? null,
            'txHash' => $hash,
            'sourceAccount' => $record['source_account'] ?? null,
            'hostFunctions' => ['invokeContracts' => $calls],
            'feeCharged' => (int) ($record['fee_charged'] ?? 0),
            'maxFee' => (int) ($record['max_fee'] ?? 0),
            'ledger' => $this->ledger($record['ledger'] ?? null),
            'totalOperations' => (int) ($record['operation_count'] ?? 0),
            'createdAt' => $record['created_at'] ?? null,
            'successful' => is_bool($record['successful'] ?? null) ? $record['successful'] : null,
            'envelopeDecoded' => null,
            'metaDecoded' => null,
            'returnValueDecoded' => null,
            'resourceFeeCharged' => null,
        ];
    }

    public function storage(string $contractId, array $record): array
    {
        $this->assertContract($contractId, $record);
        $key = $record['key_xdr'] ?? null;
        $value = $record['val_xdr'] ?? null;
        $durability = $record['durability'] ?? null;
        if (!is_string($key) || !is_string($value) || !in_array($durability, ['instance', 'persistent', 'temporary'], true)) {
            throw new \UnexpectedValueException('Missing storage key/value XDR or durability.');
        }
        XdrSCVal::fromBase64Xdr($key);
        XdrSCVal::fromBase64Xdr($value);

        return [
            'id' => null,
            'upstreamId' => $record['id'] ?? null,
            'storageKey' => $key,
            // Instance subkeys are not standalone LedgerEntryData: do not fabricate XDR.
            'entryXdr' => null,
            'entryDecoded' => ['key' => $key, 'valueXdr' => $value, 'durability' => $durability],
            'entryRaw' => $record,
            'lastModifiedLedgerSeq' => $this->ledger($record['last_modified_ledger'] ?? null),
            'liveUntilLedgerSeq' => isset($record['live_until_ledger']) ? $this->ledger($record['live_until_ledger']) : null,
            'updatedAt' => null,
        ];
    }

    private function assertContract(string $contractId, array $record): void
    {
        if (($record['contract_id'] ?? null) !== $contractId) {
            throw new \UnexpectedValueException('Contract identity mismatch.');
        }
    }

    private function count(array $record, string $key): int
    {
        $value = $record[$key] ?? 0;
        if ((!is_int($value) && !(is_string($value) && ctype_digit($value))) || (int) $value < 0) {
            throw new \UnexpectedValueException('Invalid contract count.');
        }

        return (int) $value;
    }

    private function ledger(mixed $value): int
    {
        if (!is_int($value) || $value < 1) {
            throw new \UnexpectedValueException('Invalid ledger sequence.');
        }

        return $value;
    }

    private function transactionHash(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new \UnexpectedValueException('Invalid transaction hash.');
        }

        return $value;
    }
}
