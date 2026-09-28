<?php

declare(strict_types=1);

namespace App\Service\Statistics;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/** Resolves complete time buckets, with adjacent ledgers proving both boundaries. */
final class HistoricalBucketWindowResolver
{
    /**
     * @return array{start_ledger:int,end_ledger:int,bucket_start:string,bucket_end:string}|null
     */
    public function resolve(Connection $connection, int $bucketMinutes, ?int $startLedger, ?int $endLedger): ?array
    {
        $this->validateArguments($bucketMinutes, $startLedger, $endLedger);
        if ($startLedger !== null && $startLedger > $endLedger) {
            [$startLedger, $endLedger] = [$endLedger, $startLedger];
        }

        $range = $this->loadRange($connection, $startLedger, $endLedger);
        if ($range === null) {
            if ($startLedger !== null) {
                throw new \RuntimeException('Requested ledger range is missing; refusing partial bucket writes.');
            }

            return null;
        }

        $firstLedger = (int) $range['start_ledger'];
        $lastLedger = (int) $range['end_ledger'];
        if ($startLedger !== null) {
            $this->assertRequestedRangeComplete($range, $startLedger, $endLedger);
        }
        $startsAtGenesis = $firstLedger <= 2;
        $bounds = $this->resolveBucketBounds($range, $bucketMinutes, $startLedger !== null);
        if ($bounds === null) {
            return null;
        }
        [$from, $until] = $bounds;
        $previous = $connection->fetchOne(
            'SELECT sequence FROM history_ledgers WHERE sequence <= :edge AND closed_at < :bucket_start ORDER BY sequence DESC LIMIT 1',
            ['edge' => $startLedger === null ? $lastLedger : $firstLedger, 'bucket_start' => $from],
            ['edge' => ParameterType::INTEGER]
        );
        $following = $connection->fetchOne(
            'SELECT sequence FROM history_ledgers WHERE sequence >= :edge AND closed_at >= :bucket_end ORDER BY sequence ASC LIMIT 1',
            ['edge' => $startLedger === null ? $firstLedger : $lastLedger, 'bucket_end' => $until],
            ['edge' => ParameterType::INTEGER]
        );

        if (($previous === false && !$startsAtGenesis) || $following === false) {
            throw new \RuntimeException('Incomplete bucket context. Ingest adjacent ledgers before syncing; increase HORIZON_CONTEXT_LEDGERS if needed.');
        }

        $contextStart = $previous === false ? $firstLedger : (int) $previous;
        $contextEnd = (int) $following;
        $this->assertContiguousRange($connection, $contextStart, $contextEnd);

        return [
            'start_ledger' => $previous === false ? $firstLedger : $contextStart + 1,
            'end_ledger' => $contextEnd - 1,
            'bucket_start' => $from,
            'bucket_end' => $until,
        ];
    }

    private function validateArguments(int $bucketMinutes, ?int $startLedger, ?int $endLedger): void
    {
        if ($bucketMinutes < 1 || $bucketMinutes > intdiv(PHP_INT_MAX, 60)) {
            throw new \InvalidArgumentException('Invalid bucket size.');
        }
        if (($startLedger === null) !== ($endLedger === null)) {
            throw new \InvalidArgumentException('Start and end ledger must be provided together.');
        }
        if ($startLedger !== null && ($startLedger < 1 || $endLedger < 1)) {
            throw new \InvalidArgumentException('Start and end ledger must be positive integers.');
        }
    }

    /** @param array<string,mixed> $range */
    private function assertRequestedRangeComplete(array $range, int $startLedger, int $endLedger): void
    {
        // Horizon history may begin at ledger 2: ledger 1 is genesis state.
        $firstLedger = (int) $range['start_ledger'];
        $expectedStart = $startLedger === 1 && $firstLedger === 2 ? 2 : $startLedger;
        if ($firstLedger !== $expectedStart || (int) $range['end_ledger'] !== $endLedger
            || (int) $range['ledger_count'] !== $endLedger - $expectedStart + 1) {
            throw new \RuntimeException('Requested ledger range is incomplete; refusing partial bucket writes.');
        }
    }

    /**
     * @param array<string,mixed> $range
     * @return array{string,string}|null
     */
    private function resolveBucketBounds(array $range, int $bucketMinutes, bool $explicitRange): ?array
    {
        $seconds = $bucketMinutes * 60;
        $firstTime = new \DateTimeImmutable((string) $range['min_closed_at'], new \DateTimeZone('UTC'));
        $lastTime = new \DateTimeImmutable((string) $range['max_closed_at'], new \DateTimeZone('UTC'));
        $bucketStart = intdiv($firstTime->getTimestamp(), $seconds) * $seconds;
        $bucketEnd = intdiv($lastTime->getTimestamp(), $seconds) * $seconds + $seconds;
        if (!$explicitRange) {
            // An unbounded sync only publishes closed, provably complete buckets.
            if ((int) $range['start_ledger'] > 2) {
                $bucketStart += $seconds;
            }
            $bucketEnd -= $seconds;
        }

        if ($bucketStart >= $bucketEnd) {
            return null;
        }

        return [gmdate('Y-m-d H:i:s', $bucketStart), gmdate('Y-m-d H:i:s', $bucketEnd)];
    }

    private function assertContiguousRange(Connection $connection, int $contextStart, int $contextEnd): void
    {
        $count = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM history_ledgers WHERE sequence BETWEEN :start_ledger AND :end_ledger',
            ['start_ledger' => $contextStart, 'end_ledger' => $contextEnd],
            ['start_ledger' => ParameterType::INTEGER, 'end_ledger' => ParameterType::INTEGER]
        );
        if ($count !== $contextEnd - $contextStart + 1) {
            throw new \RuntimeException('Ledger gaps inside the complete bucket window; refusing partial bucket writes.');
        }
    }

    /** @return array<string,mixed>|null */
    private function loadRange(Connection $connection, ?int $startLedger, ?int $endLedger): ?array
    {
        $sql = 'SELECT MIN(sequence) AS start_ledger, MAX(sequence) AS end_ledger, MIN(closed_at) AS min_closed_at, MAX(closed_at) AS max_closed_at, COUNT(*) AS ledger_count FROM history_ledgers';
        $params = [];
        $types = [];
        if ($startLedger !== null) {
            $sql .= ' WHERE sequence BETWEEN :start_ledger AND :end_ledger';
            $params = ['start_ledger' => $startLedger, 'end_ledger' => $endLedger];
            $types = ['start_ledger' => ParameterType::INTEGER, 'end_ledger' => ParameterType::INTEGER];
        }
        $row = $connection->fetchAssociative($sql, $params, $types);

        return $row === false || $row['start_ledger'] === null ? null : $row;
    }
}
