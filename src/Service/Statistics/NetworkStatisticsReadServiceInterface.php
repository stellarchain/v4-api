<?php

declare(strict_types=1);

namespace App\Service\Statistics;

interface NetworkStatisticsReadServiceInterface
{
    /**
     * @return array<string,mixed>
     */
    public function read(string $network, string $range, int $bucketMinutes): array;
}
