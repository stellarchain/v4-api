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
    source_asset_type VARCHAR(32) DEFAULT NULL,
    source_asset_code VARCHAR(32) DEFAULT NULL,
    source_asset_issuer VARCHAR(64) DEFAULT NULL,
    source_amount_decimal NUMERIC(36, 14) DEFAULT NULL,
    destination_asset_type VARCHAR(32) DEFAULT NULL,
    destination_asset_code VARCHAR(32) DEFAULT NULL,
    destination_asset_issuer VARCHAR(64) DEFAULT NULL,
    destination_amount_decimal NUMERIC(36, 14) DEFAULT NULL,
    asset_type VARCHAR(32) NOT NULL,
    asset_code VARCHAR(32) DEFAULT NULL,
    asset_issuer VARCHAR(64) DEFAULT NULL,
    amount_decimal NUMERIC(36, 14) DEFAULT NULL,
    memo_type VARCHAR(32) DEFAULT NULL,
    memo VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE INDEX uniq_payment_flow_event_operation (network, operation_id),
    INDEX idx_payment_flow_event_from (network, from_address, closed_at, id),
    INDEX idx_payment_flow_event_to (network, to_address, closed_at, id),
    INDEX idx_payment_flow_event_tx_hash (network, tx_hash),
    INDEX idx_payment_flow_event_ledger (network, ledger),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
