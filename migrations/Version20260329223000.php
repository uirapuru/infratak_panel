<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260329223000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add sleep_at field to server for scheduled AWS stop';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE server ADD sleep_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE server DROP sleep_at');
    }
}
