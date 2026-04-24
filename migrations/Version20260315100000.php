<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260315100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create a generic paginated time-series table for blockchain/network chart metrics.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('network_metric_point')) {
            return;
        }

        $this->addSql(
            "CREATE TABLE network_metric_point (
                id INT AUTO_INCREMENT NOT NULL,
                network INT NOT NULL,
                metric_group VARCHAR(32) NOT NULL,
                metric_key VARCHAR(64) NOT NULL,
                source VARCHAR(32) NOT NULL,
                bucket_minutes INT NOT NULL,
                bucket_start DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                bucket_end DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                value_decimal NUMERIC(36, 14) NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_network_metric_point_bucket (network, source, metric_key, bucket_minutes, bucket_start),
                INDEX idx_network_metric_point_query (network, metric_key, bucket_minutes, bucket_start, id),
                INDEX idx_network_metric_point_group (network, metric_group, bucket_minutes, bucket_start),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB"
        );
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('network_metric_point')) {
            $this->addSql('DROP TABLE network_metric_point');
        }
    }
}
