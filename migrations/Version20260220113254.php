<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260220113254 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE account (id INT AUTO_INCREMENT NOT NULL, address VARCHAR(56) NOT NULL, network INT DEFAULT 1 NOT NULL, label VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, verified TINYINT NOT NULL, UNIQUE INDEX UNIQ_7D3656A4D4E6F81 (address), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE account_metric (id INT AUTO_INCREMENT NOT NULL, total_transactions BIGINT DEFAULT 0 NOT NULL, native_balance NUMERIC(36, 7) DEFAULT \'0.0000000\' NOT NULL, payments_count BIGINT DEFAULT 0 NOT NULL, trades_count BIGINT DEFAULT 0 NOT NULL, rank_score NUMERIC(20, 8) DEFAULT \'0\' NOT NULL, rank_position INT DEFAULT NULL, metric_updated_at DATETIME DEFAULT NULL, account_id INT NOT NULL, UNIQUE INDEX UNIQ_A0E2E0909B6B5FBA (account_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE account_metric_interval (id INT AUTO_INCREMENT NOT NULL, interval_start DATETIME NOT NULL, interval_end DATETIME NOT NULL, total_transactions INT NOT NULL, payment_operations INT NOT NULL, trade_operations INT NOT NULL, asset_transactions INT NOT NULL, contract_transactions INT NOT NULL, successful_transactions INT NOT NULL, failed_transactions INT NOT NULL, operation_count INT NOT NULL, fee_charged_sum NUMERIC(30, 0) NOT NULL, max_fee_sum NUMERIC(30, 0) NOT NULL, first_tx_at DATETIME DEFAULT NULL, last_tx_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, account_id INT NOT NULL, INDEX IDX_F3606B249B6B5FBA (account_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE asset (id INT AUTO_INCREMENT NOT NULL, asset_key VARCHAR(128) NOT NULL, network INT DEFAULT 1 NOT NULL, code VARCHAR(32) NOT NULL, issuer VARCHAR(56) DEFAULT NULL, is_native TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, rating_average NUMERIC(8, 2) DEFAULT NULL, toml_info JSON DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE asset_metric_history (id INT AUTO_INCREMENT NOT NULL, source VARCHAR(64) NOT NULL, metric_key VARCHAR(191) NOT NULL, value_decimal NUMERIC(36, 14) DEFAULT NULL, value_text VARCHAR(255) DEFAULT NULL, recorded_at DATETIME NOT NULL, asset_id INT NOT NULL, INDEX IDX_3CF846D25DA1941 (asset_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE asset_statistic (id INT AUTO_INCREMENT NOT NULL, price NUMERIC(18, 8) DEFAULT NULL, supply NUMERIC(36, 0) DEFAULT NULL, trades BIGINT DEFAULT NULL, traded_amount BIGINT DEFAULT NULL, payments BIGINT DEFAULT NULL, payments_amount BIGINT DEFAULT NULL, trustlines_total INT DEFAULT NULL, trustlines_authorized INT DEFAULT NULL, trustlines_funded INT DEFAULT NULL, rating_average NUMERIC(8, 2) DEFAULT NULL, recorded_at DATETIME NOT NULL, asset_id INT NOT NULL, INDEX IDX_89A1AEA65DA1941 (asset_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE contract_events (id INT AUTO_INCREMENT NOT NULL, tx_hash VARCHAR(300) NOT NULL, event_idx INT NOT NULL, ledger INT DEFAULT NULL, ledger_closed_at DATETIME DEFAULT NULL, event_type VARCHAR(64) DEFAULT \'unknown\' NOT NULL, topic_decoded JSON DEFAULT NULL, value_decoded JSON DEFAULT NULL, addresses JSON DEFAULT NULL, amount_raw VARCHAR(100) DEFAULT NULL, created_at DATETIME DEFAULT NULL, contract_id INT NOT NULL, INDEX IDX_6FC7B2072576E0FD (contract_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE contract_sources (id INT AUTO_INCREMENT NOT NULL, wasm_id VARCHAR(64) NOT NULL, source_code LONGTEXT DEFAULT NULL, source_code_sha256 VARCHAR(64) DEFAULT NULL, wasm_blob LONGBLOB DEFAULT NULL, wasm_blob_sha256 VARCHAR(64) DEFAULT NULL, status INT DEFAULT 0 NOT NULL, error_message LONGTEXT DEFAULT NULL, decompiled_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_contract_sources_wasm_id (wasm_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE contract_storage_entries (id INT AUTO_INCREMENT NOT NULL, storage_key VARCHAR(512) NOT NULL, entry_xdr LONGTEXT DEFAULT NULL, entry_decoded JSON DEFAULT NULL, last_modified_ledger_seq INT DEFAULT NULL, live_until_ledger_seq INT DEFAULT NULL, updated_at DATETIME NOT NULL, contract_id INT NOT NULL, INDEX IDX_899AFD5D2576E0FD (contract_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE contract_transactions (id INT AUTO_INCREMENT NOT NULL, tx_hash VARCHAR(300) NOT NULL, source_account VARCHAR(300) DEFAULT NULL, host_functions LONGTEXT DEFAULT NULL, fee_charged INT NOT NULL, max_fee INT NOT NULL, ledger INT DEFAULT NULL, total_operations INT DEFAULT NULL, created_at DATETIME DEFAULT NULL, contract_id INT NOT NULL, INDEX IDX_F3F0F982576E0FD (contract_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE contracts (id INT AUTO_INCREMENT NOT NULL, contract_id VARCHAR(300) NOT NULL, contract_id_hex VARCHAR(64) DEFAULT NULL, asset_code VARCHAR(255) DEFAULT NULL, asset_address VARCHAR(255) DEFAULT NULL, asset_issuer VARCHAR(255) DEFAULT NULL, created_at DATETIME DEFAULT NULL, contract_code LONGTEXT DEFAULT NULL, source_code_verified TINYINT DEFAULT 0 NOT NULL, contract_type INT DEFAULT NULL, network INT DEFAULT 1 NOT NULL, executable_type INT DEFAULT NULL, is_sac TINYINT DEFAULT 0 NOT NULL, wasm_id VARCHAR(64) DEFAULT NULL, INDEX IDX_950A973993544C7 (wasm_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE market_asset_snapshot (id INT AUTO_INCREMENT NOT NULL, network INT DEFAULT 1 NOT NULL, rank_position INT NOT NULL, score NUMERIC(20, 8) NOT NULL, price_xlm NUMERIC(18, 8) DEFAULT NULL, price_change1h NUMERIC(12, 4) DEFAULT NULL, price_change24h NUMERIC(12, 4) DEFAULT NULL, price_change7d NUMERIC(12, 4) DEFAULT NULL, volume_xlm24h NUMERIC(30, 7) DEFAULT NULL, trades24h BIGINT DEFAULT NULL, trustlines_total INT DEFAULT NULL, supply NUMERIC(36, 0) DEFAULT NULL, sparkline1h JSON DEFAULT NULL, updated_at DATETIME NOT NULL, asset_id INT NOT NULL, INDEX idx_market_asset_snapshot_network_rank (network, rank_position, id), INDEX idx_market_asset_snapshot_network_updated (network, updated_at), UNIQUE INDEX uniq_market_asset_snapshot_asset (asset_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE orders (id INT AUTO_INCREMENT NOT NULL, uuid VARCHAR(36) NOT NULL, account_address VARCHAR(56) NOT NULL, network INT DEFAULT 1 NOT NULL, label_name VARCHAR(50) NOT NULL, order_type VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL, email VARCHAR(255) DEFAULT NULL, payment_tx_hash VARCHAR(64) DEFAULT NULL, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_E52FFDEED17F50A6 (uuid), INDEX idx_orders_account_status_expires (account_address, status, expires_at), INDEX idx_orders_uuid (uuid), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE account_metric ADD CONSTRAINT FK_A0E2E0909B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id)');
        $this->addSql('ALTER TABLE account_metric_interval ADD CONSTRAINT FK_F3606B249B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE asset_metric_history ADD CONSTRAINT FK_3CF846D25DA1941 FOREIGN KEY (asset_id) REFERENCES asset (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE asset_statistic ADD CONSTRAINT FK_89A1AEA65DA1941 FOREIGN KEY (asset_id) REFERENCES asset (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE contract_events ADD CONSTRAINT FK_6FC7B2072576E0FD FOREIGN KEY (contract_id) REFERENCES contracts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE contract_storage_entries ADD CONSTRAINT FK_899AFD5D2576E0FD FOREIGN KEY (contract_id) REFERENCES contracts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE contract_transactions ADD CONSTRAINT FK_F3F0F982576E0FD FOREIGN KEY (contract_id) REFERENCES contracts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE contracts ADD CONSTRAINT FK_950A973993544C7 FOREIGN KEY (wasm_id) REFERENCES contract_sources (wasm_id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE market_asset_snapshot ADD CONSTRAINT FK_FF69E1925DA1941 FOREIGN KEY (asset_id) REFERENCES asset (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE account_metric DROP FOREIGN KEY FK_A0E2E0909B6B5FBA');
        $this->addSql('ALTER TABLE account_metric_interval DROP FOREIGN KEY FK_F3606B249B6B5FBA');
        $this->addSql('ALTER TABLE asset_metric_history DROP FOREIGN KEY FK_3CF846D25DA1941');
        $this->addSql('ALTER TABLE asset_statistic DROP FOREIGN KEY FK_89A1AEA65DA1941');
        $this->addSql('ALTER TABLE contract_events DROP FOREIGN KEY FK_6FC7B2072576E0FD');
        $this->addSql('ALTER TABLE contract_storage_entries DROP FOREIGN KEY FK_899AFD5D2576E0FD');
        $this->addSql('ALTER TABLE contract_transactions DROP FOREIGN KEY FK_F3F0F982576E0FD');
        $this->addSql('ALTER TABLE contracts DROP FOREIGN KEY FK_950A973993544C7');
        $this->addSql('ALTER TABLE market_asset_snapshot DROP FOREIGN KEY FK_FF69E1925DA1941');
        $this->addSql('DROP TABLE account');
        $this->addSql('DROP TABLE account_metric');
        $this->addSql('DROP TABLE account_metric_interval');
        $this->addSql('DROP TABLE asset');
        $this->addSql('DROP TABLE asset_metric_history');
        $this->addSql('DROP TABLE asset_statistic');
        $this->addSql('DROP TABLE contract_events');
        $this->addSql('DROP TABLE contract_sources');
        $this->addSql('DROP TABLE contract_storage_entries');
        $this->addSql('DROP TABLE contract_transactions');
        $this->addSql('DROP TABLE contracts');
        $this->addSql('DROP TABLE market_asset_snapshot');
        $this->addSql('DROP TABLE orders');
    }
}
