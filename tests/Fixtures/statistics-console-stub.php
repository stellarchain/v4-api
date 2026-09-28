<?php

declare(strict_types=1);

$command = $argv[1] ?? '';
file_put_contents(getenv('STATISTICS_TEST_LOG'), 'console ' . implode(' ', array_slice($argv, 1)) . PHP_EOL, FILE_APPEND);
exit($command === getenv('STATISTICS_TEST_FAIL_COMMAND') ? 1 : 0);
