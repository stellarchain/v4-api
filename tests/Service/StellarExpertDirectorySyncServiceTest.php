<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Stellar\StellarNetworkResolver;
use App\Service\StellarExpertDirectorySyncService;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class StellarExpertDirectorySyncServiceTest extends TestCase
{
    public function testItCreatesMissingEntriesAndOnlyUpdatesExistingScams(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE account (id INTEGER PRIMARY KEY AUTOINCREMENT, address VARCHAR(56) NOT NULL, network INTEGER NOT NULL, label VARCHAR(255) DEFAULT NULL, verified INTEGER NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $connection->executeStatement('INSERT INTO account (address, network, label, verified, created_at, updated_at) VALUES (?, 1, ?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)', [
            'GA2VRL65L3ZFEDDJ357RGI3MAOKPJZ2Z3IJTPSC24I4KDTNFSVEQURRA',
            'Existing good label',
        ]);
        $connection->executeStatement('INSERT INTO account (address, network, label, verified, created_at, updated_at) VALUES (?, 1, ?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)', [
            'GA6HCMBLTZS5VYYBCATRBRZ3BZJMAFUDKYYF6AH6MVCMGWMRDNSWJPIH',
            'Old label',
        ]);

        $payload = [
            '_links' => ['next' => ['href' => '/explorer/directory?order=asc&cursor=GCEMPTY&limit=3']],
            '_embedded' => [
                'records' => [
                    [
                        'address' => 'GA2VRL65L3ZFEDDJ357RGI3MAOKPJZ2Z3IJTPSC24I4KDTNFSVEQURRA',
                        'name' => 'External good label',
                        'tags' => ['custodian'],
                    ],
                    [
                        'address' => 'GA6HCMBLTZS5VYYBCATRBRZ3BZJMAFUDKYYF6AH6MVCMGWMRDNSWJPIH',
                        'name' => 'External scam label',
                        'tags' => ['malicious'],
                    ],
                    [
                        'address' => 'GAP5LETOV6YIE62YAM56STDANPRDO7ZFDBGSNHJQIYGGKSMOZAHOOS2S',
                        'name' => 'Missing account',
                        'tags' => ['anchor'],
                    ],
                ],
            ],
        ];
        $httpClient = new MockHttpClient([
            new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]),
            new MockResponse(json_encode(['_embedded' => ['records' => []]], JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]),
        ]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $service = new StellarExpertDirectorySyncService($connection, $entityManager, $httpClient, new StellarNetworkResolver());
        $result = $service->sync('mainnet', 3, null, null, false);

        self::assertSame(3, $result['fetched']);
        self::assertSame(1, $result['created']);
        self::assertSame(1, $result['scam_updates']);
        self::assertSame(1, $result['skipped_existing']);
        self::assertSame('Existing good label', $connection->fetchOne('SELECT label FROM account WHERE address = ?', ['GA2VRL65L3ZFEDDJ357RGI3MAOKPJZ2Z3IJTPSC24I4KDTNFSVEQURRA']));
        self::assertSame('Scam', $connection->fetchOne('SELECT label FROM account WHERE address = ?', ['GA6HCMBLTZS5VYYBCATRBRZ3BZJMAFUDKYYF6AH6MVCMGWMRDNSWJPIH']));
    }
}
