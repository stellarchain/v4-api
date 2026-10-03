<?php

declare(strict_types=1);

namespace App\Tests\Service\Trace;

use App\Service\Stellar\StellarNetworkResolver;
use App\Service\Trace\PaymentFlowAccountMetadataReadServiceInterface;
use App\Service\Trace\PaymentFlowInvestigationReadService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

final class PaymentFlowInvestigationReadServiceIntegrationTest extends TestCase
{
    private const CONTAINER = 'stellarchain-forwardfill-regression-20260928';
    private ?Connection $connection = null;

    protected function setUp(): void
    {
        if (getenv('STELLARCHAIN_REPAIR_TEST_CONTAINER') !== self::CONTAINER) {
            self::markTestSkipped('Requires the named disposable PostgreSQL test container. Never loads dotenv.');
        }
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => '127.0.0.1', 'port' => 55439,
            'dbname' => 'postgres', 'user' => 'postgres', 'connect_timeout' => 5,
        ]);
        $this->connection->executeStatement(file_get_contents(dirname(__DIR__, 2) . '/Fixtures/forwardfill-repair.sql'));
    }

    protected function tearDown(): void
    {
        $this->connection?->close();
    }

    public function testInvestigatorPaginatesWithoutDuplicatesIncludingSelfTransfers(): void
    {
        $metadata = $this->createMock(PaymentFlowAccountMetadataReadServiceInterface::class);
        $metadata->method('readByAddresses')->willReturn([]);
        $service = new PaymentFlowInvestigationReadService($this->connection, new StellarNetworkResolver(), $metadata);
        $first = $service->read('mainnet', 'GFOCUS', null, null, null, 'both', 2);
        self::assertSame(['1004', '1003'], array_column($first['events'], 'id'));
        self::assertTrue($first['coverage']['hasMore']);
        self::assertSame(105, $first['coverage']['latestObservedLedger']);
        self::assertFalse($first['coverage']['completeHistoryVerified']);
        $second = $service->read('mainnet', 'GFOCUS', null, null, null, 'both', 2, $first['coverage']['nextCursor']);
        self::assertSame(['1002', '1001'], array_column($second['events'], 'id'));
        self::assertFalse($second['coverage']['hasMore']);
        self::assertTrue($second['coverage']['isPartial']);
        $filtered = $service->read('mainnet', 'GFOCUS', null, 100, 100, 'incoming', 50, null, 'payment');
        self::assertSame(['1003', '1002'], array_column($filtered['events'], 'id'));
        $this->expectException(\InvalidArgumentException::class);
        $service->read('mainnet', 'GFOCUS', null, null, null, 'incoming', 2, $first['coverage']['nextCursor']);
    }

    public function testOrphanedTransactionDoesNotHideTheNextInvestigatorPage(): void
    {
        $this->connection->executeStatement("INSERT INTO payment_flow_event (id,network,ledger,tx_id,operation_type,from_address_id,to_address_id) VALUES (1005,1,102,999,'payment',1,2)");
        $metadata = $this->createMock(PaymentFlowAccountMetadataReadServiceInterface::class);
        $metadata->method('readByAddresses')->willReturn([]);
        $service = new PaymentFlowInvestigationReadService($this->connection, new StellarNetworkResolver(), $metadata);
        $first = $service->read('mainnet', 'GFOCUS', null, null, null, 'outgoing', 2);
        self::assertSame(['1004', '1003'], array_column($first['events'], 'id'));
        self::assertTrue($first['coverage']['hasMore']);
        $second = $service->read('mainnet', 'GFOCUS', null, null, null, 'outgoing', 2, $first['coverage']['nextCursor']);
        self::assertSame(['1001'], array_column($second['events'], 'id'));
    }
}
