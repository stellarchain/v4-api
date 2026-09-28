#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Service\Statistics\HorizonForwardfillRunner;

require dirname(__DIR__) . '/vendor/autoload.php';

if (in_array('--help', $argv, true)) {
    echo "Usage: php bin/horizon-forwardfill.php\nSee docs/forwardfill.md for configuration and the launch gates.\n";
    exit(0);
}

try {
    exit((new HorizonForwardfillRunner())->run(getenv(), dirname(__DIR__)));
} catch (Throwable $exception) {
    // Configuration errors intentionally contain variable names, never connection strings.
    fwrite(STDERR, 'Forwardfill stopped: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
