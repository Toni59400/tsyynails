<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260928110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Double authentification TOTP des comptes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD totp_secret VARCHAR(64) DEFAULT NULL, ADD totp_active_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP totp_secret, DROP totp_active_at');
    }
}
