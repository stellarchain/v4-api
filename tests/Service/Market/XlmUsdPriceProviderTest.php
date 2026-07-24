<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\Service\Market\XlmUsdPriceProvider;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class XlmUsdPriceProviderTest extends TestCase
{
    public function testLoadsLatestPriceOnlyOncePerProcess(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with(
                self::stringContains('FROM asset_metric_history'),
                [
                    'source' => 'coingecko_stellar',
                    'metric_key' => 'market_data.current_price.usd',
                ],
            )
            ->willReturn('0.123456789');

        $provider = new XlmUsdPriceProvider($connection);

        self::assertSame(0.123456789, $provider->latest());
        self::assertSame(0.123456789, $provider->latest());
    }

    public function testCachesMissingPrice(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->willReturn(false);

        $provider = new XlmUsdPriceProvider($connection);

        self::assertNull($provider->latest());
        self::assertNull($provider->latest());
    }

    public function testResetReloadsPriceForLongRunningWorkers(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls('0.10', '0.20');

        $provider = new XlmUsdPriceProvider($connection);

        self::assertSame(0.1, $provider->latest());
        $provider->reset();
        self::assertSame(0.2, $provider->latest());
    }
}
