<?php

namespace App\Service\Stellar\Soroban;

use Soneso\StellarSDK\Crypto\StrKey;
use Soneso\StellarSDK\Soroban\Address;
use Soneso\StellarSDK\Soroban\SorobanServer;
use Soneso\StellarSDK\Xdr\XdrContractDataDurability;
use Soneso\StellarSDK\Xdr\XdrLedgerEntryType;
use Soneso\StellarSDK\Xdr\XdrLedgerKey;
use Soneso\StellarSDK\Xdr\XdrLedgerKeyContractData;
use Soneso\StellarSDK\Xdr\XdrSCVal;

final class SorobanContractInspector
{
    public function __construct(
        private readonly SorobanServerFactory $sorobanServerFactory,
    ) {
    }

    public function normalizeContractId(?string $contractId): ?string
    {
        if (!is_string($contractId) || $contractId === '') {
            return null;
        }

        if (StrKey::isValidContractId($contractId)) {
            return $contractId;
        }

        if (preg_match('/^[0-9a-fA-F]{64}$/', $contractId) === 1) {
            try {
                return StrKey::encodeContractIdHex(strtolower($contractId));
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * @return array{wasmId:?string,executableType:?int}
     */
    public function loadContractExecutableMetaForContractId(SorobanServer $server, string $contractId): array
    {
        $ledgerKey = new XdrLedgerKey(XdrLedgerEntryType::CONTRACT_DATA());
        $ledgerKey->contractData = new XdrLedgerKeyContractData(
            Address::fromContractId($contractId)->toXdr(),
            XdrSCVal::forLedgerKeyContractInstance(),
            XdrContractDataDurability::PERSISTENT(),
        );

        $ledgerEntries = $server->getLedgerEntries([$ledgerKey->toBase64Xdr()]);
        if ($ledgerEntries->entries === null || count($ledgerEntries->entries) === 0) {
            return ['wasmId' => null, 'executableType' => null];
        }

        $executable = $ledgerEntries->entries[0]->getLedgerEntryDataXdr()->contractData?->val->instance?->executable;

        return [
            'wasmId' => $executable?->wasmIdHex,
            'executableType' => $executable?->type->value,
        ];
    }

    public function buildContractInstanceLedgerKeyBase64(string $contractId): string
    {
        $ledgerKey = new XdrLedgerKey(XdrLedgerEntryType::CONTRACT_DATA());
        $ledgerKey->contractData = new XdrLedgerKeyContractData(
            Address::fromContractId($contractId)->toXdr(),
            XdrSCVal::forLedgerKeyContractInstance(),
            XdrContractDataDurability::PERSISTENT(),
        );

        return $ledgerKey->toBase64Xdr();
    }

    public function contractExistsOnNetwork(string $contractId, ?string $network = null): bool
    {
        try {
            $normalizedContractId = $this->normalizeContractId($contractId);
            if ($normalizedContractId === null) {
                return false;
            }

            $server = $this->sorobanServerFactory->create($network);
            $executableMeta = $this->loadContractExecutableMetaForContractId($server, $normalizedContractId);

            return is_int($executableMeta['executableType']);
        } catch (\Throwable) {
            return false;
        }
    }
}
