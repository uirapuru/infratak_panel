<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260329211000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add last_retry_at timestamp to server for manual retry tracking';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE server ADD last_retry_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE server DROP last_retry_at');
    }
}
