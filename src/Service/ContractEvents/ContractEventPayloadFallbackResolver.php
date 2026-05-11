<?php

declare(strict_types=1);

namespace App\Service\ContractEvents;

use App\Service\Stellar\Soroban\ContractEventsScanner;
use App\Service\Stellar\Soroban\SorobanServerFactory;

final class ContractEventPayloadFallbackResolver
{
    public function __construct(
        private readonly SorobanServerFactory $sorobanServerFactory,
        private readonly ContractEventsScanner $contractEventsScanner,
    ) {
    }

    /**
     * @return array{
     *   topicDecoded:array<int,mixed>,
     *   valueDecoded:mixed,
     *   addresses:array<int,string>,
     *   amountRaw:?string,
     *   eventType:?string
     * }|null
     */
    public function resolve(
        string $contractId,
        ?string $network,
        string $txHash,
        int $eventIndex,
        ?int $ledger,
    ): ?array {
        $normalizedContractId = strtoupper(trim($contractId));
        $normalizedTxHash = trim($txHash);
        if ($normalizedContractId === '' || $normalizedTxHash === '' || $eventIndex < 1 || !is_int($ledger) || $ledger < 1) {
            return null;
        }

        try {
            $server = $this->sorobanServerFactory->create($network);
            $scan = $this->contractEventsScanner->scanContractTransactions(
                $server,
                $normalizedContractId,
                $ledger,
                $ledger,
            );
        } catch (\Throwable) {
            return null;
        }

        if (($scan['ok'] ?? false) !== true) {
            return null;
        }

        $events = is_array($scan['events'] ?? null) ? $scan['events'] : [];
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            if (trim((string) ($event['txHash'] ?? '')) !== $normalizedTxHash) {
                continue;
            }
            if ((int) ($event['eventIndex'] ?? 0) !== $eventIndex) {
                continue;
            }

            return [
                'topicDecoded' => is_array($event['topicDecoded'] ?? null) ? $event['topicDecoded'] : [],
                'valueDecoded' => $event['valueDecoded'] ?? null,
                'addresses' => is_array($event['addresses'] ?? null) ? $event['addresses'] : [],
                'amountRaw' => isset($event['amountRaw']) && is_string($event['amountRaw']) ? $event['amountRaw'] : null,
                'eventType' => isset($event['eventType']) && is_string($event['eventType']) ? $event['eventType'] : null,
            ];
        }

        return null;
    }
}

