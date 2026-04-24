<?php

namespace App\Command\Orders;

use App\Command\Support\NetworkOptionTrait;
use App\Entity\Order;
use App\Service\Orders\OrderEmailNotifier;
use App\Service\Orders\OrderMonitorAccountResolver;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:label-orders:sync',
    description: 'Single cron for orders: expire old pending orders and process incoming XLM payments for the monitored account.',
)]
final class SyncLabelOrdersCommand extends Command
{
    use NetworkOptionTrait;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private readonly Connection $connection,
        private readonly ManagerRegistry $doctrine,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
        private readonly OrderEmailNotifier $orderEmailNotifier,
        private readonly OrderMonitorAccountResolver $orderMonitorAccountResolver,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addNetworkOption('Horizon network (mainnet|testnet|futurenet)', 'mainnet')
            ->addOption('monitor-account', null, InputOption::VALUE_REQUIRED, 'Account that receives order payments (overrides env resolution)')
            ->addOption('required-amount-xlm', null, InputOption::VALUE_REQUIRED, 'Minimum XLM amount required to consider payment valid', '0')
            ->addOption('required-amount-usd', null, InputOption::VALUE_REQUIRED, 'Minimum USD amount required (converted to XLM using latest stored XLM/USD price)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Payments page limit', '200')
            ->addOption('cursor', null, InputOption::VALUE_REQUIRED, 'Override stored cursor')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not write DB updates');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $network = $this->resolveNetworkOption($input, $this->stellarNetworkResolver);
        $networkCode = $this->resolveNetworkCodeOption($input, $this->stellarNetworkResolver);

        $monitorAccount = trim((string) (
            $input->getOption('monitor-account')
            ?: $this->orderMonitorAccountResolver->resolveByNetwork($network)
        ));
        if ($monitorAccount === '') {
            $io->error('Missing monitor account. Set --monitor-account or STELLAR_ORDER_MONITOR_ACCOUNT_{NETWORK}.');
            return Command::FAILURE;
        }

        $requiredAmountXlm = (float) $input->getOption('required-amount-xlm');
        $requiredAmountUsdRaw = $input->getOption('required-amount-usd');
        $requiredAmountUsd = ($requiredAmountUsdRaw === null || $requiredAmountUsdRaw === '')
            ? null
            : (float) $requiredAmountUsdRaw;
        if ($requiredAmountXlm < 0) {
            $io->error('--required-amount-xlm must be >= 0.');
            return Command::FAILURE;
        }
        if ($requiredAmountUsd !== null && $requiredAmountUsd < 0) {
            $io->error('--required-amount-usd must be >= 0.');
            return Command::FAILURE;
        }
        if ($requiredAmountUsd !== null) {
            $xlmUsdPrice = $this->loadLatestXlmUsdPrice();
            if ($xlmUsdPrice === null || $xlmUsdPrice <= 0.0) {
                $io->error('Cannot resolve XLM/USD price from local DB. Run app:warm-coingecko-cache first.');

                return Command::FAILURE;
            }

            $requiredAmountXlm = $requiredAmountUsd / $xlmUsdPrice;
        }

