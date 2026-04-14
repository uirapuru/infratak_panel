<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260414090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add server subscriptions and billing lifecycle fields';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE server ADD owner_id VARCHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL, ADD subscription_paid_until DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD subscription_expired_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD subscription_termination_queued_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX IDX_5A6DD5F67E3C61F9 ON server (owner_id)');
        $this->addSql('ALTER TABLE server ADD CONSTRAINT FK_server_owner FOREIGN KEY (owner_id) REFERENCES admin_user (id) ON DELETE SET NULL');
        $this->addSql('
            CREATE TABLE server_subscription (
                id INT AUTO_INCREMENT NOT NULL,
                server_id VARCHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci NOT NULL,
                user_id VARCHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
                days INT NOT NULL,
                amount_gross_cents INT NOT NULL,
                currency VARCHAR(3) NOT NULL,
                starts_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX IDX_server_subscription_server (server_id),
                INDEX IDX_server_subscription_user (user_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_server_subscription_server FOREIGN KEY (server_id) REFERENCES server (id) ON DELETE CASCADE,
                CONSTRAINT FK_server_subscription_user FOREIGN KEY (user_id) REFERENCES admin_user (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE server_subscription');
        $this->addSql('ALTER TABLE server DROP FOREIGN KEY FK_server_owner');
        $this->addSql('DROP INDEX IDX_5A6DD5F67E3C61F9 ON server');
        $this->addSql('ALTER TABLE server DROP owner_id, DROP subscription_paid_until, DROP subscription_expired_at, DROP subscription_termination_queued_at');
    }
}
