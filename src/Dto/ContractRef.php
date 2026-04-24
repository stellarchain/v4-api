<?php

namespace App\Dto;

final readonly class ContractRef
{
    public function __construct(
        public int $id,
        public string $contractId,
    ) {
    }
}

