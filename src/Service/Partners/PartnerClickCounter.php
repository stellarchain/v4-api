<?php

declare(strict_types=1);

namespace App\Service\Partners;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class PartnerClickCounter
{
    public function __construct(private readonly PartnerFileStorage $storage)
    {
    }

    public function record(string $id, string $placement): void
    {
        $date = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $day = $date->format('Y-m-d');
        $lock = $this->storage->lock('clicks-'.$day);
        try {
            $rows = $this->storage->read('clicks-'.$day.'.json');
            $key = $id.':'.$placement;
            // Bound cardinality even after repeated catalog replacements.
            if (!isset($rows[$key]) && count($rows) >= 1000) {
                throw new \RuntimeException('Daily click counter capacity reached.');
            }
            $rows[$key] = [
                'day' => $day,
                'partnerId' => $id,
                'placement' => $placement,
                'clicks' => ($rows[$key]['clicks'] ?? 0) + 1,
            ];
            $this->storage->write('clicks-'.$day.'.json', $rows);
        } finally {
            $this->storage->unlock($lock);
        }
    }

    /** Returns aggregate activations, not unique people, conversions or verified human clicks. */
    public function report(string $from, string $to): array
    {
        $start = $this->parseDate($from);
        $end = $this->parseDate($to);
        if ($end < $start || $start->diff($end)->days > 89) {
            throw new InvalidArgumentException('Select an inclusive UTC range of at most 90 days.');
        }
        $rows = [];
        for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
            foreach ($this->storage->read('clicks-'.$day->format('Y-m-d').'.json') as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function parseDate(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Dates must use YYYY-MM-DD.');
        }

        return $date;
    }
}
