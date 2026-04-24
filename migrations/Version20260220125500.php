<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260220125500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add first/last transaction timestamps to account_metric for API exposure.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account_metric ADD first_transaction_at DATETIME DEFAULT NULL, ADD last_transaction_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account_metric DROP first_transaction_at, DROP last_transaction_at');
    }
}

