<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260329203000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop global unique constraint on server name to allow recreating deleted servers';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_server_name ON server');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_server_name ON server (name)');
    }
}