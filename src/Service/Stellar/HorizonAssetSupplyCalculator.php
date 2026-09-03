<?php

declare(strict_types=1);

namespace App\Service\Stellar;

final class HorizonAssetSupplyCalculator
{
    private const BALANCE_KEYS = [
        'authorized',
        'authorized_to_maintain_liabilities',
        'unauthorized',
        'claimable_balances',
        'liquidity_pools',
    ];

    /**
     * @param array<string,mixed> $balances
     */
    public function calculateStroops(array $balances, mixed $contractBalance = null): string
    {
        return $this->calculate($balances, $contractBalance, 0);
    }

    /**
     * @param array<string,mixed> $balances
     */
    public function calculateDecimalUnits(array $balances, mixed $contractBalance = null): string
    {
        return $this->calculate($balances, $contractBalance, 7);
    }

    /**
     * @param array<string,mixed> $balances
     */
    public function calculateStroopsFromDecimalUnits(array $balances, mixed $contractBalance = null): string
    {
        return bcmul($this->calculateDecimalUnits($balances, $contractBalance), '10000000', 0);
    }

    /**
     * @param array<string,mixed> $balances
     */
    private function calculate(array $balances, mixed $contractBalance, int $scale): string
    {
        $supply = '0';
        foreach (self::BALANCE_KEYS as $key) {
            $supply = bcadd($supply, $this->normalizeAmount($balances[$key] ?? null), $scale);
        }

        return bcadd($supply, $this->normalizeAmount($contractBalance), $scale);
    }

    private function normalizeAmount(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (!is_string($value) || preg_match('/^[0-9]+(?:\.[0-9]+)?$/', trim($value)) !== 1) {
            throw new \UnexpectedValueException('Horizon asset balance must be a non-negative decimal value.');
        }

        return trim($value);
    }
}
