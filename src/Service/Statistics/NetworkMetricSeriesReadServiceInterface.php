<?php

declare(strict_types=1);

namespace App\Service\Statistics;

interface NetworkMetricSeriesReadServiceInterface
{
    /**
     * @return array{
     *   network:string,
     *   networkCode:int,
     *   metricKey:?string,
     *   bucketMinutes:int,
     *   page:int,
     *   itemsPerPage:int,
     *   totalItems:int,
     *   window:?array{start:string,end:string,olderBefore:?string,newerBefore:?string,isLatest:bool},
     *   items:list<array<string,mixed>>
     * }
     */
    public function read(
        string $network,
        ?string $metricKey,
        int $bucketMinutes,
        int $page,
        int $itemsPerPage,
        int $windowDays,
        ?string $before = null
    ): array;
}
