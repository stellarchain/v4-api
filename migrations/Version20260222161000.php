<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260222161000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add performance indexes for heavy contract_events queries.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_contract_events_contract_id_id ON contract_events (contract_id, id)');
        $this->addSql('CREATE INDEX idx_contract_events_contract_ledger_closed ON contract_events (contract_id, ledger_closed_at)');
        $this->addSql('CREATE INDEX idx_contract_events_contract_event_type ON contract_events (contract_id, event_type)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_contract_events_contract_id_id ON contract_events');
        $this->addSql('DROP INDEX idx_contract_events_contract_ledger_closed ON contract_events');
        $this->addSql('DROP INDEX idx_contract_events_contract_event_type ON contract_events');
    }
}
