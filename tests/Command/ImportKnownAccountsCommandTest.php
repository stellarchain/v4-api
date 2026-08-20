<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\Import\ImportKnownAccountsCommand;
use App\Entity\Account;
use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ImportKnownAccountsCommandTest extends TestCase
{
    private const USDT0_ISSUER = 'GATISXX6BZ6NC7IKQBY37CJD4SOZL3CYZJWXEDG6JVIY4WBS6KXJHN6Q';

    public function testCuratedAccountIsNotRemovedByTheMinimumBalanceFilter(): void
    {
        $horizonConnection = $this->createMock(Connection::class);
        $horizonConnection->expects(self::exactly(4))
            ->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls(
                [['column_name' => 'account_id'], ['column_name' => 'balance']],
                [],
                [[
                    'address' => self::USDT0_ISSUER,
                    'native_balance_stroops' => '49992291',
                ]],
                []
            );

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::once())
            ->method('getConnection')
            ->with('horizon_mainnet')
            ->willReturn($horizonConnection);

        $localConnection = $this->createMock(Connection::class);
        $localConnection->expects(self::once())
            ->method('fetchFirstColumn')
            ->willReturn([]);

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getTableName')->willReturn('account');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')->with(Account::class)->willReturn($metadata);
        $entityManager->method('getConnection')->willReturn($localConnection);

        $labelsFile = tempnam(sys_get_temp_dir(), 'known-accounts-');
        self::assertIsString($labelsFile);
        file_put_contents($labelsFile, sprintf("%s,Everdawn Labs Limited (USDT0),1\n", self::USDT0_ISSUER));

        try {
            $tester = new CommandTester(new ImportKnownAccountsCommand(
                $entityManager,
                $registry,
                new StellarNetworkResolver()
            ));
            $exitCode = $tester->execute([
                '--network' => 'mainnet',
                '--top' => '1',
                '--min-balance' => '10',
                '--balance-unit' => 'stroops',
                '--labels-file' => $labelsFile,
                '--dry-run' => true,
            ]);
        } finally {
            unlink($labelsFile);
        }

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertMatchesRegularExpression('/processed_after_filters\s+1/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/created\s+1/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/verified_applied_from_csv\s+1/', $tester->getDisplay());
    }
}
