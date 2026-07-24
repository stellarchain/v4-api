<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260723183000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the missing asset metric history lookup indexes declared by the ORM mapping.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE asset_metric_history '
            .'ADD INDEX idx_asset_metric_history_asset_time (asset_id, recorded_at), '
            .'ADD INDEX idx_asset_metric_history_source_key_time (source, metric_key, recorded_at)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE asset_metric_history '
            .'DROP INDEX idx_asset_metric_history_asset_time, '
            .'DROP INDEX idx_asset_metric_history_source_key_time'
        );
    }
}
