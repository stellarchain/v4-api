<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260222152000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add contract_verified_metadata table to store verified contract details imported from curated JSON.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE contract_verified_metadata (id INT AUTO_INCREMENT NOT NULL, contract_id INT NOT NULL, display_name VARCHAR(255) DEFAULT NULL, metadata_type VARCHAR(64) DEFAULT NULL, is_sep41 TINYINT DEFAULT NULL, symbol VARCHAR(32) DEFAULT NULL, decimals INT DEFAULT NULL, is_verified TINYINT DEFAULT NULL, website VARCHAR(255) DEFAULT NULL, description LONGTEXT DEFAULT NULL, icon_url VARCHAR(255) DEFAULT NULL, added_at DATE DEFAULT NULL, raw_payload JSON DEFAULT NULL, source_name VARCHAR(64) DEFAULT \'verified_contracts_json\' NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_contract_verified_metadata_contract (contract_id), INDEX idx_contract_verified_metadata_symbol (symbol), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE contract_verified_metadata ADD CONSTRAINT FK_CONTRACT_VERIFIED_METADATA_CONTRACT FOREIGN KEY (contract_id) REFERENCES contracts (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contract_verified_metadata DROP FOREIGN KEY FK_CONTRACT_VERIFIED_METADATA_CONTRACT');
        $this->addSql('DROP TABLE contract_verified_metadata');
    }
}
