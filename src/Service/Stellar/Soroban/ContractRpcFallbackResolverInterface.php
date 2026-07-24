<?php

declare(strict_types=1);

namespace App\Service\Stellar\Soroban;

interface ContractRpcFallbackResolverInterface
{
    /**
     * @return array{
     *     contractId:string,
     *     contractIdHex:string,
     *     wasmId:?string,
     *     executableType:int,
     *     isSac:bool
     * }|null
     */
    public function resolve(string $contractId, ?string $network = null): ?array;
}