        $limit = (int) $input->getOption('limit');
        if ($limit < 1 || $limit > 200) {
            $io->error('--limit must be between 1 and 200.');
            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $expiredUpdated = 0;

        if (!$dryRun) {
            $expiredUpdated = $this->connection->executeStatement(
                "UPDATE orders SET status = :expired, updated_at = :now WHERE network = :network AND status IN (:activeStatuses) AND expires_at <= :now",
                [
                    'expired' => Order::STATUS_EXPIRED,
                    'network' => $networkCode,
                    'activeStatuses' => [Order::STATUS_PENDING, Order::STATUS_AWAITING_VERIFICATION],
                    'now' => $now->format('Y-m-d H:i:s'),
                ],
                [
                    'network' => ParameterType::INTEGER,
                    'activeStatuses' => ArrayParameterType::STRING,
                ]
            );
        }

        $horizonConnection = $this->resolveHorizonConnection($network);
        $cursorOverride = $input->getOption('cursor');
        $cursor = is_string($cursorOverride) && trim($cursorOverride) !== ''
            ? trim($cursorOverride)
            : $this->loadCursor($monitorAccount, $network);

        if ($cursor === null) {
            $cursor = $this->initializeCursorFromHorizon($horizonConnection, $monitorAccount);
            if (!$dryRun) {
                $this->storeCursor($monitorAccount, $network, $cursor);
            }
            $io->success(sprintf('Cursor initialized to %s. Next cron run will process new incoming payments.', $cursor));
            $io->table(['Metric', 'Value'], [
                ['expired_orders_updated', (string) $expiredUpdated],
                ['payments_processed', '0'],
                ['orders_completed', '0'],
                ['orders_awaiting_verification', '0'],
                ['accounts_labeled', '0'],
            ]);
            return Command::SUCCESS;
        }

        $paymentsProcessed = 0;
        $ordersCompleted = 0;
        $ordersAwaiting = 0;
        $accountsLabeled = 0;
        $lastOperationId = null;
        $cursorId = ctype_digit($cursor) ? (int) $cursor : 0;

        while (true) {
            $operations = $this->fetchIncomingNativePaymentsFromHorizon(
                $horizonConnection,
                $monitorAccount,
                $cursorId,
                $limit
            );
            if ($operations === []) {
                break;
            }

            foreach ($operations as $operation) {
                $operationId = (int) ($operation['operation_id'] ?? 0);
                if ($operationId > 0) {
                    $lastOperationId = $operationId;
                    $cursorId = $operationId;
                }

                $sourceAccount = trim((string) ($operation['source_account'] ?? ''));
                if ($sourceAccount === '') {
                    continue;
                }

                $amount = (float) ($operation['amount'] ?? 0);
                if ($amount < $requiredAmountXlm) {
                    continue;
                }
                $paymentTxHash = trim((string) ($operation['transaction_hash'] ?? ''));

                $paymentsProcessed++;
                $activeOrder = $this->findActiveOrder($sourceAccount, $networkCode, $now);
                if ($activeOrder === null) {
                    continue;
                }

                $newStatus = $activeOrder['order_type'] === 'user_defined'
                    ? Order::STATUS_COMPLETED
                    : Order::STATUS_AWAITING_VERIFICATION;
                $currentStatus = (string) ($activeOrder['status'] ?? '');
                $currentPaymentTxHash = (string) ($activeOrder['payment_tx_hash'] ?? '');
                if ($currentStatus === $newStatus && $currentPaymentTxHash === $paymentTxHash) {
                    continue;
                }

                if (!$dryRun) {
                    $this->connection->executeStatement(
                        'UPDATE orders SET status = :status, payment_tx_hash = :payment_tx_hash, updated_at = :now WHERE id = :id',
                        [
                            'status' => $newStatus,
                            'payment_tx_hash' => $paymentTxHash !== '' ? $paymentTxHash : null,
                            'now' => $now->format('Y-m-d H:i:s'),
                            'id' => (int) $activeOrder['id'],
                        ],
                        [
                            'id' => ParameterType::INTEGER,
                        ]
                    );

                    if ($newStatus === Order::STATUS_COMPLETED) {
                        if ($this->updateExistingAccountLabel($sourceAccount, $networkCode, (string) $activeOrder['label_name'], $now)) {
                            $accountsLabeled++;
                        }
                    }

                    if ($currentStatus === Order::STATUS_PENDING) {
                        $updatedOrder = $this->hydrateOrderFromRow($activeOrder, $newStatus, $paymentTxHash, $now, $networkCode);
                        if ($newStatus === Order::STATUS_COMPLETED) {
                            $this->orderEmailNotifier->sendOrderCompleted($updatedOrder);
                        } elseif ($newStatus === Order::STATUS_AWAITING_VERIFICATION) {
                            $this->orderEmailNotifier->sendOrderAwaitingVerification($updatedOrder);
                            $this->orderEmailNotifier->sendOrderAwaitingVerificationToAdmin($updatedOrder);
                        }
                    }
                }

                if ($newStatus === Order::STATUS_COMPLETED) {
                    $ordersCompleted++;
                } else {
                    $ordersAwaiting++;
                }
            }

            if (count($operations) < $limit) {
                break;
            }
        }

        if (!$dryRun && $lastOperationId !== null) {
            $this->storeCursor($monitorAccount, $network, (string) $lastOperationId);
        }

        $io->table(['Metric', 'Value'], [
            ['expired_orders_updated', (string) $expiredUpdated],
            ['payments_processed', (string) $paymentsProcessed],
            ['orders_completed', (string) $ordersCompleted],
            ['orders_awaiting_verification', (string) $ordersAwaiting],
            ['accounts_labeled', (string) $accountsLabeled],
        ]);

        $io->success($dryRun ? 'Orders sync dry-run completed.' : 'Orders sync completed.');

        return Command::SUCCESS;
    }

