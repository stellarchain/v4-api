<?php

namespace App\DataProvider;

use ApiPlatform\DependencyInjection\Attribute\AsTaggedItem;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Account;
use App\Entity\AccountMetric;
use App\Repository\AccountRepository;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\ORM\EntityManagerInterface;
use Soneso\StellarSDK\Crypto\StrKey;
use Soneso\StellarSDK\Exceptions\HorizonRequestException;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\Responses\Account\AccountBalanceResponse;
use Soneso\StellarSDK\StellarSDK;

#[AsTaggedItem('api_platform.state.provider')]
final class AccountItemDataProvider implements ProviderInterface
{
    public function __construct(
        private readonly AccountRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?Account
    {
        $address = $uriVariables['address'] ?? $uriVariables['id'] ?? null;
        if (!is_string($address) || trim($address) === '') {
            return null;
        }

        $address = trim($address);
        if (!StrKey::isValidAccountId($address)) {
            return null;
        }

        $network = $this->resolveNetwork($context);
        $networkCode = $this->stellarNetworkResolver->resolveNetworkCode($network) ?? 1;
        $sdk = new StellarSDK($this->stellarNetworkResolver->resolveHorizonUrl($network));

        $account = $this->repository->findOneByAddressAndNetwork($address, $networkCode);

        try {
            $horizonAccount = $sdk->requestAccount($address);
        } catch (HorizonRequestException $exception) {
            if (in_array($exception->getStatusCode(), [400, 404], true)) {
                if ($account instanceof Account) {
                    $this->attachLocalAccountData($account, $networkCode);
                }
                return $account;
            }
            throw $exception;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $isNew = false;

        if ($account === null) {
            $account = (new Account())
                ->setAddress($address)
                ->setNetwork($networkCode)
                ->setVerified(false)
                ->setCreatedAt($now)
                ->setUpdatedAt($now);
            $isNew = true;
        } else {
            $account->setUpdatedAt($now);
        }

        $this->hydrateAccountFromHorizon($account, $horizonAccount, $network, $networkCode);

        if ($isNew) {
            $this->entityManager->persist($account);
        }
        $this->entityManager->flush();

        return $account;
    }

    public function enrichExistingAccountFromHorizon(Account $account, string $network): void
    {
        $address = trim((string) $account->getAddress());
        if ($address === '' || !StrKey::isValidAccountId($address)) {
            return;
        }

        $networkCode = $this->stellarNetworkResolver->resolveNetworkCode($network) ?? 1;
        $sdk = new StellarSDK($this->stellarNetworkResolver->resolveHorizonUrl($network));
        try {
            $horizonAccount = $sdk->requestAccount($address);
        } catch (HorizonRequestException $exception) {
            if (in_array($exception->getStatusCode(), [400, 404], true)) {
                return;
            }
            throw $exception;
        }

        $account->setUpdatedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->hydrateAccountFromHorizon($account, $horizonAccount, $network, $networkCode);
        $this->entityManager->flush();
    }

    private function hydrateAccountFromHorizon(Account $account, AccountResponse $horizonAccount, string $network, int $networkCode): void
    {
        $address = trim((string) $account->getAddress());

        $nativeBalance = '0.0000000';
        $balances = [];
        foreach ($horizonAccount->getBalances() as $balance) {
            \assert($balance instanceof AccountBalanceResponse);
            if ($balance->getAssetType() === 'native') {
                $nativeBalance = $balance->getBalance();
            }

            $balances[] = [
                'assetType' => $balance->getAssetType(),
                'assetCode' => $balance->getAssetCode(),
                'assetIssuer' => $balance->getAssetIssuer(),
                'balance' => $balance->getBalance(),
                'buyingLiabilities' => $balance->getBuyingLiabilities(),
                'sellingLiabilities' => $balance->getSellingLiabilities(),
                'limit' => $balance->getLimit(),
            ];
        }

        $metric = $account->getAccountMetric();
        if ($metric === null) {
            $metric = (new AccountMetric())
                ->setAccount($account)
                ->setTransactionsPerHour('0');
        }
        $metric->setNativeBalance($nativeBalance);

        $account->setStellarData([
            'network' => $network,
            'accountId' => $horizonAccount->getAccountId(),
            'sequence' => (string) $horizonAccount->getSequenceNumber(),
            'subentryCount' => $horizonAccount->getSubentryCount(),
            'lastModifiedLedger' => $this->safeNullableInt(fn (): mixed => $horizonAccount->getLastModifiedLedger()),
            'lastModifiedTime' => $this->safeNullableString(fn (): mixed => $horizonAccount->getLastModifiedTime()),
            'numSponsoring' => $this->safeNullableInt(fn (): mixed => $horizonAccount->getNumSponsoring()),
            'numSponsored' => $this->safeNullableInt(fn (): mixed => $horizonAccount->getNumSponsored()),
            'balances' => $balances,
        ]);

        $this->entityManager->persist($metric);
        $this->attachLocalAccountData($account, $networkCode, $nativeBalance);
    }

    private function attachLocalAccountData(Account $account, int $networkCode, ?string $nativeBalance = null): void
    {
        $address = trim((string) $account->getAddress());
        if ($address === '') {
            return;
        }

        $stellarData = $account->getStellarData();
        if (!is_array($stellarData)) {
            $stellarData = [];
        }

        $balanceForActivity = is_string($nativeBalance) && $nativeBalance !== ''
            ? $nativeBalance
            : $this->loadCurrentNativeBalanceByAccountId($account->getId());
        $account->setActivity24h($this->loadActivity24h($account->getId(), $balanceForActivity));
        $account->setStellarData($stellarData);

        $tokens = $this->loadRelatedAssets($address, $networkCode);
        $account->setAssets($tokens);
        $account->setContracts(null);
    }

    private function loadCurrentNativeBalanceByAccountId(?int $accountId): string
    {
        if (!is_int($accountId) || $accountId <= 0) {
            return '0.0000000';
        }

        $value = $this->entityManager->getConnection()->fetchOne(
            'SELECT native_balance FROM account_metric WHERE account_id = :account_id LIMIT 1',
            ['account_id' => $accountId]
        );

        if ($value === false || $value === null || $value === '') {
            return '0.0000000';
        }

        return (string) $value;
    }

    private function resolveNetwork(array $context): string
    {
        $network = $context['filters']['network'] ?? null;
        if (!is_string($network) || trim($network) === '') {
            return 'mainnet';
        }

        return $this->stellarNetworkResolver->normalizeNetwork($network);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadActivity24h(?int $accountId, string $currentNativeBalance): ?array
    {
        if (!is_int($accountId) || $accountId <= 0) {
            return null;
        }

        $from = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->sub(new \DateInterval('PT24H'));
        $row = $this->entityManager->getConnection()->fetchAssociative(
            <<<SQL
SELECT
    COALESCE(SUM(total_transactions), 0) AS total_transactions_24h,
    COALESCE(SUM(payment_operations), 0) AS payment_operations_24h,
    COALESCE(SUM(trade_operations), 0) AS trade_operations_24h,
    COALESCE(SUM(asset_transactions), 0) AS asset_transactions_24h,
    COALESCE(SUM(contract_transactions), 0) AS contract_transactions_24h,
    COALESCE(SUM(successful_transactions), 0) AS successful_transactions_24h,
    COALESCE(SUM(failed_transactions), 0) AS failed_transactions_24h,
    COALESCE(SUM(operation_count), 0) AS operation_count_24h,
    COALESCE(SUM(fee_charged_sum), 0) AS fee_charged_stroops_24h,
    COALESCE(SUM(max_fee_sum), 0) AS max_fee_stroops_24h,
    MAX(interval_end) AS latest_interval_end_24h
FROM account_metric_interval
WHERE account_id = :account_id
  AND interval_end > :from_dt
SQL,
            [
                'account_id' => $accountId,
                'from_dt' => $from->format('Y-m-d H:i:s'),
            ]
        );

        if (!is_array($row)) {
            return null;
        }

        $totalTransactions = (int) ($row['total_transactions_24h'] ?? 0);
        $successfulTransactions = (int) ($row['successful_transactions_24h'] ?? 0);
        $feeChargedStroops = (string) ($row['fee_charged_stroops_24h'] ?? '0');
        $balanceChange = $this->loadBalanceChange24h($accountId, $currentNativeBalance);

        return [
            'totalTransactions' => $totalTransactions,
            'paymentOperations' => (int) ($row['payment_operations_24h'] ?? 0),
            'tradeOperations' => (int) ($row['trade_operations_24h'] ?? 0),
            'assetTransactions' => (int) ($row['asset_transactions_24h'] ?? 0),
            'contractTransactions' => (int) ($row['contract_transactions_24h'] ?? 0),
            'successfulTransactions' => $successfulTransactions,
            'failedTransactions' => (int) ($row['failed_transactions_24h'] ?? 0),
            'operationCount' => (int) ($row['operation_count_24h'] ?? 0),
            'successRatePercent' => $totalTransactions > 0
                ? round(($successfulTransactions / $totalTransactions) * 100, 2)
                : null,
            'feeChargedStroops' => $feeChargedStroops,
            'feeChargedXlm' => $this->stroopsToXlm($feeChargedStroops),
            'maxFeeStroops' => (string) ($row['max_fee_stroops_24h'] ?? '0'),
            'latestIntervalEnd' => is_string($row['latest_interval_end_24h'] ?? null)
                ? (string) $row['latest_interval_end_24h']
                : null,
            'nativeBalanceChange24h' => $balanceChange,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadBalanceChange24h(int $accountId, string $currentNativeBalance): ?array
    {
        $target = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->sub(new \DateInterval('PT24H'))
            ->format('Y-m-d H:i:s');

        $reference = $this->entityManager->getConnection()->fetchAssociative(
            <<<SQL
SELECT native_balance, recorded_hour
FROM account_balance_snapshot
WHERE account_id = :account_id
  AND recorded_hour <= :target_dt
ORDER BY recorded_hour DESC
LIMIT 1
SQL,
            [
                'account_id' => $accountId,
                'target_dt' => $target,
            ]
        );

        if (!is_array($reference)) {
            return null;
        }

        $current = $this->decimalToFloat($currentNativeBalance);
        $previous = $this->decimalToFloat((string) ($reference['native_balance'] ?? '0'));
        $delta = $current - $previous;
        $deltaPercent = $previous > 0.0 ? ($delta / $previous) * 100 : null;

        return [
            'currentXlm' => number_format($current, 7, '.', ''),
            'referenceXlm' => number_format($previous, 7, '.', ''),
            'changeXlm' => number_format($delta, 7, '.', ''),
            'changePercent' => $deltaPercent !== null ? round($deltaPercent, 4) : null,
            'referenceRecordedHour' => is_string($reference['recorded_hour'] ?? null)
                ? (string) $reference['recorded_hour']
                : null,
        ];
    }

    private function decimalToFloat(string $value): float
    {
        $normalized = trim(str_replace(',', '.', $value));

        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }

    private function safeNullableString(callable $resolver): ?string
    {
        try {
            $value = $resolver();
        } catch (\Throwable) {
            return null;
        }

        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function safeNullableInt(callable $resolver): ?int
    {
        try {
            $value = $resolver();
        } catch (\Throwable) {
            return null;
        }

        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function stroopsToXlm(string $stroops): string
    {
        $normalized = trim($stroops);
        if ($normalized === '' || !is_numeric($normalized)) {
            return '0.0000000';
        }

        $value = ((float) $normalized) / 10000000;

        return number_format($value, 7, '.', '');
    }

    /**
     * @return array<int,array<string,mixed>>|null
     */
    private function loadRelatedAssets(string $address, int $networkCode): ?array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            <<<SQL
SELECT id, asset_key, code, issuer, is_native, network, created_at, updated_at
FROM asset
WHERE issuer = :issuer
  AND network = :network
ORDER BY id DESC
LIMIT 200
SQL,
            [
                'issuer' => $address,
                'network' => $networkCode,
            ]
        );

        if ($rows === []) {
            return null;
        }

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'assetKey' => (string) $row['asset_key'],
            'code' => (string) $row['code'],
            'issuer' => $row['issuer'] !== null ? (string) $row['issuer'] : null,
            'isNative' => (bool) $row['is_native'],
            'network' => (int) $row['network'],
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
        ], $rows);
    }

}
