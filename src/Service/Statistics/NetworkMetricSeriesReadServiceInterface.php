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
     *   items:list<array<string,mixed>>
     * }
     */
    public function read(
        string $network,
        ?string $metricKey,
        int $bucketMinutes,
        int $page,
        int $itemsPerPage
    ): array;
}
