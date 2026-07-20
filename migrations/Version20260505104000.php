<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260505104000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add SEP-55 verification columns and GitHub repository address to contracts.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('contracts')) {
            return;
        }

        $table = $schema->getTable('contracts');

        if (!$table->hasColumn('sep55_verified')) {
            $this->addSql('ALTER TABLE contracts ADD sep55_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER source_code_verified');
        }

        if (!$table->hasColumn('github_address')) {
            $this->addSql('ALTER TABLE contracts ADD github_address VARCHAR(255) DEFAULT NULL AFTER sep55_verified');
        }

        if (!$table->hasColumn('sep55_commit_hash')) {
            $this->addSql('ALTER TABLE contracts ADD sep55_commit_hash VARCHAR(64) DEFAULT NULL AFTER github_address');
        }

        if (!$table->hasColumn('sep55_attestation_url')) {
            $this->addSql('ALTER TABLE contracts ADD sep55_attestation_url VARCHAR(255) DEFAULT NULL AFTER sep55_commit_hash');
        }

        if (!$table->hasColumn('sep55_error')) {
            $this->addSql('ALTER TABLE contracts ADD sep55_error LONGTEXT DEFAULT NULL AFTER sep55_attestation_url');
        }

        if (!$table->hasColumn('sep55_last_checked_at')) {
            $this->addSql('ALTER TABLE contracts ADD sep55_last_checked_at DATETIME DEFAULT NULL AFTER sep55_error');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('contracts')) {
            return;
        }

        $table = $schema->getTable('contracts');

        if ($table->hasColumn('sep55_last_checked_at')) {
            $this->addSql('ALTER TABLE contracts DROP COLUMN sep55_last_checked_at');
        }

        if ($table->hasColumn('sep55_error')) {
            $this->addSql('ALTER TABLE contracts DROP COLUMN sep55_error');
        }

        if ($table->hasColumn('sep55_attestation_url')) {
            $this->addSql('ALTER TABLE contracts DROP COLUMN sep55_attestation_url');
        }

        if ($table->hasColumn('sep55_commit_hash')) {
            $this->addSql('ALTER TABLE contracts DROP COLUMN sep55_commit_hash');
        }

        if ($table->hasColumn('github_address')) {
            $this->addSql('ALTER TABLE contracts DROP COLUMN github_address');
        }

        if ($table->hasColumn('sep55_verified')) {
            $this->addSql('ALTER TABLE contracts DROP COLUMN sep55_verified');
        }
    }
}
