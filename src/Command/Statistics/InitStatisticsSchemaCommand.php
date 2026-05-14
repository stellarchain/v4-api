<?php

declare(strict_types=1);

namespace App\Command\Statistics;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:statistics:init-schema',
    description: 'Create the storage table used by network metrics backfills.',
)]
final class InitStatisticsSchemaCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.statistics_connection')]
        private readonly Connection $statisticsConnection,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $platform = $this->statisticsConnection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->initMysqlSchema();
        } elseif ($platform instanceof PostgreSQLPlatform) {
            $this->initPostgresSchema();
        } else {
            $io->error(sprintf('Unsupported statistics database platform: %s', $platform::class));

            return Command::FAILURE;
        }

        $io->success('Statistics schema is ready.');

        return Command::SUCCESS;
    }

    private function initMysqlSchema(): void
    {
        $this->statisticsConnection->executeStatement(
            <<<SQL
CREATE TABLE IF NOT EXISTS network_metric_point (
    id INT AUTO_INCREMENT NOT NULL,
    network INT NOT NULL,
    metric_group VARCHAR(32) NOT NULL,
    metric_key VARCHAR(64) NOT NULL,
    source VARCHAR(32) NOT NULL,
    bucket_minutes INT NOT NULL,
    bucket_start DATETIME NOT NULL,
    bucket_end DATETIME NOT NULL,
    value_decimal NUMERIC(36, 14) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE INDEX uniq_network_metric_point_bucket (network, source, metric_key, bucket_minutes, bucket_start),
    INDEX idx_network_metric_point_query (network, metric_key, bucket_minutes, bucket_start, id),
    INDEX idx_network_metric_point_group (network, metric_group, bucket_minutes, bucket_start),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
SQL
        );
    }

    private function initPostgresSchema(): void
    {
        foreach ([
            <<<SQL
CREATE TABLE IF NOT EXISTS network_metric_point (
    id BIGSERIAL PRIMARY KEY,
    network INT NOT NULL,
    metric_group VARCHAR(32) NOT NULL,
    metric_key VARCHAR(64) NOT NULL,
    source VARCHAR(32) NOT NULL,
    bucket_minutes INT NOT NULL,
    bucket_start TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    bucket_end TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    value_decimal NUMERIC(36, 14) NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
)
SQL,
            'CREATE UNIQUE INDEX IF NOT EXISTS uniq_network_metric_point_bucket ON network_metric_point (network, source, metric_key, bucket_minutes, bucket_start)',
            'CREATE INDEX IF NOT EXISTS idx_network_metric_point_query ON network_metric_point (network, metric_key, bucket_minutes, bucket_start, id)',
            'CREATE INDEX IF NOT EXISTS idx_network_metric_point_group ON network_metric_point (network, metric_group, bucket_minutes, bucket_start)',
        ] as $sql) {
            $this->statisticsConnection->executeStatement($sql);
        }
    }
}
