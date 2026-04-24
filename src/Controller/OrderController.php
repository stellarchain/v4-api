<?php

namespace App\Controller;

use App\Entity\Account;
use App\Entity\Order;
use App\Repository\AccountRepository;
use App\Repository\OrderRepository;
use App\Service\Orders\OrderEmailNotifier;
use App\Service\Orders\OrderMonitorAccountResolver;
use App\Service\Orders\OrderPaymentStreamValidator;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\ORM\EntityManagerInterface;
use Soneso\StellarSDK\Crypto\StrKey;
use Soneso\StellarSDK\Exceptions\HorizonRequestException;
use Soneso\StellarSDK\StellarSDK;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class OrderController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountRepository $accountRepository,
        private readonly OrderRepository $orderRepository,
        private readonly OrderEmailNotifier $orderEmailNotifier,
        private readonly OrderMonitorAccountResolver $orderMonitorAccountResolver,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
        private readonly OrderPaymentStreamValidator $orderPaymentStreamValidator,
    ) {
    }

    #[Route('/v1/orders', name: 'orders_store', methods: ['POST'])]
    public function store(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->error('Invalid JSON payload.', Response::HTTP_BAD_REQUEST);
        }

        $networkInput = $payload['network'] ?? $request->query->get('network');
        $network = $this->stellarNetworkResolver->normalizeNetwork(is_string($networkInput) ? $networkInput : null);
        $networkCode = $this->stellarNetworkResolver->resolveNetworkCode($network) ?? 1;
        $account = trim((string) ($payload['account'] ?? ''));
        $labelName = trim((string) ($payload['label'] ?? ''));
        $orderType = trim((string) ($payload['order_type'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));

        if ($account === '' || !StrKey::isValidAccountId($account)) {
            return $this->error('account must be a valid Stellar account address.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($labelName === '' || mb_strlen($labelName) > 50) {
            return $this->error('label is required and must be at most 50 characters.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!in_array($orderType, ['user_defined', 'verified'], true)) {
            return $this->error('order_type must be one of: user_defined, verified.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($email === '') {
            return $this->error('email is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->error('email must be a valid email address.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!$this->existsOnHorizon($account, $network)) {
            return $this->error(sprintf('account was not found on Horizon (%s).', $network), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($this->orderRepository->findActiveByAccountAddress($account, $networkCode) !== null) {
            return $this->error('This account already has a pending order.', Response::HTTP_BAD_REQUEST);
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->upsertLocalAccount($account, $networkCode, $now);

        $order = (new Order())
            ->setUuid($this->generateUuidV4())
            ->setAccountAddress($account)
            ->setNetwork($networkCode)
            ->setLabelName($labelName)
            ->setOrderType($orderType)
            ->setStatus(Order::STATUS_PENDING)
            ->setEmail($email)
            ->setExpiresAt($now->modify('+1 hour'))
            ->setCreatedAt($now)
            ->setUpdatedAt($now);

        $this->entityManager->persist($order);
        $this->entityManager->flush();
        $this->orderEmailNotifier->sendOrderCreated($order);

        return new JsonResponse([
            'message' => 'Order added successfully.',
            'status' => 200,
            'data' => $this->serializeOrder($order),
        ], Response::HTTP_OK);
    }

    #[Route('/v1/orders/{id}', name: 'orders_show', methods: ['GET'])]
    public function show(string $id, Request $request): JsonResponse
    {
        $networkInput = $request->query->get('network');
        $networkRaw = is_string($networkInput) ? trim($networkInput) : '';

        if ($networkRaw !== '') {
            $network = $this->stellarNetworkResolver->normalizeNetwork($networkRaw);
            $networkCode = $this->stellarNetworkResolver->resolveNetworkCode($network) ?? 1;
            $order = $this->orderRepository->findVisibleByUuidAndNetwork($id, $networkCode);
        } else {
            $order = $this->orderRepository->findVisibleByUuid($id);
        }

        if ($order === null) {
            return $this->error('Order not found for this id.', Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'message' => 'Order returned.',
            'status' => 200,
            'data' => $this->serializeOrder($order),
        ], Response::HTTP_OK);
    }

    #[Route('/v1/orders/{id}', name: 'orders_patch', methods: ['PATCH'])]
    public function patch(string $id, Request $request): JsonResponse
    {
        $order = $this->orderRepository->findVisibleByUuid($id);
        if ($order === null) {
            return $this->error('Order not found for this id.', Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent(), true);
        $payload = is_array($payload) ? $payload : [];

        $networkInput = $request->query->get('network', $payload['network'] ?? null);
        $network = $this->stellarNetworkResolver->normalizeNetwork(is_string($networkInput) ? $networkInput : null, $this->networkNameByCode($order->getNetwork()));
        $networkCode = $this->stellarNetworkResolver->resolveNetworkCode($network) ?? $order->getNetwork();
        if ($networkCode !== $order->getNetwork()) {
            return $this->error('network does not match order network.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $streamReasonInput = $request->query->get('stream_reason', $payload['stream_reason'] ?? null);
        $streamReason = trim((string) $streamReasonInput);
        if ($streamReason !== 'payment_stream_event') {
            return $this->error('stream_reason must be payment_stream_event.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $paymentTxHashInput = $request->query->get('payment_tx_hash', $payload['payment_tx_hash'] ?? null);
        $paymentTxHash = strtolower(trim((string) $paymentTxHashInput));
        if (!preg_match('/^[a-f0-9]{64}$/', $paymentTxHash)) {
            return $this->error('payment_tx_hash must be a 64-character lowercase hex string.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $paymentEventIdInput = $request->query->get('payment_event_id', $payload['payment_event_id'] ?? null);
        $paymentEventIdRaw = trim((string) $paymentEventIdInput);
        if ($paymentEventIdRaw === '' || !ctype_digit($paymentEventIdRaw)) {
            return $this->error('payment_event_id must be a positive integer.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $paymentEventId = (int) $paymentEventIdRaw;
        if ($paymentEventId <= 0) {
            return $this->error('payment_event_id must be a positive integer.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($order->getExpiresAt() !== null && $order->getExpiresAt() <= $now) {
            return $this->error('Order is expired and cannot be confirmed.', Response::HTTP_CONFLICT);
        }

        if ($order->getStatus() === Order::STATUS_COMPLETED && $order->getPaymentTxHash() === $paymentTxHash) {
            return new JsonResponse([
                'message' => 'Order already confirmed with this transaction.',
                'status' => 200,
                'data' => $this->serializeOrder($order),
            ], Response::HTTP_OK);
        }

        if (!in_array($order->getStatus(), [Order::STATUS_PENDING, Order::STATUS_AWAITING_VERIFICATION], true)) {
            return $this->error('Order status does not allow payment confirmation.', Response::HTTP_CONFLICT);
        }

        $validation = $this->orderPaymentStreamValidator->validate($order, $network, $paymentTxHash, $paymentEventId);
        if (!($validation['valid'] ?? false)) {
            return new JsonResponse([
                'message' => 'Payment stream event validation failed.',
                'status' => Response::HTTP_UNPROCESSABLE_ENTITY,
                'error_code' => $validation['reason'] ?? 'payment_validation_failed',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $newStatus = $order->getOrderType() === 'user_defined'
            ? Order::STATUS_COMPLETED
            : Order::STATUS_AWAITING_VERIFICATION;
        $previousStatus = $order->getStatus();

        $order
            ->setStatus($newStatus)
            ->setPaymentTxHash($paymentTxHash)
            ->setUpdatedAt($now);

        if ($newStatus === Order::STATUS_COMPLETED) {
            $account = $this->accountRepository->findOneByAddressAndNetwork((string) $order->getAccountAddress(), $order->getNetwork());
            if ($account instanceof Account) {
                $account
                    ->setLabel($order->getLabelName())
                    ->setUpdatedAt($now);
            }
        }

        $this->entityManager->flush();

        if ($previousStatus === Order::STATUS_PENDING) {
            if ($newStatus === Order::STATUS_COMPLETED) {
                $this->orderEmailNotifier->sendOrderCompleted($order);
            } elseif ($newStatus === Order::STATUS_AWAITING_VERIFICATION) {
                $this->orderEmailNotifier->sendOrderAwaitingVerification($order);
                $this->orderEmailNotifier->sendOrderAwaitingVerificationToAdmin($order);
            }
        }

        return new JsonResponse([
            'message' => 'Order payment confirmed from payment stream event.',
            'status' => 200,
            'data' => $this->serializeOrder($order),
        ], Response::HTTP_OK);
    }

    /**
     * @return array<string,mixed>
     */
    private function serializeOrder(Order $order): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $expirationSeconds = max(0, $order->getExpiresAt()?->getTimestamp() - $now->getTimestamp());
        $monitorAccount = $this->orderMonitorAccountResolver->resolveByNetworkCode($order->getNetwork());

        return [
            'order_no' => $order->getUuid(),
            'accountid' => $order->getAccountAddress(),
            'network' => $order->getNetwork(),
            'order_type' => $order->getOrderType(),
            'label_name' => $order->getLabelName(),
            'expiration_time' => $expirationSeconds,
            'status' => $order->getStatus(),
            'email' => $order->getEmail(),
            'payment_tx_hash' => $order->getPaymentTxHash(),
            'payment_monitor_account' => $monitorAccount !== '' ? $monitorAccount : null,
            'expires_at' => $order->getExpiresAt()?->format(\DateTimeInterface::ATOM),
            'created_at' => $order->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'message' => $message,
            'status' => $status,
        ], $status);
    }

    private function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function existsOnHorizon(string $account, string $network): bool
    {
        $sdk = new StellarSDK($this->stellarNetworkResolver->resolveHorizonUrl($network));

        try {
            $sdk->requestAccount($account);
            return true;
        } catch (HorizonRequestException $exception) {
            if (in_array($exception->getStatusCode(), [400, 404], true)) {
                return false;
            }

            throw $exception;
        }
    }

    private function upsertLocalAccount(string $address, int $networkCode, \DateTimeImmutable $now): void
    {
        $account = $this->accountRepository->findOneByAddressAndNetwork($address, $networkCode);
        if ($account === null) {
            $account = (new Account())
                ->setAddress($address)
                ->setNetwork($networkCode)
                ->setVerified(false)
                ->setCreatedAt($now)
                ->setUpdatedAt($now);
            $this->entityManager->persist($account);
            return;
        }

        $account->setUpdatedAt($now);
    }

    private function networkNameByCode(int $networkCode): string
    {
        return match ($networkCode) {
            1 => 'mainnet',
            2 => 'testnet',
            3 => 'futurenet',
            default => 'mainnet',
        };
    }
}
