<?php
require __DIR__ . '/../vendor/autoload.php';
$k = new App\Kernel('dev', false);
$k->boot();
$doctrine = $k->getContainer()->get('doctrine');
foreach (['horizon_testnet','horizon_mainnet'] as $connName) {
    echo "=== $connName accounts columns ===\n";
    $c = $doctrine->getConnection($connName);
    $rows = $c->fetchAllAssociative("SELECT column_name, data_type, udt_name FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='accounts' ORDER BY ordinal_position");
    foreach ($rows as $r) {
        echo $r['column_name'], "|", $r['data_type'], "|", $r['udt_name'], "\n";
    }
    echo "\n=== $connName sample rows ===\n";
    $sample = $c->fetchAllAssociative("SELECT account_id, home_domain FROM accounts WHERE home_domain IS NOT NULL LIMIT 5");
    foreach ($sample as $s) {
        echo json_encode($s, JSON_UNESCAPED_SLASHES), "\n";
    }
    echo "\n";
}
