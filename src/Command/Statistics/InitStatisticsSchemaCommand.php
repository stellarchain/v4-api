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
    description: 'Create the storage tables used by historical statistics backfills.',
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

        $this->statisticsConnection->executeStatement(
            <<<SQL
CREATE TABLE IF NOT EXISTS payment_flow_event (
    id BIGINT AUTO_INCREMENT NOT NULL,
    network INT NOT NULL,
    ledger INT NOT NULL,
    closed_at DATETIME NOT NULL,
    tx_hash VARCHAR(64) NOT NULL,
    operation_id BIGINT NOT NULL,
    operation_index INT DEFAULT NULL,
    operation_type VARCHAR(48) NOT NULL,
    successful TINYINT(1) NOT NULL DEFAULT 1,
    source_account VARCHAR(64) DEFAULT NULL,
    from_address VARCHAR(64) DEFAULT NULL,
    to_address VARCHAR(64) DEFAULT NULL,
    asset_type VARCHAR(32) NOT NULL,
    asset_code VARCHAR(32) DEFAULT NULL,
    asset_issuer VARCHAR(64) DEFAULT NULL,
    amount_decimal NUMERIC(36, 14) DEFAULT NULL,
    memo_type VARCHAR(32) DEFAULT NULL,
    memo VARCHAR(255) DEFAULT NULL,
    raw_details LONGTEXT DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE INDEX uniq_payment_flow_event_operation (network, operation_id),
    INDEX idx_payment_flow_event_from (network, from_address, closed_at, id),
    INDEX idx_payment_flow_event_to (network, to_address, closed_at, id),
    INDEX idx_payment_flow_event_tx_hash (network, tx_hash),
    INDEX idx_payment_flow_event_ledger (network, ledger),
    INDEX idx_payment_flow_event_asset (network, asset_code, asset_issuer, closed_at),
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
            <<<SQL
CREATE TABLE IF NOT EXISTS payment_flow_event (
    id BIGSERIAL PRIMARY KEY,
    network INT NOT NULL,
    ledger INT NOT NULL,
    closed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    tx_hash VARCHAR(64) NOT NULL,
    operation_id BIGINT NOT NULL,
    operation_index INT DEFAULT NULL,
    operation_type VARCHAR(48) NOT NULL,
    successful BOOLEAN NOT NULL DEFAULT TRUE,
    source_account VARCHAR(64) DEFAULT NULL,
    from_address VARCHAR(64) DEFAULT NULL,
    to_address VARCHAR(64) DEFAULT NULL,
    asset_type VARCHAR(32) NOT NULL,
    asset_code VARCHAR(32) DEFAULT NULL,
    asset_issuer VARCHAR(64) DEFAULT NULL,
    amount_decimal NUMERIC(36, 14) DEFAULT NULL,
    memo_type VARCHAR(32) DEFAULT NULL,
    memo VARCHAR(255) DEFAULT NULL,
    raw_details JSONB DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
)
SQL,
            'CREATE UNIQUE INDEX IF NOT EXISTS uniq_payment_flow_event_operation ON payment_flow_event (network, operation_id)',
            'CREATE INDEX IF NOT EXISTS idx_payment_flow_event_from ON payment_flow_event (network, from_address, closed_at, id)',
            'CREATE INDEX IF NOT EXISTS idx_payment_flow_event_to ON payment_flow_event (network, to_address, closed_at, id)',
            'CREATE INDEX IF NOT EXISTS idx_payment_flow_event_tx_hash ON payment_flow_event (network, tx_hash)',
            'CREATE INDEX IF NOT EXISTS idx_payment_flow_event_ledger ON payment_flow_event (network, ledger)',
            'CREATE INDEX IF NOT EXISTS idx_payment_flow_event_asset ON payment_flow_event (network, asset_code, asset_issuer, closed_at)',
        ] as $sql) {
            $this->statisticsConnection->executeStatement($sql);
        }
    }
}
