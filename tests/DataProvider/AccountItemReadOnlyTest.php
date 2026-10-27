<?php

declare(strict_types=1);

namespace App\Tests\DataProvider;

use ApiPlatform\Metadata\Get;
use App\DataProvider\AccountItemDataProvider;
use App\Entity\Account;
use App\Entity\AccountMetric;
use App\Repository\AccountRepository;
use App\Service\AccountRead\AccountReadGateway;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AccountItemReadOnlyTest extends TestCase
{
    private const ADDRESS = 'GB44QL5XZTAXZDPDGAPDMGZ3NG4ZVPJXNTFWE22I7RZKKT25ATBJJWXI';

    public function testUnknownLocalAccountIsReturnedWithoutWrites(): void
    {
        $repository = $this->createMock(AccountRepository::class);
        $repository->method('findOneByAddressAndNetwork')->willReturn(null);
        $manager = $this->readOnlyManager();
        $manager->expects(self::never())->method('getConnection');
        $provider = new AccountItemDataProvider($repository, $manager, new StellarNetworkResolver(), $this->gateway());

        $result = $provider->provide(new Get(), ['address' => self::ADDRESS], ['filters' => ['network' => 'mainnet']]);

        self::assertSame(self::ADDRESS, $result->getAddress());
        self::assertNull($result->getId());
        self::assertNull($result->getCreatedAt());
        self::assertSame('orion', $result->getStellarData()['source']);
        self::assertSame('12.3400000', $result->getAccountMetric()->getNativeBalance());
    }

    public function testEnrichmentDoesNotDirtyManagedAccountOrMetric(): void
    {
        $local = (new Account())->setAddress(self::ADDRESS)->setLabel('Local label')->setVerified(true);
        $metric = (new AccountMetric())->setAccount($local)->setNativeBalance('5.0000000');
        $local->setAccountMetric($metric);
        $repository = $this->createMock(AccountRepository::class);
        $repository->method('findOneByAddressAndNetwork')->willReturn($local);
        $manager = $this->readOnlyManager();
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([]);
        $manager->method('getConnection')->willReturn($connection);
        $provider = new AccountItemDataProvider($repository, $manager, new StellarNetworkResolver(), $this->gateway());

        $result = $provider->provide(new Get(), ['address' => self::ADDRESS]);

        self::assertNotSame($local, $result);
        self::assertSame('Local label', $result->getLabel());
        self::assertTrue($result->isVerified());
        self::assertSame('5.0000000', $metric->getNativeBalance());
        self::assertSame($local, $metric->getAccount());
        self::assertSame('12.3400000', $result->getAccountMetric()->getNativeBalance());
    }

    private function readOnlyManager(): EntityManagerInterface
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::never())->method('persist');
        $manager->expects(self::never())->method('flush');

        return $manager;
    }

    private function gateway(): AccountReadGateway
    {
        $record = ['account_id' => self::ADDRESS, 'sequence' => '123',
            'balances' => [['asset_type' => 'native', 'balance' => '12.3400000']],
            'signers' => [], 'thresholds' => [], 'flags' => []];

        return new AccountReadGateway(new MockHttpClient([new MockResponse(json_encode($record, JSON_THROW_ON_ERROR))]),
            new StellarNetworkResolver(), new NullLogger(), 'https://orion.example', true);
    }
}
