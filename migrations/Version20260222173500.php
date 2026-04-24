<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260222173500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track contract transaction enrichment checks to avoid reprocessing unchanged rows every run.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE contract_transactions
            ADD enrichment_checked_at DATETIME DEFAULT NULL,
            ADD enrichment_status VARCHAR(16) DEFAULT NULL,
            ADD enrichment_attempts INT DEFAULT 0 NOT NULL");
        $this->addSql('CREATE INDEX idx_contract_tx_enrichment_scan ON contract_transactions (contract_id, enrichment_checked_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_contract_tx_enrichment_scan ON contract_transactions');
        $this->addSql('ALTER TABLE contract_transactions
            DROP enrichment_checked_at,
            DROP enrichment_status,
            DROP enrichment_attempts');
    }
}
