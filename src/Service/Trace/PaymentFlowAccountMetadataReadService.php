<?php

declare(strict_types=1);

namespace App\Service\Trace;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class PaymentFlowAccountMetadataReadService implements PaymentFlowAccountMetadataReadServiceInterface
{
    private const MAX_ADDRESSES = 250;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param list<string> $addresses
     * @return array<string,array<string,mixed>>
     */
    public function readByAddresses(int $networkCode, array $addresses): array
    {
        $addresses = $this->normalizeAddresses($addresses);
        if ($addresses === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT
    a.address,
    a.label,
    a.verified,
    a.created_at,
    a.updated_at,
    am.first_transaction_at,
    am.last_transaction_at,
    am.total_transactions,
    am.payments_count,
    am.trades_count,
    am.native_balance,
    am.rank_position,
    am.rank_score,
    am.metric_updated_at
FROM account a
LEFT JOIN account_metric am ON am.account_id = a.id
WHERE a.network = :network
  AND a.address IN (:addresses)
SQL,
            [
                'network' => $networkCode,
                'addresses' => $addresses,
            ],
            [
                'network' => ParameterType::INTEGER,
                'addresses' => ArrayParameterType::STRING,
            ]
        );

        $metadata = [];
        foreach ($rows as $row) {
            $address = $this->normalizeAddress($row['address'] ?? null);
            if ($address === null) {
                continue;
            }

            $metadata[$address] = [
                'address' => $address,
                'known' => true,
                'label' => $this->normalizeNullableString($row['label'] ?? null),
                'verified' => $this->normalizeBool($row['verified'] ?? null),
                'orgName' => null,
                'createdAt' => $this->formatAtom($row['created_at'] ?? null),
                'updatedAt' => $this->formatAtom($row['updated_at'] ?? null),
                'firstTransactionAt' => $this->formatAtom($row['first_transaction_at'] ?? null),
                'lastTransactionAt' => $this->formatAtom($row['last_transaction_at'] ?? null),
                'totalTransactions' => $this->normalizeIntegerString($row['total_transactions'] ?? null),
                'paymentsCount' => $this->normalizeIntegerString($row['payments_count'] ?? null),
                'tradesCount' => $this->normalizeIntegerString($row['trades_count'] ?? null),
                'nativeBalance' => $this->normalizeDecimalString($row['native_balance'] ?? null),
                'rankPosition' => $row['rank_position'] === null ? null : (int) $row['rank_position'],
                'rankScore' => $this->normalizeDecimalString($row['rank_score'] ?? null),
                'metricUpdatedAt' => $this->formatAtom($row['metric_updated_at'] ?? null),
            ];
        }

        return $metadata;
    }

    /**
     * @param list<string> $addresses
     * @return list<string>
     */
    private function normalizeAddresses(array $addresses): array
    {
        $normalized = [];
        foreach ($addresses as $address) {
            $address = $this->normalizeAddress($address);
            if ($address !== null) {
                $normalized[$address] = true;
            }
        }

        return array_slice(array_keys($normalized), 0, self::MAX_ADDRESSES);
    }

    private function normalizeAddress(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = strtoupper(trim($value));

        return $value === '' ? null : $value;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function normalizeBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes'], true);
        }

        return false;
    }

    private function normalizeIntegerString(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        return (string) (int) $value;
    }

    private function normalizeDecimalString(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        return (string) $value;
    }

    private function formatAtom(mixed $value): ?string
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable(trim($value), new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format(\DateTimeInterface::ATOM);
        } catch (\Throwable) {
            return null;
        }
    }
}