    private function findActiveOrder(string $accountAddress, int $networkCode, \DateTimeImmutable $now): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, uuid, account_address, network, order_type, label_name, email, status, payment_tx_hash, expires_at, created_at FROM orders WHERE account_address = :account AND network = :network AND status IN (:statuses) AND expires_at > :now ORDER BY id DESC LIMIT 1',
            [
                'account' => $accountAddress,
                'network' => $networkCode,
                'statuses' => [Order::STATUS_PENDING, Order::STATUS_AWAITING_VERIFICATION],
                'now' => $now->format('Y-m-d H:i:s'),
            ],
            [
                'network' => ParameterType::INTEGER,
                'statuses' => ArrayParameterType::STRING,
            ]
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function hydrateOrderFromRow(
        array $row,
        string $status,
        string $paymentTxHash,
        \DateTimeImmutable $now,
        int $networkCode
    ): Order {
        $createdAtValue = $row['created_at'] ?? null;
        $expiresAtValue = $row['expires_at'] ?? null;
        $createdAt = $createdAtValue instanceof \DateTimeInterface
            ? \DateTimeImmutable::createFromInterface($createdAtValue)
            : new \DateTimeImmutable((string) ($createdAtValue ?: $now->format('Y-m-d H:i:s')), new \DateTimeZone('UTC'));
        $expiresAt = $expiresAtValue instanceof \DateTimeInterface
            ? \DateTimeImmutable::createFromInterface($expiresAtValue)
            : new \DateTimeImmutable((string) ($expiresAtValue ?: $now->format('Y-m-d H:i:s')), new \DateTimeZone('UTC'));

        return (new Order())
            ->setUuid((string) ($row['uuid'] ?? ''))
            ->setAccountAddress((string) ($row['account_address'] ?? ''))
            ->setNetwork((int) ($row['network'] ?? $networkCode))
            ->setLabelName((string) ($row['label_name'] ?? ''))
            ->setOrderType((string) ($row['order_type'] ?? ''))
            ->setStatus($status)
            ->setEmail((string) ($row['email'] ?? ''))
            ->setPaymentTxHash($paymentTxHash !== '' ? $paymentTxHash : null)
            ->setExpiresAt($expiresAt)
            ->setCreatedAt($createdAt)
            ->setUpdatedAt($now);
    }

    private function updateExistingAccountLabel(string $address, int $networkCode, string $label, \DateTimeImmutable $now): bool
    {
        if ($label === '') {
            return false;
        }

        $updatedRows = $this->connection->executeStatement(
            'UPDATE account SET label = :label, updated_at = :updatedAt WHERE address = :address AND network = :network',
            [
                'address' => $address,
                'network' => $networkCode,
                'label' => $label,
                'updatedAt' => $now->format('Y-m-d H:i:s'),
            ],
            [
                'network' => ParameterType::INTEGER,
            ]
        );

        return $updatedRows > 0;
    }

