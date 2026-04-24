<?php

namespace App\Service\Console;

use Symfony\Component\Console\Style\SymfonyStyle;

final class SorobanSyncProgressReporter
{
    /**
     * @return callable(array<string,mixed>):void
     */
    public function build(SymfonyStyle $io): callable
    {
        $progressPages = 0;

        return function (array $progress) use ($io, &$progressPages): void {
            $phase = is_string($progress['phase'] ?? null) ? $progress['phase'] : 'unknown';
            if ($phase === 'page') {
                $progressPages++;
                if ($progressPages % 20 !== 0) {
                    return;
                }
            }

            $windowStart = (int) ($progress['windowStartLedger'] ?? 0);
            $windowEnd = (int) ($progress['windowEndLedger'] ?? 0);
            $events = (int) ($progress['eventsCollected'] ?? 0);
            $tx = (int) ($progress['transactionsCollected'] ?? 0);
            $scanned = (int) ($progress['scannedLedgers'] ?? 0);
            $total = (int) ($progress['totalLedgers'] ?? 0);

            if ($phase === 'range-adjusted') {
                $io->writeln(sprintf(
                    'RPC range shifted. New start ledger: %d',
                    (int) ($progress['scanStartLedger'] ?? 0)
                ));
                return;
            }

            if ($phase === 'window') {
                $io->writeln(sprintf(
                    'Window %d-%d done | ledgers %d/%d | events=%d | uniqueTx=%d',
                    $windowStart,
                    $windowEnd,
                    $scanned,
                    $total,
                    $events,
                    $tx
                ));
                return;
            }

            if ($phase === 'horizon_ledger') {
                $io->writeln(sprintf(
                    'Horizon ledger %d done | ledgers %d/%d | uniqueTx=%d',
                    (int) ($progress['ledger'] ?? 0),
                    (int) ($progress['ledgersScanned'] ?? 0),
                    (int) ($progress['totalLedgers'] ?? 0),
                    (int) ($progress['transactionsCollected'] ?? 0)
                ));
                return;
            }

            $io->writeln(sprintf(
                'Progress | pages=%d | window=%d-%d | ledgers %d/%d | events=%d | uniqueTx=%d',
                (int) ($progress['pagesProcessed'] ?? 0),
                $windowStart,
                $windowEnd,
                $scanned,
                $total,
                $events,
                $tx
            ));
        };
    }
}