SQL
        );

        foreach ([
            <<<SQL
CREATE TABLE IF NOT EXISTS asset_market_metric_point (
    id BIGINT AUTO_INCREMENT NOT NULL,
    network INT NOT NULL,
    bucket_minutes INT NOT NULL,
    bucket_start DATETIME NOT NULL,
    bucket_end DATETIME NOT NULL,
    asset_type VARCHAR(32) NOT NULL,
    asset_code VARCHAR(32) NOT NULL,
    asset_issuer VARCHAR(64) NOT NULL,
    trades_count BIGINT NOT NULL DEFAULT 0,
    volume_xlm NUMERIC(36, 14) NOT NULL DEFAULT 0,
    volume_asset NUMERIC(36, 14) NOT NULL DEFAULT 0,
    open_price_xlm NUMERIC(36, 14) DEFAULT NULL,
    high_price_xlm NUMERIC(36, 14) DEFAULT NULL,
    low_price_xlm NUMERIC(36, 14) DEFAULT NULL,
    close_price_xlm NUMERIC(36, 14) DEFAULT NULL,
    first_trade_at DATETIME DEFAULT NULL,
    last_trade_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE INDEX uniq_asset_market_metric_bucket (network, bucket_minutes, bucket_start, asset_type, asset_code, asset_issuer),
    INDEX idx_asset_market_metric_asset_time (network, asset_code, asset_issuer, bucket_start, id),
    INDEX idx_asset_market_metric_bucket (network, bucket_minutes, bucket_start),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
SQL,
            <<<SQL
CREATE TABLE IF NOT EXISTS asset_state_snapshot (
    id BIGINT AUTO_INCREMENT NOT NULL,
    network INT NOT NULL,
    range_start_ledger INT NOT NULL,
    range_end_ledger INT NOT NULL,
    snapshot_at DATETIME NOT NULL,
    asset_type VARCHAR(32) NOT NULL,
    asset_code VARCHAR(32) NOT NULL,
    asset_issuer VARCHAR(64) NOT NULL,
    trustlines_authorized INT NOT NULL DEFAULT 0,
    trustlines_authorized_to_maintain_liabilities INT NOT NULL DEFAULT 0,
    trustlines_unauthorized INT NOT NULL DEFAULT 0,
    trustlines_total INT NOT NULL DEFAULT 0,
    supply NUMERIC(36, 14) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE INDEX uniq_asset_state_snapshot_range (network, range_start_ledger, range_end_ledger, asset_type, asset_code, asset_issuer),
    INDEX idx_asset_state_snapshot_asset_time (network, asset_code, asset_issuer, snapshot_at, id),
    INDEX idx_asset_state_snapshot_range (network, range_start_ledger, range_end_ledger),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
SQL,
            <<<SQL
CREATE TABLE IF NOT EXISTS account_activity_summary (
    id BIGINT AUTO_INCREMENT NOT NULL,
    network INT NOT NULL,
    range_start_ledger INT NOT NULL,
    range_end_ledger INT NOT NULL,
    account_address VARCHAR(64) NOT NULL,
    first_ledger INT DEFAULT NULL,
    last_ledger INT DEFAULT NULL,
    first_activity_at DATETIME DEFAULT NULL,
    last_activity_at DATETIME DEFAULT NULL,
    total_transactions BIGINT NOT NULL DEFAULT 0,
    successful_transactions BIGINT NOT NULL DEFAULT 0,
    failed_transactions BIGINT NOT NULL DEFAULT 0,
    operation_count BIGINT NOT NULL DEFAULT 0,
    fee_charged_sum NUMERIC(36, 0) NOT NULL DEFAULT 0,
    max_fee_sum NUMERIC(36, 0) NOT NULL DEFAULT 0,
    operation_source_count BIGINT NOT NULL DEFAULT 0,
    payment_sent_count BIGINT NOT NULL DEFAULT 0,
    payment_received_count BIGINT NOT NULL DEFAULT 0,
    native_sent NUMERIC(36, 14) NOT NULL DEFAULT 0,
    native_received NUMERIC(36, 14) NOT NULL DEFAULT 0,
    trade_operation_count BIGINT NOT NULL DEFAULT 0,
    asset_operation_count BIGINT NOT NULL DEFAULT 0,
    contract_operation_count BIGINT NOT NULL DEFAULT 0,
    account_created_count BIGINT NOT NULL DEFAULT 0,
    account_funded_count BIGINT NOT NULL DEFAULT 0,
    account_merged_count BIGINT NOT NULL DEFAULT 0,
    merge_destination_count BIGINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE INDEX uniq_account_activity_summary_range (network, range_start_ledger, range_end_ledger, account_address),
    INDEX idx_account_activity_summary_account (network, account_address, first_ledger, id),
    INDEX idx_account_activity_summary_range (network, range_start_ledger, range_end_ledger),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
SQL,
        ] as $sql) {
            $this->statisticsConnection->executeStatement($sql);
        }
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
    source_asset_type VARCHAR(32) DEFAULT NULL,
    source_asset_code VARCHAR(32) DEFAULT NULL,
    source_asset_issuer VARCHAR(64) DEFAULT NULL,
    source_amount_decimal NUMERIC(36, 14) DEFAULT NULL,
    destination_asset_type VARCHAR(32) DEFAULT NULL,
    destination_asset_code VARCHAR(32) DEFAULT NULL,
    destination_asset_issuer VARCHAR(64) DEFAULT NULL,
    destination_amount_decimal NUMERIC(36, 14) DEFAULT NULL,
    asset_type VARCHAR(32) NOT NULL,
    asset_code VARCHAR(32) DEFAULT NULL,
    asset_issuer VARCHAR(64) DEFAULT NULL,
    amount_decimal NUMERIC(36, 14) DEFAULT NULL,
    memo_type VARCHAR(32) DEFAULT NULL,
    memo VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
)
SQL,
            'ALTER TABLE payment_flow_event ADD COLUMN IF NOT EXISTS source_asset_type VARCHAR(32) DEFAULT NULL',
            'ALTER TABLE payment_flow_event ADD COLUMN IF NOT EXISTS source_asset_code VARCHAR(32) DEFAULT NULL',
            'ALTER TABLE payment_flow_event ADD COLUMN IF NOT EXISTS source_asset_issuer VARCHAR(64) DEFAULT NULL',
            'ALTER TABLE payment_flow_event ADD COLUMN IF NOT EXISTS source_amount_decimal NUMERIC(36, 14) DEFAULT NULL',
            'ALTER TABLE payment_flow_event ADD COLUMN IF NOT EXISTS destination_asset_type VARCHAR(32) DEFAULT NULL',
            'ALTER TABLE payment_flow_event ADD COLUMN IF NOT EXISTS destination_asset_code VARCHAR(32) DEFAULT NULL',
            'ALTER TABLE payment_flow_event ADD COLUMN IF NOT EXISTS destination_asset_issuer VARCHAR(64) DEFAULT NULL',
            'ALTER TABLE payment_flow_event ADD COLUMN IF NOT EXISTS destination_amount_decimal NUMERIC(36, 14) DEFAULT NULL',
            'CREATE UNIQUE INDEX IF NOT EXISTS uniq_payment_flow_event_operation ON payment_flow_event (network, operation_id)',
            'CREATE INDEX IF NOT EXISTS idx_payment_flow_event_from ON payment_flow_event (network, from_address, closed_at, id)',
            'CREATE INDEX IF NOT EXISTS idx_payment_flow_event_to ON payment_flow_event (network, to_address, closed_at, id)',
            'CREATE INDEX IF NOT EXISTS idx_payment_flow_event_tx_hash ON payment_flow_event (network, tx_hash)',
            'CREATE INDEX IF NOT EXISTS idx_payment_flow_event_ledger ON payment_flow_event (network, ledger)',
            'DROP INDEX IF EXISTS idx_payment_flow_event_asset',
            <<<SQL
CREATE TABLE IF NOT EXISTS asset_market_metric_point (
    id BIGSERIAL PRIMARY KEY,
    network INT NOT NULL,
    bucket_minutes INT NOT NULL,
    bucket_start TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    bucket_end TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    asset_type VARCHAR(32) NOT NULL,
    asset_code VARCHAR(32) NOT NULL,
    asset_issuer VARCHAR(64) NOT NULL,
    trades_count BIGINT NOT NULL DEFAULT 0,
    volume_xlm NUMERIC(36, 14) NOT NULL DEFAULT 0,
    volume_asset NUMERIC(36, 14) NOT NULL DEFAULT 0,
    open_price_xlm NUMERIC(36, 14) DEFAULT NULL,
    high_price_xlm NUMERIC(36, 14) DEFAULT NULL,
    low_price_xlm NUMERIC(36, 14) DEFAULT NULL,
    close_price_xlm NUMERIC(36, 14) DEFAULT NULL,
    first_trade_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    last_trade_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
)
SQL,
            'CREATE UNIQUE INDEX IF NOT EXISTS uniq_asset_market_metric_bucket ON asset_market_metric_point (network, bucket_minutes, bucket_start, asset_type, asset_code, asset_issuer)',
            'CREATE INDEX IF NOT EXISTS idx_asset_market_metric_asset_time ON asset_market_metric_point (network, asset_code, asset_issuer, bucket_start, id)',
            'CREATE INDEX IF NOT EXISTS idx_asset_market_metric_bucket ON asset_market_metric_point (network, bucket_minutes, bucket_start)',
            <<<SQL
CREATE TABLE IF NOT EXISTS asset_state_snapshot (
    id BIGSERIAL PRIMARY KEY,
    network INT NOT NULL,
    range_start_ledger INT NOT NULL,
    range_end_ledger INT NOT NULL,
    snapshot_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    asset_type VARCHAR(32) NOT NULL,
    asset_code VARCHAR(32) NOT NULL,
    asset_issuer VARCHAR(64) NOT NULL,
    trustlines_authorized INT NOT NULL DEFAULT 0,
    trustlines_authorized_to_maintain_liabilities INT NOT NULL DEFAULT 0,
    trustlines_unauthorized INT NOT NULL DEFAULT 0,
    trustlines_total INT NOT NULL DEFAULT 0,
    supply NUMERIC(36, 14) NOT NULL DEFAULT 0,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
)
SQL,
            'CREATE UNIQUE INDEX IF NOT EXISTS uniq_asset_state_snapshot_range ON asset_state_snapshot (network, range_start_ledger, range_end_ledger, asset_type, asset_code, asset_issuer)',
            'CREATE INDEX IF NOT EXISTS idx_asset_state_snapshot_asset_time ON asset_state_snapshot (network, asset_code, asset_issuer, snapshot_at, id)',
            'CREATE INDEX IF NOT EXISTS idx_asset_state_snapshot_range ON asset_state_snapshot (network, range_start_ledger, range_end_ledger)',
            <<<SQL
CREATE TABLE IF NOT EXISTS account_activity_summary (
    id BIGSERIAL PRIMARY KEY,
    network INT NOT NULL,
    range_start_ledger INT NOT NULL,
    range_end_ledger INT NOT NULL,
    account_address VARCHAR(64) NOT NULL,
    first_ledger INT DEFAULT NULL,
    last_ledger INT DEFAULT NULL,
    first_activity_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    last_activity_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    total_transactions BIGINT NOT NULL DEFAULT 0,
    successful_transactions BIGINT NOT NULL DEFAULT 0,
    failed_transactions BIGINT NOT NULL DEFAULT 0,
    operation_count BIGINT NOT NULL DEFAULT 0,
    fee_charged_sum NUMERIC(36, 0) NOT NULL DEFAULT 0,
    max_fee_sum NUMERIC(36, 0) NOT NULL DEFAULT 0,
    operation_source_count BIGINT NOT NULL DEFAULT 0,
    payment_sent_count BIGINT NOT NULL DEFAULT 0,
    payment_received_count BIGINT NOT NULL DEFAULT 0,
    native_sent NUMERIC(36, 14) NOT NULL DEFAULT 0,
    native_received NUMERIC(36, 14) NOT NULL DEFAULT 0,
    trade_operation_count BIGINT NOT NULL DEFAULT 0,
    asset_operation_count BIGINT NOT NULL DEFAULT 0,
    contract_operation_count BIGINT NOT NULL DEFAULT 0,
    account_created_count BIGINT NOT NULL DEFAULT 0,
    account_funded_count BIGINT NOT NULL DEFAULT 0,
    account_merged_count BIGINT NOT NULL DEFAULT 0,
    merge_destination_count BIGINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
)
SQL,
            'CREATE UNIQUE INDEX IF NOT EXISTS uniq_account_activity_summary_range ON account_activity_summary (network, range_start_ledger, range_end_ledger, account_address)',
            'CREATE INDEX IF NOT EXISTS idx_account_activity_summary_account ON account_activity_summary (network, account_address, first_ledger, id)',
            'CREATE INDEX IF NOT EXISTS idx_account_activity_summary_range ON account_activity_summary (network, range_start_ledger, range_end_ledger)',
        ] as $sql) {
            $this->statisticsConnection->executeStatement($sql);
        }
    }
}
