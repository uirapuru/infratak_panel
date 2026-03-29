<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260329202000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add server lifecycle timestamps for start and end of runtime';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE server ADD started_at DATETIME DEFAULT NULL, ADD ended_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE server DROP started_at, DROP ended_at');
    }
}