<?php

declare(strict_types=1);

namespace App\Service\Market;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

final class XlmUsdPriceProvider implements ResetInterface
{
    private bool $loaded = false;
    private ?float $latestPrice = null;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private readonly Connection $connection,
    ) {
    }

    public function latest(): ?float
    {
        if ($this->loaded) {
            return $this->latestPrice;
        }

        $this->loaded = true;
        $value = $this->connection->fetchOne(
            <<<'SQL'
SELECT value_decimal
FROM asset_metric_history
WHERE source = :source
  AND metric_key = :metric_key
ORDER BY recorded_at DESC
LIMIT 1
SQL,
            [
                'source' => 'coingecko_stellar',
                'metric_key' => 'market_data.current_price.usd',
            ],
        );

        if ($value === false || $value === null || !is_numeric($value)) {
            return null;
        }

        return $this->latestPrice = (float) $value;
    }

    public function reset(): void
    {
        $this->loaded = false;
        $this->latestPrice = null;
    }
}