    private function loadCursor(string $monitorAccount, string $network): ?string
    {
        $key = $this->cursorCacheKey($monitorAccount, $network);
        $item = $this->cache->getItem($key);
        if (!$item->isHit()) {
            return null;
        }

        $value = trim((string) $item->get());

        return $value !== '' ? $value : null;
    }

    private function storeCursor(string $monitorAccount, string $network, string $cursor): void
    {
        $key = $this->cursorCacheKey($monitorAccount, $network);
        $item = $this->cache->getItem($key);
        $item->set($cursor);
        $this->cache->save($item);
    }

    private function cursorCacheKey(string $monitorAccount, string $network): string
    {
        return sprintf(
            'orders_sync_cursor_%s',
            hash('sha256', strtolower(trim($monitorAccount)) . ':' . strtolower(trim($network)))
        );
    }

    private function resolveHorizonConnection(string $network): Connection
    {
        $connectionName = match ($network) {
            'mainnet' => 'horizon_mainnet',
            'testnet' => 'horizon_testnet',
            default => 'horizon_testnet',
        };

        return $this->doctrine->getConnection($connectionName);
    }

    private function initializeCursorFromHorizon(Connection $horizonConnection, string $monitorAccount): string
    {
        $latest = $horizonConnection->fetchOne(
            <<<SQL
SELECT COALESCE(MAX(ho.id), 0)
FROM history_operations ho
WHERE ho.type IN (1, 2, 13)
  AND COALESCE(ho.details->>'to', ho.details->>'account', '') = :monitor
SQL,
            ['monitor' => $monitorAccount]
        );

        return (string) max(0, (int) $latest);
    }

    /**
     * @return list<array{operation_id:int,source_account:string,amount:string,transaction_hash:string}>
     */
    private function fetchIncomingNativePaymentsFromHorizon(
        Connection $horizonConnection,
        string $monitorAccount,
        int $afterOperationId,
        int $limit
    ): array {
        $rows = $horizonConnection->fetchAllAssociative(
            <<<SQL
SELECT
    ho.id AS operation_id,
    COALESCE(NULLIF(ho.source_account, ''), ht.account, '') AS source_account,
    COALESCE(ho.details->>'amount', ho.details->>'starting_balance', '0') AS amount,
    ht.transaction_hash
FROM history_operations ho
INNER JOIN history_transactions ht ON ht.id = ho.transaction_id
WHERE ho.id > :after_operation_id
  AND ho.type IN (1, 2, 13)
  AND ht.successful = TRUE
  AND COALESCE(ho.details->>'to', ho.details->>'account', '') = :monitor
  AND (
    ho.type = 2
    OR COALESCE(ho.details->>'asset_type', 'native') = 'native'
  )
ORDER BY ho.id ASC
LIMIT :limit_rows
SQL,
            [
                'after_operation_id' => $afterOperationId,
                'monitor' => $monitorAccount,
                'limit_rows' => $limit,
            ],
            [
                'after_operation_id' => ParameterType::INTEGER,
                'limit_rows' => ParameterType::INTEGER,
            ]
        );

        return array_map(
            static fn (array $row): array => [
                'operation_id' => (int) ($row['operation_id'] ?? 0),
                'source_account' => (string) ($row['source_account'] ?? ''),
                'amount' => (string) ($row['amount'] ?? '0'),
                'transaction_hash' => (string) ($row['transaction_hash'] ?? ''),
            ],
            $rows
        );
    }

    private function loadLatestXlmUsdPrice(): ?float
    {
        $value = $this->connection->fetchOne(
            <<<SQL
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

}
