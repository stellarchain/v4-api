<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\OrderController;
use App\Entity\Order;
use App\Repository\AccountRepository;
use App\Repository\OrderRepository;
use App\Service\Orders\OrderEmailNotifier;
use App\Service\Orders\OrderMonitorAccountResolver;
use App\Service\Orders\OrderPaymentStreamValidator;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class OrderControllerTest extends TestCase
{
    public function testShowReturnsNotFoundWhenNetworkFilterDoesNotMatchOrderNetwork(): void
    {
        $orderRepository = $this->createMock(OrderRepository::class);
        $orderRepository->expects(self::once())
            ->method('findVisibleByUuidAndNetwork')
            ->with('44fc9fbc-abe0-4133-b5bb-42dda7bb5373', 2)
            ->willReturn(null);

        $controller = new OrderController(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(AccountRepository::class),
            $orderRepository,
            $this->createMock(OrderEmailNotifier::class),
            new OrderMonitorAccountResolver('', '', ''),
            new StellarNetworkResolver(),
            $this->createMock(OrderPaymentStreamValidator::class)
        );

        $request = new Request([
            'network' => 'testnet',
        ]);

        $response = $controller->show('44fc9fbc-abe0-4133-b5bb-42dda7bb5373', $request);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame('Order not found for this id.', $payload['message']);
    }

    public function testPatchConfirmsUserDefinedOrderWithValidPaymentStreamEvent(): void
    {
        $order = $this->buildOrder('pending', 'user_defined');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $orderRepository = $this->createMock(OrderRepository::class);
        $orderRepository->expects(self::once())
            ->method('findVisibleByUuid')
            ->with('750dfc1a-ee1e-4be0-92fe-92e721e1aa2a')
            ->willReturn($order);

        $accountRepository = $this->createMock(AccountRepository::class);
        $accountRepository->expects(self::once())
            ->method('findOneByAddressAndNetwork')
            ->with('GAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAWHF', 2)
            ->willReturn(null);

        $streamValidator = $this->createMock(OrderPaymentStreamValidator::class);
        $streamValidator->expects(self::once())
            ->method('validate')
            ->willReturn([
                'valid' => true,
                'reason' => null,
                'rpc_checked' => true,
                'rpc_url' => 'https://soroban-testnet.stellar.org',
                'rpc_fallback_used' => false,
            ]);
        $orderEmailNotifier = $this->createMock(OrderEmailNotifier::class);
        $orderEmailNotifier->expects(self::once())
            ->method('sendOrderCompleted')
            ->with($order);
        $orderEmailNotifier->expects(self::never())
            ->method('sendOrderAwaitingVerification');
        $orderEmailNotifier->expects(self::never())
            ->method('sendOrderAwaitingVerificationToAdmin');

        $controller = new OrderController(
            $entityManager,
            $accountRepository,
            $orderRepository,
            $orderEmailNotifier,
            new OrderMonitorAccountResolver('', 'GMONITORAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', ''),
            new StellarNetworkResolver(),
            $streamValidator
        );

        $request = new Request([
            'network' => 'testnet',
            'stream_reason' => 'payment_stream_event',
            'payment_tx_hash' => '0531c3fd7e557f76b751a5011c1abda9575e1f362d4713d57524fd3efe89f501',
            'payment_event_id' => '5053084858335233',
        ]);

        $response = $controller->patch('750dfc1a-ee1e-4be0-92fe-92e721e1aa2a', $request);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame('Order payment confirmed from payment stream event.', $payload['message']);
        self::assertSame('completed', $payload['data']['status']);
        self::assertSame('0531c3fd7e557f76b751a5011c1abda9575e1f362d4713d57524fd3efe89f501', $payload['data']['payment_tx_hash']);
    }

    public function testPatchMovesVerifiedOrderToAwaitingVerificationAndSendsBothEmails(): void
    {
        $order = $this->buildOrder('pending', 'verified');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $orderRepository = $this->createMock(OrderRepository::class);
        $orderRepository->expects(self::once())
            ->method('findVisibleByUuid')
            ->with('750dfc1a-ee1e-4be0-92fe-92e721e1aa2a')
            ->willReturn($order);

        $accountRepository = $this->createMock(AccountRepository::class);
        $accountRepository->expects(self::never())
            ->method('findOneByAddressAndNetwork');

        $streamValidator = $this->createMock(OrderPaymentStreamValidator::class);
        $streamValidator->expects(self::once())
            ->method('validate')
            ->willReturn([
                'valid' => true,
                'reason' => null,
                'rpc_checked' => true,
                'rpc_url' => 'https://soroban-testnet.stellar.org',
                'rpc_fallback_used' => false,
            ]);

        $orderEmailNotifier = $this->createMock(OrderEmailNotifier::class);
        $orderEmailNotifier->expects(self::never())
            ->method('sendOrderCompleted');
        $orderEmailNotifier->expects(self::once())
            ->method('sendOrderAwaitingVerification')
            ->with($order);
        $orderEmailNotifier->expects(self::once())
            ->method('sendOrderAwaitingVerificationToAdmin')
            ->with($order);

        $controller = new OrderController(
            $entityManager,
            $accountRepository,
            $orderRepository,
            $orderEmailNotifier,
            new OrderMonitorAccountResolver('', 'GMONITORAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', ''),
            new StellarNetworkResolver(),
            $streamValidator
        );

        $request = new Request([
            'network' => 'testnet',
            'stream_reason' => 'payment_stream_event',
            'payment_tx_hash' => '1531c3fd7e557f76b751a5011c1abda9575e1f362d4713d57524fd3efe89f502',
            'payment_event_id' => '5053084858335234',
        ]);

        $response = $controller->patch('750dfc1a-ee1e-4be0-92fe-92e721e1aa2a', $request);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame('Order payment confirmed from payment stream event.', $payload['message']);
        self::assertSame('awaiting_verification', $payload['data']['status']);
        self::assertSame('1531c3fd7e557f76b751a5011c1abda9575e1f362d4713d57524fd3efe89f502', $payload['data']['payment_tx_hash']);
    }

    public function testPatchRejectsInvalidStreamReason(): void
    {
        $order = $this->buildOrder('pending', 'user_defined');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $orderRepository = $this->createMock(OrderRepository::class);
        $orderRepository->expects(self::once())
            ->method('findVisibleByUuid')
            ->willReturn($order);

        $streamValidator = $this->createMock(OrderPaymentStreamValidator::class);
        $streamValidator->expects(self::never())->method('validate');

        $controller = new OrderController(
            $entityManager,
            $this->createMock(AccountRepository::class),
            $orderRepository,
            $this->createMock(OrderEmailNotifier::class),
            new OrderMonitorAccountResolver('', '', ''),
            new StellarNetworkResolver(),
            $streamValidator
        );

        $request = new Request([
            'network' => 'testnet',
            'stream_reason' => 'other_reason',
            'payment_tx_hash' => '0531c3fd7e557f76b751a5011c1abda9575e1f362d4713d57524fd3efe89f501',
            'payment_event_id' => '5053084858335233',
        ]);

        $response = $controller->patch('id', $request);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame('stream_reason must be payment_stream_event.', $payload['message']);
    }

    private function buildOrder(string $status, string $orderType): Order
    {
        return (new Order())
            ->setUuid('750dfc1a-ee1e-4be0-92fe-92e721e1aa2a')
            ->setAccountAddress('GAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAWHF')
            ->setNetwork(2)
            ->setLabelName('my-label')
            ->setOrderType($orderType)
            ->setStatus($status)
            ->setEmail('a@b.com')
            ->setExpiresAt(new \DateTimeImmutable('+30 minutes'))
            ->setCreatedAt(new \DateTimeImmutable('-5 minutes'))
            ->setUpdatedAt(new \DateTimeImmutable('-2 minutes'));
    }
}
