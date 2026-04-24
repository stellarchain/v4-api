<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\Horizon\SyncNetworkMetricsCommand;
use App\Service\Statistics\NetworkMetricSyncServiceInterface;
use App\Service\Stellar\StellarNetworkResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SyncNetworkMetricsCommandTest extends TestCase
{
    public function testItFailsWhenOnlyOneLedgerBoundaryIsProvided(): void
    {
        $this->ensureCommandClassesLoaded();
        $service = $this->createMock(NetworkMetricSyncServiceInterface::class);
        $service->expects(self::never())->method('sync');

        $command = new SyncNetworkMetricsCommand($service, new StellarNetworkResolver());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            '--network' => 'testnet',
            '--bucket-minutes' => '10',
            '--start-ledger' => '100',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Use --start-ledger and --end-ledger together.', $tester->getDisplay());
    }

    public function testItDelegatesToSyncServiceAndSucceeds(): void
    {
        $this->ensureCommandClassesLoaded();
        $service = $this->createMock(NetworkMetricSyncServiceInterface::class);
        $service->expects(self::once())
            ->method('sync')
            ->with('mainnet', 60, 100, 200, false)
            ->willReturn([
                'network' => 'mainnet',
                'start_ledger' => 100,
                'end_ledger' => 200,
                'bucket_minutes' => 60,
                'buckets' => 5,
                'metrics_written' => 95,
                'rows_written' => 95,
                'dry_run' => false,
            ]);

        $command = new SyncNetworkMetricsCommand($service, new StellarNetworkResolver());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            '--network' => 'mainnet',
            '--bucket-minutes' => '60',
            '--start-ledger' => '100',
            '--end-ledger' => '200',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Network metric points synced.', $tester->getDisplay());
        self::assertStringContainsString('metrics_written', $tester->getDisplay());
    }

    private function ensureCommandClassesLoaded(): void
    {
        require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
        require_once dirname(__DIR__, 2) . '/src/Service/Statistics/NetworkMetricSyncServiceInterface.php';
        require_once dirname(__DIR__, 2) . '/src/Command/Support/NetworkOptionTrait.php';
        require_once dirname(__DIR__, 2) . '/src/Service/Stellar/StellarNetworkResolver.php';
        require_once dirname(__DIR__, 2) . '/src/Command/Horizon/SyncNetworkMetricsCommand.php';
    }
}
