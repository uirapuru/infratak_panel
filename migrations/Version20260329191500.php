<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260329191500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create server operation log table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE server_operation_log (id INT AUTO_INCREMENT NOT NULL, server_id VARCHAR(36) NOT NULL, level VARCHAR(16) NOT NULL, status VARCHAR(32) DEFAULT NULL, step VARCHAR(32) DEFAULT NULL, message LONGTEXT NOT NULL, context_data JSON NOT NULL, created_at DATETIME NOT NULL, INDEX IDX_D79C0646184D70DE (server_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE server_operation_log ADD CONSTRAINT FK_D79C0646184D70DE FOREIGN KEY (server_id) REFERENCES server (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE server_operation_log');
    }
}
