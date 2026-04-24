<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260224203000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create projects table for Stellar Community Fund projects imported from JSON.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE projects (
            id INT AUTO_INCREMENT NOT NULL,
            source_url VARCHAR(255) NOT NULL,
            name VARCHAR(255) NOT NULL,
            website LONGTEXT DEFAULT NULL,
            github LONGTEXT DEFAULT NULL,
            description LONGTEXT DEFAULT NULL,
            rounds JSON DEFAULT NULL,
            submissions JSON DEFAULT NULL,
            team_size INT DEFAULT NULL,
            category VARCHAR(255) DEFAULT NULL,
            total_awarded VARCHAR(64) DEFAULT NULL,
            total_awarded_amount NUMERIC(20, 2) DEFAULT NULL,
            rounds_count INT NOT NULL DEFAULT 0,
            awarded_submissions INT DEFAULT NULL,
            image_alt VARCHAR(255) DEFAULT NULL,
            image_source_url LONGTEXT DEFAULT NULL,
            image_source_file VARCHAR(255) DEFAULT NULL,
            image_storage_key VARCHAR(255) DEFAULT NULL,
            image_public_url LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX uniq_projects_source_url (source_url),
            INDEX idx_projects_name (name),
            INDEX idx_projects_updated_at (updated_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE projects');
    }
}
