<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AssetTomlEnrichmentService;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AssetTomlEnrichmentServiceTest extends TestCase
{
    private const USDT0_ISSUER = 'GATISXX6BZ6NC7IKQBY37CJD4SOZL3CYZJWXEDG6JVIY4WBS6KXJHN6Q';
    private const USDT0_IMAGE = 'https://ipfs.io/ipfs/bafkreifaalohkkikosp27qp6qczwaffwseejvoaafkgv7ahyrqvejmpqou';

    public function testCreatesAndEnrichesTrackedAssetOutsideMarketRanking(): void
    {
        $assetConnection = $this->createAssetConnection();
        $service = $this->createService(
            $assetConnection,
            $this->createHorizonConnection(),
            $this->createTomlHttpClient('usdt0.to', checkImage: true)
        );

        $result = $service->enrich('mainnet', false, 100, null, true, 1000);

        self::assertSame(1, $result['processed']);
        self::assertSame(1, $result['updated']);
        self::assertSame(0, $result['failed']);

        $asset = $this->loadStoredAsset($assetConnection);
        self::assertSame('USDT0-'.self::USDT0_ISSUER, $asset['asset_key']);
        self::assertSame('USDT0', $asset['code']);

        $storedToml = $this->decodeStoredToml($asset);
        self::assertSame(self::USDT0_IMAGE, $storedToml['image']);
        self::assertSame('usdt0.to', $storedToml['home_domain']);
        self::assertSame('https://usdt0.to/.well-known/stellar.toml', $storedToml['toml_url']);
        self::assertSame('Everdawn Labs Limited', $storedToml['documentation']['ORG_NAME']);
        self::assertArrayNotHasKey('image_fallback_used', $storedToml);
    }

    public function testOnChainHomeDomainRemainsAuthoritative(): void
    {
        $assetConnection = $this->createAssetConnection();
        $service = $this->createService(
            $assetConnection,
            $this->createHorizonConnection('issuer.example'),
            $this->createTomlHttpClient('issuer.example')
        );

        $result = $service->enrich('mainnet', false, 100, null, true, 1000, false);

        self::assertSame(1, $result['updated']);
        self::assertSame(0, $result['failed']);
        $storedToml = $this->decodeStoredToml($this->loadStoredAsset($assetConnection));
        self::assertSame('issuer.example', $storedToml['home_domain']);
        self::assertSame('https://issuer.example/.well-known/stellar.toml', $storedToml['toml_url']);
    }

    /**
     * @dataProvider mismatchedCurrencyProvider
     */
    public function testRejectsTomlCurrencyThatDoesNotMatchTrackedAsset(string $code, string $issuer): void
    {
        $assetConnection = $this->createAssetConnection();
        $service = $this->createService(
            $assetConnection,
            $this->createHorizonConnection(),
            $this->createTomlHttpClient('usdt0.to', $code, $issuer)
        );

        $result = $service->enrich('mainnet', false, 100, null, true, 1000, false);

        self::assertSame(1, $result['processed']);
        self::assertSame(0, $result['updated']);
        self::assertSame(1, $result['failed']);
        self::assertNull($this->loadStoredAsset($assetConnection)['toml_info']);
    }

    /**
     * @return iterable<string,array{string,string}>
     */
    public static function mismatchedCurrencyProvider(): iterable
    {
        yield 'wrong code' => ['NOTUSDT0', self::USDT0_ISSUER];
        yield 'wrong issuer' => [
            'USDT0',
            'GA2VRL65L3ZFEDDJ357RGI3MAOKPJZ2Z3IJTPSC24I4KDTNFSVEQURRA',
        ];
    }

    private function createService(
        Connection $assetConnection,
        Connection $horizonConnection,
        MockHttpClient $httpClient
    ): AssetTomlEnrichmentService {
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->expects(self::once())
            ->method('getConnection')
            ->with('horizon_mainnet')
            ->willReturn($horizonConnection);

        return new AssetTomlEnrichmentService(
            $assetConnection,
            $doctrine,
            $httpClient,
            new StellarNetworkResolver(),
            ['mainnet' => [[
                'code' => 'USDT0',
                'issuer' => self::USDT0_ISSUER,
                'home_domain' => 'usdt0.to',
            ]]]
        );
    }

    private function createAssetConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(<<<SQL
CREATE TABLE asset (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    asset_key VARCHAR(128) NOT NULL,
    network INTEGER NOT NULL,
    code VARCHAR(32) NOT NULL,
    issuer VARCHAR(56),
    is_native INTEGER NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    rating_average NUMERIC(8, 2),
    toml_info JSON,
    UNIQUE (asset_key, network)
)
SQL);
        $connection->executeStatement(<<<SQL
CREATE TABLE market_asset_snapshot (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    asset_id INTEGER NOT NULL,
    network INTEGER NOT NULL,
    rank_position INTEGER NOT NULL
)
SQL);

        return $connection;
    }

    private function createHorizonConnection(?string $homeDomain = null): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE accounts (account_id VARCHAR(56) NOT NULL, home_domain VARCHAR(255))');
        if ($homeDomain !== null) {
            $connection->insert('accounts', [
                'account_id' => self::USDT0_ISSUER,
                'home_domain' => $homeDomain,
            ]);
        }

        return $connection;
    }

    private function createTomlHttpClient(
        string $domain,
        string $code = 'USDT0',
        string $issuer = self::USDT0_ISSUER,
        bool $checkImage = false
    ): MockHttpClient {
        return new MockHttpClient(static function (string $method, string $url) use ($domain, $code, $issuer, $checkImage): MockResponse {
            self::assertSame('GET', $method);
            if ($url === self::USDT0_IMAGE && $checkImage) {
                return new MockResponse('png', [
                    'http_code' => 200,
                    'response_headers' => ['content-type' => 'image/png'],
                ]);
            }

            self::assertSame(sprintf('https://%s/.well-known/stellar.toml', $domain), $url);

            return new MockResponse(sprintf(<<<TOML
[DOCUMENTATION]
ORG_NAME = "Everdawn Labs Limited"
ORG_URL = "https://usdt0.to"

[[CURRENCIES]]
code = "%s"
issuer = "%s"
name = "USDT0"
image = "%s"
TOML, $code, $issuer, self::USDT0_IMAGE));
        });
    }

    /**
     * @return array<string,mixed>
     */
    private function loadStoredAsset(Connection $connection): array
    {
        $asset = $connection->fetchAssociative(
            'SELECT asset_key, code, issuer, toml_info FROM asset WHERE network = 1 AND issuer = ?',
            [self::USDT0_ISSUER]
        );
        self::assertIsArray($asset);

        return $asset;
    }

    /**
     * @param array<string,mixed> $asset
     * @return array<string,mixed>
     */
    private function decodeStoredToml(array $asset): array
    {
        $toml = json_decode((string) $asset['toml_info'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($toml);

        return $toml;
    }
}
