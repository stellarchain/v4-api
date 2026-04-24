<?php

declare(strict_types=1);

namespace App\Service\Orders;

use App\Entity\Order;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OrderPaymentStreamValidator
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ManagerRegistry $doctrine,
        private readonly OrderMonitorAccountResolver $orderMonitorAccountResolver,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
    ) {
    }

    private const REQUIRED_USD_AMOUNT = 99.0;
    private const REQUIRED_USD_DISCOUNT = 5.0;

    /**
     * @return array{valid:bool,reason:?string,details?:array<string,mixed>}
     */
    public function validate(Order $order, string $network, string $paymentTxHash, int $paymentEventId): array
    {
        $monitorAccount = trim($this->orderMonitorAccountResolver->resolveByNetwork($network));
        if ($monitorAccount === '') {
            return $this->error('missing_monitor_account');
        }

        $operation = $this->fetchHorizonOperation($network, $paymentEventId);
        if ($operation === null) {
            return $this->error('payment_event_not_found');
        }

        if (($operation['transaction_hash'] ?? '') !== $paymentTxHash) {
            return $this->error('transaction_hash_mismatch');
        }

        if (($operation['from'] ?? '') !== $order->getAccountAddress()) {
            return $this->error('source_account_mismatch');
        }

        if (($operation['to'] ?? '') !== $monitorAccount) {
            return $this->error('monitor_account_mismatch');
        }

        if (!in_array(strtolower((string) ($operation['asset_type'] ?? 'native')), ['native'], true)) {
            return $this->error('non_native_payment');
        }

        if (($operation['transaction_successful'] ?? true) === false) {
            return $this->error('transaction_not_successful');
        }

        $amountXlm = $this->parseAmountAsFloat((string) ($operation['amount'] ?? '0'));
        if ($amountXlm <= 0.0) {
            return $this->error('payment_amount_invalid');
        }

        $requiredXlm = $this->requiredAmountInXlm();
        if ($requiredXlm === null) {
            return $this->error('missing_price_data');
        }
        if ($amountXlm < $requiredXlm) {
            return $this->error('insufficient_amount');
        }

        return [
            'valid' => true,
            'reason' => null,
            'details' => [
                'amount_xlm' => $amountXlm,
                'required_amount_xlm' => $requiredXlm,
            ],
        ];
    }

    private function parseAmountAsFloat(string $raw): float
    {
        $normalized = str_replace(',', '.', trim($raw));
        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }

    private function requiredAmountInXlm(): ?float
    {
        $price = $this->loadLatestXlmUsdPrice();
        if ($price === null || $price <= 0.0) {
            return null;
        }

        $effectiveUsdAmount = max(0.0, self::REQUIRED_USD_AMOUNT - self::REQUIRED_USD_DISCOUNT);

        return $effectiveUsdAmount / $price;
    }

    private function loadLatestXlmUsdPrice(): ?float
    {
        $connection = $this->doctrine->getConnection();
        $value = $connection->fetchOne(
            <<<'SQL'
SELECT amh.value_decimal
FROM asset_metric_history amh
INNER JOIN asset a ON a.id = amh.asset_id
WHERE amh.source = 'coingecko_stellar'
  AND amh.metric_key = 'market_data.current_price.usd'
  AND a.is_native = 1
ORDER BY amh.recorded_at DESC
LIMIT 1
SQL
        );

        if ($value === false || $value === null || !is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function fetchHorizonOperation(string $network, int $operationId): ?array
    {
        $baseUrl = rtrim($this->stellarNetworkResolver->resolveHorizonUrl($network), '/');
        try {
            $response = $this->httpClient->request('GET', sprintf('%s/operations/%d', $baseUrl, $operationId), [
                'timeout' => 4.0,
            ]);
            return $response->toArray(false);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{valid:bool,reason:?string,details?:array<string,mixed>}
     */
    private function error(string $reason): array
    {
        return [
            'valid' => false,
            'reason' => $reason,
        ];
    }
}
