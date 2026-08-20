<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\Directory\SyncStellarExpertDirectoryCommand;
use App\Service\Stellar\StellarNetworkResolver;
use App\Service\StellarExpertDirectorySyncService;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SyncStellarExpertDirectoryCommandTest extends TestCase
{
    public function testItRejectsInvalidPageSize(): void
    {
        $tester = new CommandTester(new SyncStellarExpertDirectoryCommand($this->createService(), new StellarNetworkResolver()));
        $exitCode = $tester->execute(['--page-size' => '201']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('--page-size must be between 1 and 200.', $tester->getDisplay());
    }

    public function testItRunsSyncService(): void
    {
        $tester = new CommandTester(new SyncStellarExpertDirectoryCommand($this->createService([
            '_embedded' => [
                'records' => [
                    [
                        'address' => 'GA2VRL65L3ZFEDDJ357RGI3MAOKPJZ2Z3IJTPSC24I4KDTNFSVEQURRA',
                        'name' => 'SDF Escrow',
                        'tags' => ['sdf'],
                    ],
                ],
            ],
        ]), new StellarNetworkResolver()));
        $exitCode = $tester->execute([
            '--network' => 'mainnet',
            '--page-size' => '50',
            '--limit' => '1',
            '--cursor' => 'GCURSOR',
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Stellar Expert directory dry-run completed.', $tester->getDisplay());
        self::assertStringContainsString('created', $tester->getDisplay());
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function createService(array $payload = ['_embedded' => ['records' => []]]): StellarExpertDirectorySyncService
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE account (id INTEGER PRIMARY KEY AUTOINCREMENT, address VARCHAR(56) NOT NULL, network INTEGER NOT NULL, label VARCHAR(255) DEFAULT NULL, verified INTEGER NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $httpClient = new MockHttpClient([
            new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]),
        ]);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        return new StellarExpertDirectorySyncService($connection, $entityManager, $httpClient, new StellarNetworkResolver());
    }
}
