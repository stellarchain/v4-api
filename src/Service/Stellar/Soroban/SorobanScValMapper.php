<?php

namespace App\Service\Stellar\Soroban;

use Soneso\StellarSDK\Soroban\Address;
use Soneso\StellarSDK\Xdr\XdrSCVal;
use Soneso\StellarSDK\Xdr\XdrSCValType;

final class SorobanScValMapper
{
    /**
     * @param mixed $events
     * @return array<int,array<string,mixed>>
     */
    public function parseEventsPayload(mixed $events): array
    {
        if (!is_array($events)) {
            return [];
        }

        $parsed = [];
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $topicRaw = isset($event['topic']) && is_array($event['topic']) ? $event['topic'] : [];
            $topicDecoded = [];
            foreach ($topicRaw as $topicItem) {
                if (!is_string($topicItem) || $topicItem === '') {
                    continue;
                }
                try {
                    $topicDecoded[] = $this->scValToNative(XdrSCVal::fromBase64Xdr($topicItem));
                } catch (\Throwable) {
                    $topicDecoded[] = $topicItem;
                }
            }

            $valueDecoded = null;
            if (isset($event['value']) && is_string($event['value']) && $event['value'] !== '') {
                try {
                    $valueDecoded = $this->scValToNative(XdrSCVal::fromBase64Xdr($event['value']));
                } catch (\Throwable) {
                    $valueDecoded = $event['value'];
                }
            }

            $eventType = $this->detectEventType($topicDecoded);
            $addresses = $this->extractAddressesFromMixed([$topicDecoded, $valueDecoded]);
            $amount = $this->extractAmountFromMixed([$topicDecoded, $valueDecoded]);

            $parsed[] = [
                'contractId' => $event['contractId'] ?? null,
                'ledger' => $event['ledger'] ?? null,
                'ledgerClosedAt' => $event['ledgerClosedAt'] ?? null,
                'txHash' => $event['txHash'] ?? null,
                'topic' => $topicRaw,
                'topicDecoded' => $topicDecoded,
                'value' => $event['value'] ?? null,
                'valueDecoded' => $valueDecoded,
                'eventType' => $eventType,
                'addresses' => array_values(array_unique($addresses)),
                'amount' => $amount,
            ];
        }

