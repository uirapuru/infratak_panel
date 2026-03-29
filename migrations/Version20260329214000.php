<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260329214000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add diagnose status/log/timestamp fields to server';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE server ADD last_diagnose_status VARCHAR(16) DEFAULT NULL, ADD last_diagnose_log LONGTEXT DEFAULT NULL, ADD last_diagnosed_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE server DROP last_diagnose_status, DROP last_diagnose_log, DROP last_diagnosed_at');
    }
}
