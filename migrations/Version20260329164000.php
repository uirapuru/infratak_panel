<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260329164000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create server orchestrator table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE server (id VARCHAR(36) NOT NULL, name VARCHAR(64) NOT NULL, domain VARCHAR(255) NOT NULL, portal_domain VARCHAR(255) NOT NULL, status VARCHAR(32) NOT NULL, step VARCHAR(32) NOT NULL, aws_instance_id VARCHAR(64) DEFAULT NULL, public_ip VARCHAR(45) DEFAULT NULL, last_error LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \"(DC2Type:datetime_immutable)\", updated_at DATETIME NOT NULL COMMENT \"(DC2Type:datetime_immutable)\", UNIQUE INDEX uniq_server_name (name), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE server');
    }
}
