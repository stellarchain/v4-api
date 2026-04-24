<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260221170500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist contract counters for fast API sorting/filtering.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contracts ADD total_transactions INT DEFAULT 0 NOT NULL, ADD total_operations INT DEFAULT 0 NOT NULL, ADD total_events INT DEFAULT 0 NOT NULL, ADD total_effects INT DEFAULT 0 NOT NULL, ADD total_storage_entries INT DEFAULT 0 NOT NULL, ADD total_invokes INT DEFAULT 0 NOT NULL');
        $this->addSql('CREATE INDEX idx_contract_network_total_invokes ON contracts (network, total_invokes, id)');
        $this->addSql('CREATE INDEX idx_contract_network_total_transactions ON contracts (network, total_transactions, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_contract_network_total_invokes ON contracts');
        $this->addSql('DROP INDEX idx_contract_network_total_transactions ON contracts');
        $this->addSql('ALTER TABLE contracts DROP total_transactions, DROP total_operations, DROP total_events, DROP total_effects, DROP total_storage_entries, DROP total_invokes');
    }
}

