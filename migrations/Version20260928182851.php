<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rappels, annulations remboursées et durées de conservation.
 */
final class Version20260928182851 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rappel de la veille, remboursement de l\'acompte, dernière connexion et avertissement avant suppression (RGPD)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation ADD rappel_envoye_at DATETIME DEFAULT NULL, ADD acompte_rembourse_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE user ADD derniere_connexion_at DATETIME DEFAULT NULL, ADD avertissement_suppression_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation DROP rappel_envoye_at, DROP acompte_rembourse_at');
        $this->addSql('ALTER TABLE `user` DROP derniere_connexion_at, DROP avertissement_suppression_at');
    }
}
