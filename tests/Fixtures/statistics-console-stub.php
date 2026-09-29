<?php

declare(strict_types=1);

use Doctrine\Bundle\DoctrineBundle\ConnectionFactory;

function verifyDoctrineConnectionUrls(): void
{
    require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

    $horizonConnection = getenv('NETWORK') === 'mainnet' ? 'DATABASE_HORIZON_URL_MAINNET' : 'DATABASE_HORIZON_URL_TESTNET';
    $statisticsUrl = getenv('STATISTICS_TEST_EXPECT_STATISTICS_APP_URL');
    $horizonUrl = getenv('STATISTICS_TEST_EXPECT_HORIZON_APP_URL');
    $expectedUrls = [
        'DATABASE_URL' => $statisticsUrl,
        'DATABASE_STATISTICS_URL' => $statisticsUrl,
        'DATABASE_CONTRACTS_URL' => $statisticsUrl,
        'DATABASE_HORIZON_URL' => $horizonUrl,
        $horizonConnection => $horizonUrl,
    ];
    $factory = new ConnectionFactory();
    foreach ($expectedUrls as $name => $expectedUrl) {
        $url = getenv($name);
        // Exercise the real factory without opening a connection or loading application dotenv.
        $connection = $factory->createConnection(['url' => $url]);
        if ($connection->isConnected() || $url !== $expectedUrl) {
            throw new RuntimeException('Unexpected Doctrine connection configuration: ' . $name);
        }
    }
    file_put_contents(getenv('STATISTICS_TEST_LOG'), 'doctrine connections initialized' . PHP_EOL, FILE_APPEND);
}

$command = $argv[1] ?? '';
file_put_contents(getenv('STATISTICS_TEST_LOG'), 'console ' . implode(' ', array_slice($argv, 1)) . PHP_EOL, FILE_APPEND);
if (getenv('STATISTICS_TEST_DOCTRINE_CONNECTIONS') === '1') {
    verifyDoctrineConnectionUrls();
}
exit($command === getenv('STATISTICS_TEST_FAIL_COMMAND') ? 1 : 0);
