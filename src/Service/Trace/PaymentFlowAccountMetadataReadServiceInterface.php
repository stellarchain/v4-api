<?php

declare(strict_types=1);

namespace App\Service\Trace;

interface PaymentFlowAccountMetadataReadServiceInterface
{
    /**
     * @param list<string> $addresses
     * @return array<string,array<string,mixed>>
     */
    public function readByAddresses(int $networkCode, array $addresses): array;
}
