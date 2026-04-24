<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260224210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add sorting columns total_awarded_amount and rounds_count to projects table.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('projects')) {
            return;
        }

        $table = $schema->getTable('projects');

        if (!$table->hasColumn('total_awarded_amount')) {
            $this->addSql("ALTER TABLE projects ADD total_awarded_amount NUMERIC(20, 2) DEFAULT NULL AFTER total_awarded");
        }

        if (!$table->hasColumn('rounds_count')) {
            $this->addSql("ALTER TABLE projects ADD rounds_count INT NOT NULL DEFAULT 0 AFTER total_awarded_amount");
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('projects')) {
            return;
        }

        $table = $schema->getTable('projects');

        if ($table->hasColumn('rounds_count')) {
            $this->addSql('ALTER TABLE projects DROP COLUMN rounds_count');
        }

        if ($table->hasColumn('total_awarded_amount')) {
            $this->addSql('ALTER TABLE projects DROP COLUMN total_awarded_amount');
        }
    }
}
