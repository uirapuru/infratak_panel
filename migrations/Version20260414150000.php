<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260414150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create promo_code table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE promo_code (
                id          INT AUTO_INCREMENT NOT NULL,
                code        VARCHAR(64)  NOT NULL,
                duration_days INT         NOT NULL DEFAULT 1,
                max_uses    INT          DEFAULT NULL,
                used_count  INT          NOT NULL DEFAULT 0,
                expires_at  DATETIME     DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                is_active   TINYINT(1)   NOT NULL DEFAULT 1,
                created_at  DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX UNIQ_promo_code_code (code),
                INDEX IDX_promo_code_code (code),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE promo_code');
    }
}
