<?php

declare(strict_types=1);

namespace App\Service\Stellar\Soroban;

use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\Crypto\StrKey;
use Soneso\StellarSDK\Util\Hash;
use Soneso\StellarSDK\Xdr\XdrContractIDPreimage;
use Soneso\StellarSDK\Xdr\XdrEnvelopeType;
use Soneso\StellarSDK\Xdr\XdrHashIDPreimage;
use Soneso\StellarSDK\Xdr\XdrHashIDPreimageContractID;

final class LedgerJsonContractExtractor
{
    private const PUBLIC_NETWORK_PASSPHRASE = 'Public Global Stellar Network ; September 2015';

    /** @var array<string,string> */
    private array $sacContractIdCache = [];

    public function __construct(
        private readonly SorobanContractInspector $contractInspector,
        private readonly string $networkPassphrase = self::PUBLIC_NETWORK_PASSPHRASE,
    ) {
    }

    /**
     * @return array{
     *     sequence:int,
     *     closedAt:?string,
     *     transactions:list<array<string,mixed>>
     * }
     */
    public function extract(
        array $ledger,
        bool $includeDiagnosticEvents = true,
        ?string $networkPassphrase = null
    ): array {
        $networkPassphrase ??= $this->networkPassphrase;
        $sequence = (int) ($ledger['sequence'] ?? 0);
        $closedAt = $this->normalizeLedgerCloseTime($ledger['ledgerCloseTime'] ?? null);
        $metadata = is_array($ledger['metadataJson'] ?? null) ? $ledger['metadataJson'] : [];
        $closeMeta = $this->extractCloseMetaPayload($metadata);

        $envelopes = [];
        $this->collectTransactionEnvelopes($closeMeta['tx_set'] ?? null, $envelopes);

        $txProcessingRows = is_array($closeMeta['tx_processing'] ?? null) ? array_values($closeMeta['tx_processing']) : [];
        $transactions = [];
        foreach ($txProcessingRows as $index => $txProcessing) {
            if (!is_array($txProcessing)) {
                continue;
            }

            $envelope = is_array($envelopes[$index] ?? null) ? $envelopes[$index] : [];
            $txHash = $this->extractTransactionHash($txProcessing);
            if ($txHash === null) {
                continue;
            }

            $operations = is_array($envelope['operations'] ?? null) ? $envelope['operations'] : [];
            $invokeCalls = $this->extractInvokeCallsFromOperations($operations);
            $operationTypes = $this->extractOperationTypes($operations);
            $events = $this->extractEvents($txProcessing, $txHash, $sequence, $closedAt, $includeDiagnosticEvents);
            $storage = $this->extractStorageEntries($txProcessing, $sequence);
            $contractMeta = $this->extractContractMetaFromStorage($storage);
            $this->mergeClassicAssetContractMeta($contractMeta, $this->extractClassicAssetContractMeta($operations, $networkPassphrase));
            $this->mergeCreateContractMeta($contractMeta, $invokeCalls);

            $contractIds = [];
            foreach ($invokeCalls as $call) {
                $this->addContractId($contractIds, $call['contractId'] ?? null);
            }
            foreach ($events as $event) {
                if (($event['isDiagnostic'] ?? false) === true) {
                    continue;
                }
                $this->addContractId($contractIds, $event['contractId'] ?? null);
            }
            foreach ($storage as $entry) {
                $this->addContractId($contractIds, $entry['contractId'] ?? null);
            }

            if ($contractIds === []) {
                continue;
            }

            $eventsByContract = [];
            foreach ($events as $event) {
                $contractId = (string) ($event['contractId'] ?? '');
                if ($contractId === '') {
                    continue;
                }
                $eventsByContract[$contractId] ??= [];
                $event['eventIndex'] = count($eventsByContract[$contractId]) + 1;
                $eventsByContract[$contractId][] = $event;
            }

            $storageByContract = [];
            foreach ($storage as $entry) {
                $contractId = (string) ($entry['contractId'] ?? '');
                if ($contractId === '') {
                    continue;
                }
                $storageByContract[$contractId] ??= [];
                $storageByContract[$contractId][] = $entry;
            }

            $transactions[] = [
                'txHash' => $txHash,
                'sourceAccount' => $this->normalizeNullableString($envelope['sourceAccount'] ?? null),
                'feeCharged' => $this->extractFeeCharged($txProcessing),
                'maxFee' => isset($envelope['maxFee']) ? (int) $envelope['maxFee'] : 0,
                'ledger' => $sequence,
                'createdAt' => $closedAt,
                'totalOperations' => count($operations),
                'operationTypes' => $operationTypes,
                'invokeCalls' => $invokeCalls,
                'effectsCount' => count($events),
                'contractIds' => array_keys($contractIds),
                'eventsByContract' => $eventsByContract,
                'storageByContract' => $storageByContract,
                'contractMetaByContract' => $contractMeta,
                'returnValue' => $this->extractReturnValue($txProcessing),
                'resourceFeeCharged' => $this->extractResourceFeeCharged($txProcessing),
                'envelopeDecoded' => $envelope['raw'] ?? null,
                'metaDecoded' => $txProcessing,
            ];
        }

        return [
            'sequence' => $sequence,
            'closedAt' => $closedAt,
            'transactions' => $transactions,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function extractCloseMetaPayload(array $metadata): array
    {
        foreach (['v2', 'v1', 'v0'] as $versionKey) {
            if (is_array($metadata[$versionKey] ?? null)) {
                return $metadata[$versionKey];
            }
        }

        return $metadata;
    }

    /**
     * @param array<int,array<string,mixed>> $result
     */
    private function collectTransactionEnvelopes(mixed $node, array &$result): void
    {
        if (!is_array($node)) {
            return;
        }

        if (isset($node['tx_fee_bump']) && is_array($node['tx_fee_bump'])) {
            $result[] = $this->extractFeeBumpEnvelope($node['tx_fee_bump']);
            return;
        }

        if (
            isset($node['tx'])
            && is_array($node['tx'])
            && isset($node['tx']['tx'], $node['tx']['signatures'])
        ) {
            $normal = $this->extractNormalEnvelope($node);
            if ($normal !== null) {
                $result[] = $normal;
                return;
            }
        }

        foreach ($node as $child) {
            $this->collectTransactionEnvelopes($child, $result);
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function extractNormalEnvelope(array $envelope): ?array
    {
        $tx = $envelope['tx']['tx'] ?? null;
        if (!is_array($tx) || !is_array($tx['operations'] ?? null)) {
            return null;
        }

        return [
            'sourceAccount' => $this->normalizeNullableString($tx['source_account'] ?? null),
            'maxFee' => isset($tx['fee']) ? (int) $tx['fee'] : 0,
            'operations' => array_values($tx['operations']),
            'raw' => $envelope,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function extractFeeBumpEnvelope(array $feeBump): array
    {
        $innerTx = $feeBump['tx']['inner_tx']['tx']['tx'] ?? null;
        if (!is_array($innerTx)) {
            $innerTx = [];
        }

        return [
            'sourceAccount' => $this->normalizeNullableString($innerTx['source_account'] ?? null),
            'maxFee' => isset($feeBump['tx']['fee']) ? (int) $feeBump['tx']['fee'] : 0,
            'operations' => is_array($innerTx['operations'] ?? null) ? array_values($innerTx['operations']) : [],
            'raw' => ['tx_fee_bump' => $feeBump],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function extractInvokeCallsFromOperations(array $operations): array
    {
        $calls = [];
        foreach ($operations as $operation) {
            if (!is_array($operation)) {
                continue;
            }

            $hostFunction = $operation['body']['invoke_host_function']['host_function'] ?? null;
            if (!is_array($hostFunction)) {
                continue;
            }

            if (is_array($hostFunction['invoke_contract'] ?? null)) {
                $invoke = $hostFunction['invoke_contract'];
                $contractId = $this->normalizeContractId($invoke['contract_address'] ?? null);
                if ($contractId === null) {
                    continue;
                }

                $calls[] = [
                    'type' => 'invoke_contract',
                    'contractId' => $contractId,
                    'functionName' => $this->normalizeNullableString($invoke['function_name'] ?? null) ?? 'unknown',
                    'args' => $this->normalizeScValList($invoke['args'] ?? []),
                ];
                continue;
            }

            if (is_array($hostFunction['create_contract'] ?? null)) {
                $calls[] = [
                    'type' => 'create_contract',
                    'contractId' => null,
                    'functionName' => 'create_contract',
                    'args' => $this->normalizeScValJson($hostFunction['create_contract']),
                ];
                continue;
            }

            if (is_array($hostFunction['create_contract_v2'] ?? null)) {
                $calls[] = [
                    'type' => 'create_contract_v2',
                    'contractId' => null,
                    'functionName' => 'create_contract_v2',
                    'args' => $this->normalizeScValJson($hostFunction['create_contract_v2']),
                ];
                continue;
            }

            if (array_key_exists('upload_contract_wasm', $hostFunction)) {
                $calls[] = [
                    'type' => 'upload_contract_wasm',
                    'contractId' => null,
                    'functionName' => 'upload_contract_wasm',
                    'args' => null,
                ];
            }
        }

        return $calls;
    }

    /**
     * @return list<string>
     */
    private function extractOperationTypes(array $operations): array
    {
        $types = [];
        foreach ($operations as $operation) {
            if (!is_array($operation)) {
                continue;
            }
            $body = is_array($operation['body'] ?? null) ? $operation['body'] : [];
            foreach (array_keys($body) as $type) {
                $types[$type] = true;
            }
        }

        return array_keys($types);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function extractEvents(
        array $txProcessing,
        string $txHash,
        int $ledger,
        ?string $closedAt,
        bool $includeDiagnosticEvents
    ): array {
        $events = [];
        $this->collectEvents($txProcessing, $events, false, $includeDiagnosticEvents);

        $normalized = [];
        foreach ($events as $eventRow) {
            $event = is_array($eventRow['event'] ?? null) ? $eventRow['event'] : $eventRow;
            $contractId = $this->normalizeContractId($event['contract_id'] ?? null);
            if ($contractId === null) {
                continue;
            }

            $body = is_array($event['body']['v0'] ?? null) ? $event['body']['v0'] : [];
            $topicDecoded = $this->normalizeScValList($body['topics'] ?? []);
            $valueDecoded = $this->normalizeScValJson($body['data'] ?? null);
            $eventType = $this->detectEventType($topicDecoded, $event['type_'] ?? null);
            $addresses = $this->extractAddressesFromMixed([$topicDecoded, $valueDecoded]);

            $normalized[] = [
                'contractId' => $contractId,
                'txHash' => $txHash,
                'ledger' => $ledger,
                'ledgerClosedAt' => $closedAt,
                'eventType' => $eventType,
                'topicDecoded' => $topicDecoded,
                'valueDecoded' => $valueDecoded,
                'addresses' => $addresses,
                'amountRaw' => $this->normalizeAmountRaw($valueDecoded),
                'isDiagnostic' => (bool) ($eventRow['isDiagnostic'] ?? false),
                'raw' => $eventRow,
            ];
        }

        return $normalized;
    }

    /**
     * @param list<array<string,mixed>> $events
     */
    private function collectEvents(mixed $node, array &$events, bool $diagnostic, bool $includeDiagnosticEvents): void
    {
        if (!is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            if ($key === 'diagnostic_events') {
                if ($includeDiagnosticEvents && is_array($value)) {
                    foreach ($value as $eventRow) {
                        if (is_array($eventRow)) {
                            $eventRow['isDiagnostic'] = true;
                            $events[] = $eventRow;
                        }
                    }
                }
                continue;
            }

            if ($key === 'events' && is_array($value)) {
                foreach ($value as $eventRow) {
                    if (is_array($eventRow)) {
                        $eventRow['isDiagnostic'] = $diagnostic;
                        $events[] = $eventRow;
                    }
                }
                continue;
            }

            $this->collectEvents($value, $events, $diagnostic, $includeDiagnosticEvents);
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function extractStorageEntries(array $txProcessing, int $ledger): array
    {
        $entries = [];
        $seen = [];
        $this->collectStorageEntries($txProcessing, $entries, $ledger, null, null, $seen);

        return $entries;
    }

    /**
     * @param list<array<string,mixed>> $entries
     * @param array<string,bool> $seen
     */
    private function collectStorageEntries(
        mixed $node,
        array &$entries,
        int $ledger,
        ?string $changeType,
        ?int $lastModifiedLedgerSeq,
        array &$seen
    ): void
    {
        if (!is_array($node)) {
            return;
        }

        $currentLastModifiedLedgerSeq = isset($node['last_modified_ledger_seq'])
            ? (int) $node['last_modified_ledger_seq']
            : $lastModifiedLedgerSeq;

        if ($this->looksLikeContractData($node)) {
            $contractId = $this->normalizeContractId($node['contract'] ?? null);
            if ($contractId !== null) {
                $entry = [
                    'contractId' => $contractId,
                    'key' => $this->buildStorageKey($node['key'] ?? null),
                    'xdr' => null,
                    'lastModifiedLedgerSeq' => $currentLastModifiedLedgerSeq ?? $ledger,
                    'liveUntilLedgerSeq' => null,
                    'changeType' => $changeType ?? 'unknown',
                    'contractData' => $node,
                ];
                $dedupeKey = hash('sha256', (string) json_encode([
                    $entry['contractId'],
                    $entry['key'],
                    $entry['lastModifiedLedgerSeq'],
                    $entry['changeType'],
                ]));
                if (!isset($seen[$dedupeKey])) {
                    $seen[$dedupeKey] = true;
                    $entries[] = $entry;
                }
            }
        }

        foreach ($node as $key => $child) {
            $childChangeType = $this->detectLedgerEntryChangeType($key, $changeType);
            $this->collectStorageEntries($child, $entries, $ledger, $childChangeType, $currentLastModifiedLedgerSeq, $seen);
        }
    }

    private function detectLedgerEntryChangeType(mixed $key, ?string $fallback): ?string
    {
        if (!is_string($key)) {
            return $fallback;
        }

        return match ($key) {
            'created', 'ledger_entry_created' => 'created',
            'updated', 'ledger_entry_updated' => 'updated',
            'state', 'ledger_entry_state' => 'state',
            default => $fallback,
        };
    }

    private function looksLikeContractData(array $node): bool
    {
        return array_key_exists('contract', $node)
            && array_key_exists('key', $node)
            && array_key_exists('val', $node);
    }

    /**
     * @param list<array<string,mixed>> $storage
     * @return array<string,array<string,mixed>>
     */
    private function extractContractMetaFromStorage(array $storage): array
    {
        $meta = [];
        foreach ($storage as $entry) {
            $contractId = (string) ($entry['contractId'] ?? '');
            if ($contractId === '') {
                continue;
            }

            $contractData = is_array($entry['contractData'] ?? null) ? $entry['contractData'] : [];
            $executable = $contractData['val']['contract_instance']['executable'] ?? null;
            $wasmId = is_array($executable) ? ($executable['wasm'] ?? null) : null;
            if (is_string($wasmId) && preg_match('/^[0-9a-fA-F]{64}$/', $wasmId) === 1) {
                $meta[$contractId]['wasmId'] = strtolower($wasmId);
                $meta[$contractId]['executableType'] = 0;
            }
            if ($this->containsStellarAssetExecutable($executable)) {
                $meta[$contractId]['isSac'] = true;
                $meta[$contractId]['executableType'] = 1;
            }

            if (
                ($entry['changeType'] ?? null) === 'created'
                && $this->isContractInstanceStorageKey($contractData['key'] ?? null)
                && is_array($contractData['val']['contract_instance'] ?? null)
            ) {
                $meta[$contractId]['deployed'] = true;
                $meta[$contractId]['deploymentKind'] = 'contract_instance_created';
            }
        }

        return $meta;
    }

    /**
     * @param array<string,array<string,mixed>> $contractMeta
     * @param array<string,array<string,mixed>> $classicAssetContractMeta
     */
    private function mergeClassicAssetContractMeta(array &$contractMeta, array $classicAssetContractMeta): void
    {
        foreach ($classicAssetContractMeta as $contractId => $meta) {
            $existing = $contractMeta[$contractId] ?? [];
            if (isset($existing['wasmId']) || (isset($existing['executableType']) && (int) $existing['executableType'] === 0)) {
                continue;
            }

            $contractMeta[$contractId] = array_replace($meta, $existing);
        }
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function extractClassicAssetContractMeta(array $operations, string $networkPassphrase): array
    {
        $assets = [];
        foreach ($operations as $operation) {
            if (!is_array($operation)) {
                continue;
            }

            $body = is_array($operation['body'] ?? null) ? $operation['body'] : [];
            if (array_key_exists('invoke_host_function', $body)) {
                continue;
            }

            $this->collectClassicAssets($body, $assets);
        }

        $meta = [];
        foreach ($assets as $asset) {
            $contractId = $this->deriveSacContractId($asset, $networkPassphrase);
            if ($contractId === null) {
                continue;
            }

            $meta[$contractId] = [
                'isSac' => true,
                'executableType' => 1,
                'assetCode' => $asset['assetCode'],
                'assetIssuer' => $asset['assetIssuer'],
                'assetAddress' => $contractId,
            ];
        }

        return $meta;
    }

    /**
     * @param array<string,array{assetCode:string,assetIssuer:?string}> $assets
     */
    private function collectClassicAssets(mixed $value, array &$assets): void
    {
        if (!is_array($value)) {
            return;
        }

        $asset = $this->extractAssetMetaFromNode($value);
        if ($asset !== null) {
            $assets[$this->assetMetaKey($asset)] = $asset;
            return;
        }

        foreach ($value as $child) {
            $this->collectClassicAssets($child, $assets);
        }
    }

    /**
     * @return array{assetCode:string,assetIssuer:?string}|null
     */
    private function extractAssetMetaFromNode(array $value): ?array
    {
        foreach (['native', 'asset_type_native'] as $nativeKey) {
            if (array_key_exists($nativeKey, $value)) {
                return ['assetCode' => 'XLM', 'assetIssuer' => null];
            }
        }

        $code = $this->normalizeAssetCode($value['asset_code'] ?? $value['code'] ?? null);
        $issuer = $this->normalizeAccountId($value['asset_issuer'] ?? $value['issuer'] ?? null);
        if ($code !== null && $issuer !== null) {
            return ['assetCode' => $code, 'assetIssuer' => $issuer];
        }

        foreach ([
            'alpha_num4',
            'alpha_num12',
            'alphaNum4',
            'alphaNum12',
            'credit_alphanum4',
            'credit_alphanum12',
            'credit_alphanum_4',
            'credit_alphanum_12',
        ] as $assetKey) {
            if (array_key_exists($assetKey, $value) && is_array($value[$assetKey])) {
                return $this->extractAssetMeta($value[$assetKey]);
            }
        }

        return null;
    }

    /**
     * @param array{assetCode:string,assetIssuer:?string} $asset
     */
    private function assetMetaKey(array $asset): string
    {
        return strtoupper($asset['assetCode']) . ':' . ($asset['assetIssuer'] ?? '');
    }

    /**
     * @param array{assetCode:string,assetIssuer:?string} $assetMeta
     */
    private function deriveSacContractId(array $assetMeta, string $networkPassphrase): ?string
    {
        $cacheKey = hash('sha256', $networkPassphrase) . ':' . $this->assetMetaKey($assetMeta);
        if (isset($this->sacContractIdCache[$cacheKey])) {
            return $this->sacContractIdCache[$cacheKey];
        }

        try {
            $asset = $assetMeta['assetCode'] === 'XLM' && $assetMeta['assetIssuer'] === null
                ? Asset::native()
                : Asset::createNonNativeAsset($assetMeta['assetCode'], (string) $assetMeta['assetIssuer']);

            $contractIdPreimage = XdrContractIDPreimage::forAsset($asset->toXdr());
            $hashPreimage = new XdrHashIDPreimage(new XdrEnvelopeType(XdrEnvelopeType::ENVELOPE_TYPE_CONTRACT_ID));
            $hashPreimage->contractID = new XdrHashIDPreimageContractID(
                Hash::generate($networkPassphrase),
                $contractIdPreimage
            );

            $contractId = StrKey::encodeContractId(Hash::generate($hashPreimage->encode()));
            $this->sacContractIdCache[$cacheKey] = $contractId;

            return $contractId;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string,array<string,mixed>> $contractMeta
     * @param list<array<string,mixed>> $invokeCalls
     */
    private function mergeCreateContractMeta(array &$contractMeta, array $invokeCalls): void
    {
        $deployedContractIds = [];
        foreach ($contractMeta as $contractId => $meta) {
            if (($meta['deployed'] ?? false) === true) {
                $deployedContractIds[] = $contractId;
            }
        }
        if (count($deployedContractIds) !== 1) {
            return;
        }

        $createMetas = [];
        foreach ($invokeCalls as $call) {
            $type = is_string($call['type'] ?? null) ? $call['type'] : '';
            if (!in_array($type, ['create_contract', 'create_contract_v2'], true)) {
                continue;
            }

            $createMeta = $this->extractCreateContractMeta($call['args'] ?? null);
            if ($createMeta !== null) {
                $createMetas[] = $createMeta;
            }
        }
        if (count($createMetas) !== 1) {
            return;
        }

        $contractId = $deployedContractIds[0];
        $contractMeta[$contractId] = array_replace($contractMeta[$contractId] ?? [], $createMetas[0]);
        if (($createMetas[0]['isSac'] ?? false) === true) {
            $contractMeta[$contractId]['assetAddress'] ??= $contractId;
            $contractMeta[$contractId]['deploymentKind'] = 'sac_contract_created';
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function extractCreateContractMeta(mixed $args): ?array
    {
        if (!is_array($args)) {
            return null;
        }

        $isSac = $this->containsStellarAssetExecutable($args);
        if (!$isSac) {
            return null;
        }

        $asset = $this->extractAssetMeta($args);
        $meta = [
            'isSac' => true,
            'executableType' => 1,
        ];

        if ($asset !== null) {
            $meta['assetCode'] = $asset['assetCode'];
            $meta['assetIssuer'] = $asset['assetIssuer'];
        }

        return $meta;
    }

    private function containsStellarAssetExecutable(mixed $value): bool
    {
        if (is_string($value)) {
            return in_array(strtolower($value), [
                'stellar_asset',
                'contract_executable_stellar_asset',
                'contract_executable_type_stellar_asset',
            ], true);
        }

        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $key => $child) {
            if (is_string($key) && in_array(strtolower($key), [
                'stellar_asset',
                'contract_executable_stellar_asset',
                'contract_executable_type_stellar_asset',
            ], true)) {
                return true;
            }
            if ($this->containsStellarAssetExecutable($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{assetCode:string,assetIssuer:?string}|null
     */
    private function extractAssetMeta(mixed $value): ?array
    {
        if (is_string($value)) {
            return strtolower($value) === 'native' ? ['assetCode' => 'XLM', 'assetIssuer' => null] : null;
        }

        if (!is_array($value)) {
            return null;
        }

        foreach (['native', 'asset_type_native'] as $nativeKey) {
            if (array_key_exists($nativeKey, $value)) {
                return ['assetCode' => 'XLM', 'assetIssuer' => null];
            }
        }

        $code = $this->normalizeAssetCode($value['asset_code'] ?? $value['code'] ?? null);
        $issuer = $this->normalizeAccountId($value['asset_issuer'] ?? $value['issuer'] ?? null);
        if ($code !== null && $issuer !== null) {
            return ['assetCode' => $code, 'assetIssuer' => $issuer];
        }

        foreach ([
            'alpha_num4',
            'alpha_num12',
            'alphaNum4',
            'alphaNum12',
            'credit_alphanum4',
            'credit_alphanum12',
            'credit_alphanum_4',
            'credit_alphanum_12',
            'from_asset',
            'asset',
        ] as $assetKey) {
            if (array_key_exists($assetKey, $value)) {
                $asset = $this->extractAssetMeta($value[$assetKey]);
                if ($asset !== null) {
                    return $asset;
                }
            }
        }

        foreach ($value as $child) {
            $asset = $this->extractAssetMeta($child);
            if ($asset !== null) {
                return $asset;
            }
        }

        return null;
    }

    private function normalizeAssetCode(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = rtrim(trim($value), "\0");
        return $value !== '' && strlen($value) <= 12 ? $value : null;
    }

    private function normalizeAccountId(mixed $value): ?string
    {
        if (is_array($value)) {
            foreach (['account_id', 'accountId', 'ed25519', 'issuer', 'public_key', 'publicKey'] as $key) {
                $accountId = $this->normalizeAccountId($value[$key] ?? null);
                if ($accountId !== null) {
                    return $accountId;
                }
            }
            foreach ($value as $child) {
                $accountId = $this->normalizeAccountId($child);
                if ($accountId !== null) {
                    return $accountId;
                }
            }

            return null;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (StrKey::isValidAccountId($value)) {
            return strtoupper($value);
        }
        if (preg_match('/^[0-9a-fA-F]{64}$/', $value) === 1) {
            try {
                return StrKey::encodeAccountId(hex2bin(strtolower($value)));
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function isContractInstanceStorageKey(mixed $value): bool
    {
        if ($value === 'ledger_key_contract_instance') {
            return true;
        }

        if (!is_array($value)) {
            return false;
        }

        if (array_key_exists('ledger_key_contract_instance', $value)) {
            return true;
        }

        foreach ($value as $child) {
            if ($this->isContractInstanceStorageKey($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<mixed>
     */
    private function normalizeScValList(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        $result = [];
        foreach ($values as $value) {
            $result[] = $this->normalizeScValJson($value);
        }

        return $result;
    }

    private function normalizeScValJson(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        foreach (['address', 'symbol', 'string', 'bytes', 'u32', 'i32', 'u64', 'i64', 'u128', 'i128', 'u256', 'i256', 'timepoint', 'duration', 'bool'] as $key) {
            if (array_key_exists($key, $value) && count($value) === 1) {
                return $value[$key];
            }
        }

        if (array_key_exists('void', $value) && count($value) === 1) {
            return null;
        }

        if (isset($value['vec']) && is_array($value['vec'])) {
            return $this->normalizeScValList($value['vec']);
        }

        if (isset($value['map']) && is_array($value['map'])) {
            $mapped = [];
            $list = [];
            $associative = true;
            foreach ($value['map'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $key = $this->normalizeScValJson($item['key'] ?? null);
                $val = $this->normalizeScValJson($item['val'] ?? null);
                if (!is_string($key) && !is_int($key)) {
                    $associative = false;
                }
                $list[] = ['key' => $key, 'val' => $val];
                if (is_string($key) || is_int($key)) {
                    $mapped[(string) $key] = $val;
                }
            }

            return $associative ? $mapped : $list;
        }

        $normalized = [];
        foreach ($value as $key => $child) {
            $normalized[$key] = $this->normalizeScValJson($child);
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function extractAddressesFromMixed(mixed $value): array
    {
        $addresses = [];
        $this->collectAddresses($value, $addresses);

        return array_values(array_unique($addresses));
    }

    /**
     * @param list<string> $addresses
     */
    private function collectAddresses(mixed $value, array &$addresses): void
    {
        if (is_string($value)) {
            if (preg_match('/^[GC][A-Z2-7]{55}$/', $value) === 1) {
                $addresses[] = $value;
            }
            return;
        }

        if (!is_array($value)) {
            return;
        }

        foreach ($value as $child) {
            $this->collectAddresses($child, $addresses);
        }
    }

    private function detectEventType(array $topicDecoded, mixed $fallback): string
    {
        foreach ($topicDecoded as $item) {
            if (!is_string($item)) {
                continue;
            }
            $lower = strtolower(trim($item));
            if (in_array($lower, ['transfer', 'mint', 'burn', 'approve'], true)) {
                return $lower;
            }
        }

        foreach ($topicDecoded as $item) {
            if (is_string($item) && trim($item) !== '') {
                return strtolower(trim($item));
            }
        }

        return is_string($fallback) && trim($fallback) !== '' ? strtolower(trim($fallback)) : 'unknown';
    }

    private function normalizeAmountRaw(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_string($value) && preg_match('/^-?[0-9]+$/', $value) === 1) {
            return $value;
        }
        if (is_array($value)) {
            if (array_key_exists('amount', $value)) {
                return $this->normalizeAmountRaw($value['amount']);
            }
            foreach ($value as $child) {
                $nested = $this->normalizeAmountRaw($child);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    private function extractTransactionHash(array $txProcessing): ?string
    {
        $hash = $txProcessing['result']['transaction_hash'] ?? null;
        return is_string($hash) && preg_match('/^[0-9a-fA-F]{64}$/', $hash) === 1 ? strtolower($hash) : null;
    }

    private function extractFeeCharged(array $txProcessing): int
    {
        $fee = $txProcessing['result']['result']['fee_charged'] ?? null;
        return is_numeric($fee) ? (int) $fee : 0;
    }

    private function extractReturnValue(array $txProcessing): mixed
    {
        $sorobanMeta = $this->findFirstKeyRecursive($txProcessing, 'soroban_meta');
        if (!is_array($sorobanMeta)) {
            return null;
        }

        return $this->normalizeScValJson($sorobanMeta['return_value'] ?? null);
    }

    private function extractResourceFeeCharged(array $txProcessing): ?int
    {
        $sorobanMeta = $this->findFirstKeyRecursive($txProcessing, 'soroban_meta');
        if (!is_array($sorobanMeta)) {
            return null;
        }

        $ext = $sorobanMeta['ext']['v1'] ?? null;
        if (!is_array($ext)) {
            return null;
        }

        $nonRefundable = is_numeric($ext['total_non_refundable_resource_fee_charged'] ?? null)
            ? (int) $ext['total_non_refundable_resource_fee_charged']
            : 0;
        $refundable = is_numeric($ext['total_refundable_resource_fee_charged'] ?? null)
            ? (int) $ext['total_refundable_resource_fee_charged']
            : 0;

        return $nonRefundable + $refundable;
    }

    private function findFirstKeyRecursive(mixed $node, string $targetKey): mixed
    {
        if (!is_array($node)) {
            return null;
        }
        if (array_key_exists($targetKey, $node)) {
            return $node[$targetKey];
        }
        foreach ($node as $child) {
            $found = $this->findFirstKeyRecursive($child, $targetKey);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function buildStorageKey(mixed $key): string
    {
        $encoded = json_encode($this->normalizeScValJson($key), JSON_UNESCAPED_SLASHES);
        $encoded = is_string($encoded) ? $encoded : 'null';
        if (strlen($encoded) <= 480) {
            return $encoded;
        }

        return 'sha256:' . hash('sha256', $encoded);
    }

    private function normalizeLedgerCloseTime(mixed $value): ?string
    {
        if (!is_numeric($value)) {
            return null;
        }

        return (new \DateTimeImmutable('@' . (int) $value))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    private function normalizeContractId(mixed $value): ?string
    {
        if (is_array($value)) {
            foreach (['contract', 'contract_id', 'contractId', 'contract_address', 'contractAddress'] as $key) {
                $contractId = $this->normalizeContractId($value[$key] ?? null);
                if ($contractId !== null) {
                    return $contractId;
                }
            }

            foreach ($value as $child) {
                $contractId = $this->normalizeContractId($child);
                if ($contractId !== null) {
                    return $contractId;
                }
            }

            return null;
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $normalized = $this->contractInspector->normalizeContractId(trim($value));
        return is_string($normalized) && $normalized !== '' ? strtoupper($normalized) : null;
    }

    /**
     * @param array<string,bool> $contractIds
     */
    private function addContractId(array &$contractIds, mixed $value): void
    {
        $contractId = $this->normalizeContractId($value);
        if ($contractId !== null) {
            $contractIds[$contractId] = true;
        }
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed !== '' ? $trimmed : null;
    }
}
