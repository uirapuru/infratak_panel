<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260330131500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create email verification token table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE email_verification_token (
                id INT AUTO_INCREMENT NOT NULL,
                user_id VARCHAR(36) NOT NULL,
                token_hash VARCHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                used_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                UNIQUE INDEX UNIQ_email_verification_token_hash (token_hash),
                INDEX IDX_email_verification_token_user (user_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_email_verification_token_user FOREIGN KEY (user_id) REFERENCES admin_user (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE email_verification_token');
    }
}
