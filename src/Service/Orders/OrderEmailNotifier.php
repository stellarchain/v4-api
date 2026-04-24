<?php

namespace App\Service\Orders;

use App\Entity\Order;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class OrderEmailNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly string $fromAddress,
        private readonly string $fromName,
        private readonly string $verificationAdminEmail,
    ) {
    }

    public function sendOrderCreated(Order $order): void
    {
        $email = $this->normalizeEmail($order->getEmail());
        if ($email === null) {
            return;
        }

        $this->send(
            $email,
            sprintf('[StellarChain] Order created #%s', (string) $order->getUuid()),
            sprintf(
                "Your label order was created.\n\nOrder: %s\nAccount: %s\nNetwork: %s\nLabel: %s\nStatus: %s\nExpires at: %s\n\nPlease send payment to the configured monitor account to continue processing.",
                (string) $order->getUuid(),
                (string) $order->getAccountAddress(),
                $this->networkNameByCode($order->getNetwork()),
                (string) $order->getLabelName(),
                $order->getStatus(),
                $order->getExpiresAt()?->format(\DateTimeInterface::ATOM) ?? 'n/a',
            )
        );
    }

    public function sendOrderCompleted(Order $order): void
    {
        $email = $this->normalizeEmail($order->getEmail());
        if ($email === null) {
            return;
        }

        $this->send(
            $email,
            sprintf('[StellarChain] Order completed #%s', (string) $order->getUuid()),
            sprintf(
                "Your label order has been completed.\n\nOrder: %s\nAccount: %s\nNetwork: %s\nLabel: %s\nStatus: %s\nPayment tx hash: %s",
                (string) $order->getUuid(),
                (string) $order->getAccountAddress(),
                $this->networkNameByCode($order->getNetwork()),
                (string) $order->getLabelName(),
                $order->getStatus(),
                (string) $order->getPaymentTxHash(),
            )
        );
    }

    public function sendOrderAwaitingVerification(Order $order): void
    {
        $email = $this->normalizeEmail($order->getEmail());
        if ($email === null) {
            return;
        }

        $this->send(
            $email,
            sprintf('[StellarChain] Order awaiting verification #%s', (string) $order->getUuid()),
            sprintf(
                "Your payment was received and your label order is awaiting verification.\n\nOrder: %s\nAccount: %s\nNetwork: %s\nLabel: %s\nStatus: %s\nPayment tx hash: %s\n\nWe will notify you once verification is completed.",
                (string) $order->getUuid(),
                (string) $order->getAccountAddress(),
                $this->networkNameByCode($order->getNetwork()),
                (string) $order->getLabelName(),
                $order->getStatus(),
                (string) $order->getPaymentTxHash(),
            )
        );
    }

    public function sendOrderAwaitingVerificationToAdmin(Order $order): void
    {
        $email = $this->normalizeEmail($this->verificationAdminEmail);
        if ($email === null) {
            return;
        }

        $this->send(
            $email,
            sprintf('[StellarChain] Verification required for order #%s', (string) $order->getUuid()),
            sprintf(
                "A label order is awaiting verification.\n\nOrder: %s\nAccount: %s\nNetwork: %s\nLabel: %s\nType: %s\nStatus: %s\nCustomer email: %s\nPayment tx hash: %s\nCreated at: %s\nUpdated at: %s",
                (string) $order->getUuid(),
                (string) $order->getAccountAddress(),
                $this->networkNameByCode($order->getNetwork()),
                (string) $order->getLabelName(),
                (string) $order->getOrderType(),
                $order->getStatus(),
                (string) $order->getEmail(),
                (string) $order->getPaymentTxHash(),
                $order->getCreatedAt()?->format(\DateTimeInterface::ATOM) ?? 'n/a',
                $order->getUpdatedAt()?->format(\DateTimeInterface::ATOM) ?? 'n/a',
            )
        );
    }

    private function send(string $to, string $subject, string $body): void
    {
        try {
            $message = (new Email())
                ->from(new Address($this->fromAddress, $this->fromName))
                ->to($to)
                ->subject($subject)
                ->text($body);

            $this->mailer->send($message);
        } catch (TransportExceptionInterface|\Throwable $exception) {
            $this->logger->warning('Order notification email could not be sent.', [
                'recipient' => $to,
                'subject' => $subject,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function normalizeEmail(?string $email): ?string
    {
        if (!is_string($email)) {
            return null;
        }

        $trimmed = trim($email);
        if ($trimmed === '' || filter_var($trimmed, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $trimmed;
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
