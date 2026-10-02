<?php

declare(strict_types=1);

namespace App\Service\Trace;

interface PaymentFlowInvestigationReadServiceInterface
{
    /**
     * @return array<string,mixed>
     */
    public function read(
        string $network,
        ?string $address,
        ?string $txHash,
        ?int $ledgerFrom,
        ?int $ledgerTo,
        string $direction,
        int $limit,
        ?string $cursor = null,
        ?string $operationType = null,
        ?string $asset = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $minAssetAmount = null
    ): array;
}