        return $parsed;
    }

    /**
     * @param mixed $entries
     * @return array<int,array<string,mixed>>
     */
    public function parseLedgerEntriesPayload(mixed $entries, ?int $latestLedger): array
    {
        if (!is_array($entries)) {
            return [];
        }

        $parsed = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $liveUntil = isset($entry['liveUntilLedgerSeq']) ? (int) $entry['liveUntilLedgerSeq'] : null;
            $ttl = ($liveUntil !== null && $latestLedger !== null) ? max(0, $liveUntil - $latestLedger) : null;

            $parsed[] = [
                'lastModifiedLedgerSeq' => $entry['lastModifiedLedgerSeq'] ?? null,
                'liveUntilLedgerSeq' => $liveUntil,
                'ttlLedgersRemaining' => $ttl,
                'key' => $entry['key'] ?? null,
                'xdr' => $entry['xdr'] ?? null,
                'hasContractData' => isset($entry['xdr']) && is_string($entry['xdr']),
            ];
        }

        return $parsed;
    }

    /**
     * @param array<int,array<string,mixed>> $parsedEvents
     * @return array<int,string>
     */
    public function extractTransactionHashes(array $parsedEvents): array
    {
        $hashes = [];
        foreach ($parsedEvents as $event) {
            $txHash = $event['txHash'] ?? null;
            if (!is_string($txHash) || $txHash === '') {
                continue;
            }
            $hashes[] = $txHash;
        }

        return array_values(array_unique($hashes));
    }

    public function scValToNative(XdrSCVal $value): mixed
    {
        return match ($value->getType()->value) {
            XdrSCValType::SCV_BOOL => $value->b,
            XdrSCValType::SCV_VOID => null,
            XdrSCValType::SCV_U32 => $value->u32,
            XdrSCValType::SCV_I32 => $value->i32,
            XdrSCValType::SCV_U64 => $value->u64 !== null ? (string) $value->u64 : null,
            XdrSCValType::SCV_I64 => $value->i64 !== null ? (string) $value->i64 : null,
            XdrSCValType::SCV_TIMEPOINT => $value->timepoint !== null ? (string) $value->timepoint : null,
            XdrSCValType::SCV_DURATION => $value->duration !== null ? (string) $value->duration : null,
            XdrSCValType::SCV_U128 => $value->u128 !== null ? ['hi' => $value->u128->hi, 'lo' => $value->u128->lo] : null,
            XdrSCValType::SCV_I128 => $value->i128 !== null ? ['hi' => $value->i128->hi, 'lo' => $value->i128->lo] : null,
            XdrSCValType::SCV_U256 => $value->u256 !== null ? ['hihi' => $value->u256->hiHi, 'hilo' => $value->u256->hiLo, 'lohi' => $value->u256->loHi, 'lolo' => $value->u256->loLo] : null,
            XdrSCValType::SCV_I256 => $value->i256 !== null ? ['hihi' => $value->i256->hiHi, 'hilo' => $value->i256->hiLo, 'lohi' => $value->i256->loHi, 'lolo' => $value->i256->loLo] : null,
            XdrSCValType::SCV_BYTES => $value->bytes !== null ? base64_encode($value->bytes->value) : null,
            XdrSCValType::SCV_STRING => $value->str,
            XdrSCValType::SCV_SYMBOL => $value->sym,
            XdrSCValType::SCV_ADDRESS => $this->addressToString($value),
            XdrSCValType::SCV_VEC => array_map(fn (XdrSCVal $v) => $this->scValToNative($v), $value->vec ?? []),
            XdrSCValType::SCV_MAP => $this->mapScValToNative($value->map ?? []),
            default => $value->toBase64Xdr(),
        };
    }

    /**
     * @param array<int,mixed> $topicDecoded
     */
    private function detectEventType(array $topicDecoded): string
    {
        foreach ($topicDecoded as $item) {
            if (!is_string($item)) {
                continue;
            }
            $value = strtolower($item);
            if (in_array($value, ['transfer', 'mint', 'burn', 'approve'], true)) {
                return $value;
            }
        }

        return 'unknown';
    }

    /**
     * @param mixed $value
     * @return array<int,string>
     */
    private function extractAddressesFromMixed(mixed $value): array
    {
        $result = [];
        $this->collectAddressesFromMixed($value, $result);
        return $result;
    }

    /**
     * @param mixed $value
     */
    private function extractAmountFromMixed(mixed $value): mixed
    {
        if (is_array($value)) {
            if (array_key_exists('amount', $value)) {
                return $this->extractAmountFromMixed($value['amount']);
            }

            // Preserve u128-style values so callers can normalize them precisely.
            if (
                array_key_exists('hi', $value)
                && array_key_exists('lo', $value)
                && (is_int($value['hi']) || is_string($value['hi']))
                && (is_int($value['lo']) || is_string($value['lo']))
            ) {
                return [
                    'hi' => (int) $value['hi'],
                    'lo' => (int) $value['lo'],
                ];
            }
            foreach ($value as $v) {
                $nested = $this->extractAmountFromMixed($v);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        if (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^-?[0-9]+$/', $value) === 1)) {
            return $value;
        }

        return null;
    }

    private function addressToString(XdrSCVal $value): ?string
    {
        try {
            if ($value->address === null) {
                return null;
            }

            return Address::fromXdr($value->address)->toStrKey();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<int,\Soneso\StellarSDK\Xdr\XdrSCMapEntry> $entries
     * @return array<string,mixed>
     */
    private function mapScValToNative(array $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            $keyNative = $this->scValToNative($entry->getKey());
            $valNative = $this->scValToNative($entry->getVal());
            $key = is_string($keyNative) ? $keyNative : json_encode($keyNative);
            if (!is_string($key) || $key === '') {
                $key = 'key_' . count($out);
            }
            $out[$key] = $valNative;
        }

        return $out;
    }

    /**
     * @param array<int,string> $result
     */
    private function collectAddressesFromMixed(mixed $value, array &$result): void
    {
        if (is_array($value)) {
            foreach ($value as $nested) {
                $this->collectAddressesFromMixed($nested, $result);
            }
            return;
        }

        if (is_string($value) && preg_match('/^[GC][A-Z2-7]{55}$/', $value) === 1) {
            $result[] = $value;
        }
    }
}
