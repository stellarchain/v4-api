<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260301192000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add derived scalable tables for contract argument usages and holder balances.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('contract_argument_usages')) {
            $this->addSql(
                "CREATE TABLE contract_argument_usages (
                    id INT AUTO_INCREMENT NOT NULL,
                    contract_transaction_id INT NOT NULL,
                    target_contract_id INT NOT NULL,
                    target_contract_address VARCHAR(300) NOT NULL,
                    referenced_contract_id VARCHAR(300) NOT NULL,
                    network INT NOT NULL,
                    tx_hash VARCHAR(300) NOT NULL,
                    source_account VARCHAR(300) DEFAULT NULL,
                    ledger INT DEFAULT NULL,
                    function_name VARCHAR(128) NOT NULL,
                    matched_paths JSON DEFAULT NULL,
                    matches_count INT NOT NULL DEFAULT 0,
                    created_at DATETIME DEFAULT NULL,
                    updated_at DATETIME NOT NULL,
                    INDEX idx_cau_referenced_network_id (referenced_contract_id, network, id),
                    INDEX idx_cau_target_network_id (target_contract_id, network, id),
                    UNIQUE INDEX uniq_cau_tx_ref_fn (contract_transaction_id, referenced_contract_id, function_name),
                    PRIMARY KEY(id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB"
            );
            $this->addSql('ALTER TABLE contract_argument_usages ADD CONSTRAINT FK_CAU_TX FOREIGN KEY (contract_transaction_id) REFERENCES contract_transactions (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE contract_argument_usages ADD CONSTRAINT FK_CAU_TARGET_CONTRACT FOREIGN KEY (target_contract_id) REFERENCES contracts (id) ON DELETE CASCADE');
        }

        if (!$schema->hasTable('contract_holder_balances')) {
            $this->addSql(
                "CREATE TABLE contract_holder_balances (
                    id INT AUTO_INCREMENT NOT NULL,
                    contract_id INT NOT NULL,
                    network INT NOT NULL,
                    holder_address VARCHAR(300) NOT NULL,
                    balance_raw DECIMAL(65, 0) NOT NULL,
                    inflow_raw DECIMAL(65, 0) NOT NULL,
                    outflow_raw DECIMAL(65, 0) NOT NULL,
                    updated_at DATETIME NOT NULL,
                    INDEX idx_chb_holder_network_balance (holder_address, network, balance_raw),
                    INDEX idx_chb_contract_network_balance (contract_id, network, balance_raw),
                    UNIQUE INDEX uniq_chb_contract_holder (contract_id, holder_address),
                    PRIMARY KEY(id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB"
            );
            $this->addSql('ALTER TABLE contract_holder_balances ADD CONSTRAINT FK_CHB_CONTRACT FOREIGN KEY (contract_id) REFERENCES contracts (id) ON DELETE CASCADE');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('contract_holder_balances')) {
            $this->addSql('DROP TABLE contract_holder_balances');
        }

        if ($schema->hasTable('contract_argument_usages')) {
            $this->addSql('DROP TABLE contract_argument_usages');
        }
    }
}

