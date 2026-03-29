<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260330103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add OTS admin password tracking and one-time reveal fields to server';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE server ADD ots_admin_password_current LONGTEXT DEFAULT NULL, ADD ots_admin_password_previous LONGTEXT DEFAULT NULL, ADD ots_admin_password_pending_reveal LONGTEXT DEFAULT NULL, ADD ots_admin_password_rotated_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE server DROP ots_admin_password_current, DROP ots_admin_password_previous, DROP ots_admin_password_pending_reveal, DROP ots_admin_password_rotated_at');
    }
}
