<?php

declare(strict_types=1);

namespace App\Command\Maintenance;

use App\Service\Stellar\StellarNetworkResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:contract-events:compact-payload',
    description: 'Null heavy payload columns for contract_events older than N days (hot window strategy).',
)]
final class CompactContractEventsPayloadCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.contracts_connection')]
        private readonly Connection $connection,
        private readonly StellarNetworkResolver $stellarNetworkResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('older-than-days', null, InputOption::VALUE_REQUIRED, 'Keep full payload only for rows newer than this age.', '30')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Rows updated per batch.', '50000')
            ->addOption('max-batches', null, InputOption::VALUE_REQUIRED, 'Stop after N batches (0 = until done).', '0')
            ->addOption('network', null, InputOption::VALUE_REQUIRED, 'all|mainnet|testnet|futurenet', 'all')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show impact only, do not update rows');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $olderThanDays = $this->parsePositiveInt((string) $input->getOption('older-than-days'));
        $batchSize = $this->parsePositiveInt((string) $input->getOption('batch-size'));
        $maxBatches = $this->parseNonNegativeInt((string) $input->getOption('max-batches'));
        $networkInput = strtolower(trim((string) $input->getOption('network')));
        $dryRun = (bool) $input->getOption('dry-run');

        if ($olderThanDays === null || $batchSize === null || $maxBatches === null) {
            $io->error('--older-than-days and --batch-size must be positive integers; --max-batches must be >= 0.');
            return Command::FAILURE;
        }

        $networkCode = $this->resolveNetworkCode($networkInput);
        if ($networkCode === false) {
            $io->error('--network must be one of: all, mainnet, testnet, futurenet.');
            return Command::FAILURE;
        }

        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->sub(new \DateInterval(sprintf('P%dD', $olderThanDays)))
            ->format('Y-m-d H:i:s');

        $cutId = $this->resolveCutoffId($cutoff, $networkCode);
        if ($cutId === null) {
            $io->success('No candidate rows found for payload compaction.');
            return Command::SUCCESS;
        }

        $remaining = $this->countCandidates($cutId, $networkCode);
        $io->table(
            ['Metric', 'Value'],
            [
                ['network', $networkInput],
                ['cutoff_utc', $cutoff],
                ['cut_id', (string) $cutId],
                ['rows_to_compact', (string) $remaining],
                ['batch_size', (string) $batchSize],
                ['max_batches', (string) $maxBatches],
                ['dry_run', $dryRun ? '1' : '0'],
            ]
        );

        if ($dryRun || $remaining === 0) {
            $io->success($dryRun ? 'Dry-run completed.' : 'Nothing to compact.');
            return Command::SUCCESS;
        }

        $batches = 0;
        $totalUpdated = 0;
        while (true) {
            if ($maxBatches > 0 && $batches >= $maxBatches) {
                break;
            }

            $updated = $this->compactOneBatch($cutId, $networkCode, $batchSize);
            if ($updated <= 0) {
                break;
            }

            $batches++;
            $totalUpdated += $updated;
        }

        $remainingAfter = $this->countCandidates($cutId, $networkCode);
        $io->table(
            ['Result', 'Value'],
            [
                ['batches_executed', (string) $batches],
                ['rows_compacted', (string) $totalUpdated],
                ['rows_remaining', (string) $remainingAfter],
            ]
        );
        $io->success('contract_events payload compaction finished.');

        return Command::SUCCESS;
    }

    /**
     * @return int|false
     */
    private function resolveNetworkCode(string $networkInput): int|false
    {
        if ($networkInput === 'all') {
            return 0;
        }

        if (!in_array($networkInput, ['mainnet', 'testnet', 'futurenet'], true)) {
            return false;
        }

        $code = $this->stellarNetworkResolver->resolveNetworkCode($networkInput);
        return is_int($code) ? $code : false;
    }

    private function resolveCutoffId(string $cutoff, int $networkCode): ?int
    {
        if ($networkCode === 0) {
            $id = $this->connection->fetchOne(
                'SELECT ce.id
                 FROM contract_events ce
                 WHERE ce.created_at >= :cutoff
                 ORDER BY ce.id ASC
                 LIMIT 1',
                ['cutoff' => $cutoff]
            );
        } else {
            $id = $this->connection->fetchOne(
                'SELECT ce.id
                 FROM contract_events ce
                 INNER JOIN contracts c ON c.id = ce.contract_id
                 WHERE c.network = :network
                   AND ce.created_at >= :cutoff
                 ORDER BY ce.id ASC
                 LIMIT 1',
                [
                    'network' => $networkCode,
                    'cutoff' => $cutoff,
                ],
                ['network' => ParameterType::INTEGER]
            );
        }

        if ($id === false) {
            $maxId = $networkCode === 0
                ? $this->connection->fetchOne('SELECT MAX(id) FROM contract_events')
                : $this->connection->fetchOne(
                    'SELECT MAX(ce.id)
                     FROM contract_events ce
                     INNER JOIN contracts c ON c.id = ce.contract_id
                     WHERE c.network = :network',
                    ['network' => $networkCode],
                    ['network' => ParameterType::INTEGER]
                );

            if ($maxId === false || $maxId === null) {
                return null;
            }

            $maxId = (int) $maxId;
            return $maxId > 0 ? $maxId + 1 : null;
        }

        $cutId = (int) $id;
        return $cutId > 0 ? $cutId : null;
    }

    private function countCandidates(int $cutId, int $networkCode): int
    {
        if ($networkCode === 0) {
            return (int) $this->connection->fetchOne(
                'SELECT COUNT(*)
                 FROM contract_events ce
                 WHERE ce.id < :cut_id
                   AND (ce.topic_decoded IS NOT NULL OR ce.value_decoded IS NOT NULL)',
                ['cut_id' => $cutId],
                ['cut_id' => ParameterType::INTEGER]
            );
        }

        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM contract_events ce
             INNER JOIN contracts c ON c.id = ce.contract_id
             WHERE c.network = :network
               AND ce.id < :cut_id
               AND (ce.topic_decoded IS NOT NULL OR ce.value_decoded IS NOT NULL)',
            [
                'network' => $networkCode,
                'cut_id' => $cutId,
            ],
            [
                'network' => ParameterType::INTEGER,
                'cut_id' => ParameterType::INTEGER,
            ]
        );
    }

    private function compactOneBatch(int $cutId, int $networkCode, int $batchSize): int
    {
        if ($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return $this->compactOneBatchPostgres($cutId, $networkCode, $batchSize);
        }

        if ($networkCode === 0) {
            return $this->connection->executeStatement(
                'UPDATE contract_events ce
                 SET ce.topic_decoded = NULL,
                     ce.value_decoded = NULL
                 WHERE ce.id < :cut_id
                   AND (ce.topic_decoded IS NOT NULL OR ce.value_decoded IS NOT NULL)
                 ORDER BY ce.id ASC
                 LIMIT :limit_rows',
                [
                    'cut_id' => $cutId,
                    'limit_rows' => $batchSize,
                ],
                [
                    'cut_id' => ParameterType::INTEGER,
                    'limit_rows' => ParameterType::INTEGER,
                ]
            );
        }

        return $this->connection->executeStatement(
            'UPDATE contract_events ce
             INNER JOIN contracts c ON c.id = ce.contract_id
             SET ce.topic_decoded = NULL,
                 ce.value_decoded = NULL
             WHERE c.network = :network
               AND ce.id < :cut_id
               AND (ce.topic_decoded IS NOT NULL OR ce.value_decoded IS NOT NULL)
             ORDER BY ce.id ASC
             LIMIT :limit_rows',
            [
                'network' => $networkCode,
                'cut_id' => $cutId,
                'limit_rows' => $batchSize,
            ],
            [
                'network' => ParameterType::INTEGER,
                'cut_id' => ParameterType::INTEGER,
                'limit_rows' => ParameterType::INTEGER,
            ]
        );
    }

    private function compactOneBatchPostgres(int $cutId, int $networkCode, int $batchSize): int
    {
        if ($networkCode === 0) {
            return $this->connection->executeStatement(
                'WITH selected AS (
                    SELECT ce.id
                    FROM contract_events ce
                    WHERE ce.id < :cut_id
                      AND (ce.topic_decoded IS NOT NULL OR ce.value_decoded IS NOT NULL)
                    ORDER BY ce.id ASC
                    LIMIT :limit_rows
                 )
                 UPDATE contract_events ce
                 SET topic_decoded = NULL,
                     value_decoded = NULL
                 FROM selected
                 WHERE ce.id = selected.id',
                [
                    'cut_id' => $cutId,
                    'limit_rows' => $batchSize,
                ],
                [
                    'cut_id' => ParameterType::INTEGER,
                    'limit_rows' => ParameterType::INTEGER,
                ]
            );
        }

        return $this->connection->executeStatement(
            'WITH selected AS (
                SELECT ce.id
                FROM contract_events ce
                INNER JOIN contracts c ON c.id = ce.contract_id
                WHERE c.network = :network
                  AND ce.id < :cut_id
                  AND (ce.topic_decoded IS NOT NULL OR ce.value_decoded IS NOT NULL)
                ORDER BY ce.id ASC
                LIMIT :limit_rows
             )
             UPDATE contract_events ce
             SET topic_decoded = NULL,
                 value_decoded = NULL
             FROM selected
             WHERE ce.id = selected.id',
            [
                'network' => $networkCode,
                'cut_id' => $cutId,
                'limit_rows' => $batchSize,
            ],
            [
                'network' => ParameterType::INTEGER,
                'cut_id' => ParameterType::INTEGER,
                'limit_rows' => ParameterType::INTEGER,
            ]
        );
    }

    private function parsePositiveInt(string $value): ?int
    {
        $value = trim($value);
        if ($value !== '' && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private function parseNonNegativeInt(string $value): ?int
    {
        $value = trim($value);
        if ($value !== '' && preg_match('/^[0-9]+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
