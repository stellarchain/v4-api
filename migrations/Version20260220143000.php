<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260220143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align account uniqueness to composite (address, network) for multi-network imports.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account DROP INDEX UNIQ_7D3656A4D4E6F81, ADD UNIQUE INDEX uniq_account_address_network (address, network)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account DROP INDEX uniq_account_address_network, ADD UNIQUE INDEX UNIQ_7D3656A4D4E6F81 (address)');
    }
}

