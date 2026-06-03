<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ScamReportController;
use App\Entity\Account;
use App\Repository\AccountRepository;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ScamReportControllerTest extends TestCase
{
    public function testReportRejectsMissingToken(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $controller = new ScamReportController(
            $entityManager,
            $this->createMock(AccountRepository::class),
            new StellarNetworkResolver(),
            'secret-token'
        );

        $request = Request::create('/v1/scam-reports', 'POST', content: json_encode([
            'address' => 'GDFAPQOSUUISQU4CN2G2QYPJK4G532N3337PHVMIDTHTNEAVFWUMGUSD',
        ], JSON_THROW_ON_ERROR));

        $response = $controller->store($request);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testReportCreatesScamLabelForValidAddress(): void
    {
        $address = 'GDFAPQOSUUISQU4CN2G2QYPJK4G532N3337PHVMIDTHTNEAVFWUMGUSD';

        $accountRepository = $this->createMock(AccountRepository::class);
        $accountRepository->expects(self::once())
            ->method('findOneByAddressAndNetwork')
            ->with($address, 1)
            ->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function (Account $account) use ($address): bool {
                return $account->getAddress() === $address
                    && $account->getNetwork() === 1
                    && $account->getLabel() === 'Scam'
                    && $account->isVerified() === false;
            }));
        $entityManager->expects(self::once())->method('flush');

        $controller = new ScamReportController(
            $entityManager,
            $accountRepository,
            new StellarNetworkResolver(),
            'secret-token'
        );

        $request = Request::create('/v1/scam-reports', 'POST', server: [
            'HTTP_AUTHORIZATION' => 'Bearer secret-token',
        ], content: json_encode([
            'address' => $address,
            'network' => 'mainnet',
        ], JSON_THROW_ON_ERROR));

        $response = $controller->store($request);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame('Scam report accepted.', $payload['message']);
        self::assertSame('created', $payload['data'][0]['action']);
        self::assertSame($address, $payload['data'][0]['address']);
        self::assertSame('Scam', $payload['data'][0]['label']);
    }

    public function testReportUpdatesExistingLabelAndAcceptsTokenQueryFallback(): void
    {
        $address = 'GDFAPQOSUUISQU4CN2G2QYPJK4G532N3337PHVMIDTHTNEAVFWUMGUSD';
        $account = (new Account())
            ->setAddress($address)
            ->setNetwork(1)
            ->setLabel('MoneyGram USD')
            ->setVerified(true)
            ->setCreatedAt(new \DateTimeImmutable('-1 day'))
            ->setUpdatedAt(new \DateTimeImmutable('-1 hour'));

        $accountRepository = $this->createMock(AccountRepository::class);
        $accountRepository->expects(self::once())
            ->method('findOneByAddressAndNetwork')
            ->with($address, 1)
            ->willReturn($account);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $controller = new ScamReportController(
            $entityManager,
            $accountRepository,
            new StellarNetworkResolver(),
            'secret-token'
        );

        $request = Request::create('/v1/scam-reports?token=secret-token', 'POST', content: json_encode([
            'addresses' => [$address],
        ], JSON_THROW_ON_ERROR));

        $response = $controller->store($request);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('Scam', $account->getLabel());
        self::assertFalse($account->isVerified());
    }

    public function testReportRejectsInvalidAddress(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $controller = new ScamReportController(
            $entityManager,
            $this->createMock(AccountRepository::class),
            new StellarNetworkResolver(),
            'secret-token'
        );

        $request = Request::create('/v1/scam-reports', 'POST', server: [
            'HTTP_AUTHORIZATION' => 'Bearer secret-token',
        ], content: json_encode([
            'address' => 'not-a-stellar-address',
        ], JSON_THROW_ON_ERROR));

        $response = $controller->store($request);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function testSignedReportLinkCreatesScamLabelForValidAddress(): void
    {
        $address = 'GDFAPQOSUUISQU4CN2G2QYPJK4G532N3337PHVMIDTHTNEAVFWUMGUSD';
        $expires = 4102444800;
        $signature = hash_hmac('sha256', $address."\nmainnet\n".$expires, 'secret-token');

        $accountRepository = $this->createMock(AccountRepository::class);
        $accountRepository->expects(self::once())
            ->method('findOneByAddressAndNetwork')
            ->with($address, 1)
            ->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function (Account $account) use ($address): bool {
                return $account->getAddress() === $address
                    && $account->getNetwork() === 1
                    && $account->getLabel() === 'Scam'
                    && $account->isVerified() === false;
            }));
        $entityManager->expects(self::once())->method('flush');

        $controller = new ScamReportController(
            $entityManager,
            $accountRepository,
            new StellarNetworkResolver(),
            'secret-token'
        );

        $request = Request::create('/v1/scam-reports/link', 'GET', [
            'address' => strtolower($address),
            'network' => 'mainnet',
            'expires' => (string) $expires,
            'signature' => $signature,
        ]);

        $response = $controller->reportFromLink($request);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Scam report accepted', (string) $response->getContent());
        self::assertStringContainsString($address, (string) $response->getContent());
    }

    public function testSignedReportLinkRejectsInvalidSignature(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $accountRepository = $this->createMock(AccountRepository::class);
        $accountRepository->expects(self::never())->method('findOneByAddressAndNetwork');

        $controller = new ScamReportController(
            $entityManager,
            $accountRepository,
            new StellarNetworkResolver(),
            'secret-token'
        );

        $request = Request::create('/v1/scam-reports/link', 'GET', [
            'address' => 'GDFAPQOSUUISQU4CN2G2QYPJK4G532N3337PHVMIDTHTNEAVFWUMGUSD',
            'network' => 'mainnet',
            'expires' => '4102444800',
            'signature' => 'bad-signature',
        ]);

        $response = $controller->reportFromLink($request);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testSignedReportLinkRejectsExpiredSignature(): void
    {
        $address = 'GDFAPQOSUUISQU4CN2G2QYPJK4G532N3337PHVMIDTHTNEAVFWUMGUSD';
        $expires = 1;
        $signature = hash_hmac('sha256', $address."\nmainnet\n".$expires, 'secret-token');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $controller = new ScamReportController(
            $entityManager,
            $this->createMock(AccountRepository::class),
            new StellarNetworkResolver(),
            'secret-token'
        );

        $request = Request::create('/v1/scam-reports/link', 'GET', [
            'address' => $address,
            'network' => 'mainnet',
            'expires' => (string) $expires,
            'signature' => $signature,
        ]);

        $response = $controller->reportFromLink($request);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testSignedFormLinkRendersAddressReportForm(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $controller = new ScamReportController(
            $entityManager,
            $this->createMock(AccountRepository::class),
            new StellarNetworkResolver(),
            'secret-token'
        );

        $request = Request::create('/v1/scam-reports/form', 'GET', [
            'token' => 'secret-token',
        ]);

        $response = $controller->form($request);
        $html = (string) $response->getContent();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('<form', $html);
        self::assertStringContainsString('action="/v1/scam-reports/form?token=secret-token"', $html);
        self::assertStringContainsString('name="addresses"', $html);
        self::assertStringContainsString('Report scam addresses', $html);
        self::assertStringNotContainsString('name="network"', $html);
        self::assertStringNotContainsString('Testnet', $html);
    }

    public function testSignedFormSubmissionCreatesScamLabelForSubmittedAddress(): void
    {
        $address = 'GDFAPQOSUUISQU4CN2G2QYPJK4G532N3337PHVMIDTHTNEAVFWUMGUSD';

        $accountRepository = $this->createMock(AccountRepository::class);
        $accountRepository->expects(self::once())
            ->method('findOneByAddressAndNetwork')
            ->with($address, 1)
            ->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function (Account $account) use ($address): bool {
                return $account->getAddress() === $address
                    && $account->getNetwork() === 1
                    && $account->getLabel() === 'Scam'
                    && $account->isVerified() === false;
            }));
        $entityManager->expects(self::once())->method('flush');

        $controller = new ScamReportController(
            $entityManager,
            $accountRepository,
            new StellarNetworkResolver(),
            'secret-token'
        );

        $request = Request::create('/v1/scam-reports/form', 'POST', [
            'addresses' => strtolower($address),
            'network' => 'testnet',
            'token' => 'secret-token',
        ]);

        $response = $controller->submitForm($request);
        $html = (string) $response->getContent();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Scam report accepted', $html);
        self::assertStringContainsString($address, $html);
        self::assertStringContainsString('href="/v1/scam-reports/form?token=secret-token"', $html);
        self::assertStringContainsString('Report more addresses', $html);
    }

    public function testSignedFormRejectsInvalidSignature(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $controller = new ScamReportController(
            $entityManager,
            $this->createMock(AccountRepository::class),
            new StellarNetworkResolver(),
            'secret-token'
        );

        $request = Request::create('/v1/scam-reports/form', 'POST', [
            'addresses' => 'GDFAPQOSUUISQU4CN2G2QYPJK4G532N3337PHVMIDTHTNEAVFWUMGUSD',
            'network' => 'mainnet',
            'token' => 'bad-token',
        ]);

        $response = $controller->submitForm($request);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }
}
