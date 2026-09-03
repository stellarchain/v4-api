<?php

declare(strict_types=1);

namespace App\Tests\Service\Stellar;

use App\Service\Stellar\HorizonAssetSupplyCalculator;
use PHPUnit\Framework\TestCase;

final class HorizonAssetSupplyCalculatorTest extends TestCase
{
    private HorizonAssetSupplyCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new HorizonAssetSupplyCalculator();
    }

    public function testItIncludesAllHorizonBalanceLocationsInStroops(): void
    {
        $supply = $this->calculator->calculateStroops(
            [
                'authorized' => '55490635998',
                'authorized_to_maintain_liabilities' => '0',
                'unauthorized' => '0',
                'claimable_balances' => '3070000',
                'liquidity_pools' => '1233508800',
            ],
            '25893853296482'
        );

        self::assertSame('25950580511280', $supply);
    }

    public function testItIncludesAllHorizonBalanceLocationsInDecimalUnits(): void
    {
        $supply = $this->calculator->calculateDecimalUnits(
            [
                'authorized' => '5549.0635998',
                'authorized_to_maintain_liabilities' => '0.0000000',
                'unauthorized' => '0.0000000',
                'claimable_balances' => '0.3070000',
                'liquidity_pools' => '123.3508800',
            ],
            '2589385.3296482'
        );

        self::assertSame('2595058.0511280', $supply);
    }

    public function testItConvertsHorizonDecimalUnitsToStroopsForPersistence(): void
    {
        $supply = $this->calculator->calculateStroopsFromDecimalUnits(
            [
                'authorized' => '5549.0635998',
                'authorized_to_maintain_liabilities' => '0.0000000',
                'unauthorized' => '0.0000000',
                'claimable_balances' => '0.3070000',
                'liquidity_pools' => '123.3508800',
            ],
            '2589385.3296482'
        );

        self::assertSame('25950580511280', $supply);
    }

    public function testItTreatsMissingOptionalBalanceLocationsAsZero(): void
    {
        $supply = $this->calculator->calculateStroops([
            'authorized' => '10000000',
        ]);

        self::assertSame('10000000', $supply);
    }

    public function testItRejectsInvalidAmounts(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        $this->calculator->calculateStroops([
            'authorized' => 'invalid',
        ]);
    }
}
