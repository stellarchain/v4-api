<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260222194500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create market_overview_snapshot table for precomputed XLM/global market indicators.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE market_overview_snapshot (
            id INT AUTO_INCREMENT NOT NULL,
            network INT NOT NULL,
            xlm_price_usd NUMERIC(20, 10) DEFAULT NULL,
            xlm_volume24h NUMERIC(30, 7) DEFAULT NULL,
            total_trades24h BIGINT NOT NULL,
            active_assets24h INT NOT NULL,
            tracked_assets INT NOT NULL,
            total_accounts INT NOT NULL,
            total_contracts INT NOT NULL,
            recorded_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX uniq_market_overview_snapshot_network (network),
            INDEX idx_market_overview_snapshot_network_recorded (network, recorded_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE market_overview_snapshot');
    }
}

