<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260330120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create admin_user table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE admin_user (
                id          VARCHAR(36)  NOT NULL,
                email       VARCHAR(180) NOT NULL,
                roles       JSON         NOT NULL,
                password    VARCHAR(255) NOT NULL,
                active      TINYINT(1)   NOT NULL DEFAULT 1,
                created_at  DATETIME     NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                updated_at  DATETIME     DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                PRIMARY KEY (id),
                UNIQUE INDEX UNIQ_admin_user_email (email)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE admin_user');
    }
}
