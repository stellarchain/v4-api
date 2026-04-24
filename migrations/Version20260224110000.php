<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260224110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create account_balance_snapshot table for 24h account balance change calculations.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE account_balance_snapshot (
            id INT AUTO_INCREMENT NOT NULL,
            account_id INT NOT NULL,
            recorded_hour DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            native_balance NUMERIC(36, 7) NOT NULL DEFAULT \'0.0000000\',
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_account_balance_snapshot_account_hour (account_id, recorded_hour),
            UNIQUE INDEX uniq_account_balance_snapshot_account_hour (account_id, recorded_hour),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE account_balance_snapshot
            ADD CONSTRAINT FK_ACCOUNT_BALANCE_SNAPSHOT_ACCOUNT
            FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account_balance_snapshot DROP FOREIGN KEY FK_ACCOUNT_BALANCE_SNAPSHOT_ACCOUNT');
        $this->addSql('DROP TABLE account_balance_snapshot');
    }
}
