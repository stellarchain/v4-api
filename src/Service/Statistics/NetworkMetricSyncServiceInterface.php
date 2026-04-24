<?php

declare(strict_types=1);

namespace App\Service\Statistics;

interface NetworkMetricSyncServiceInterface
{
    /**
     * @return array{
     *   network:string,
     *   start_ledger:int,
     *   end_ledger:int,
     *   bucket_minutes:int,
     *   buckets:int,
     *   metrics_written:int,
     *   rows_written:int,
     *   dry_run:bool
     * }
     */
    public function sync(
        string $network,
        int $bucketMinutes = 10,
        ?int $startLedger = null,
        ?int $endLedger = null,
        bool $dryRun = false
    ): array;
}
