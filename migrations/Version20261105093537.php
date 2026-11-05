<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Reconstruction d'ongles choisie en ligne : nombre d'ongles, prix et durée copiés sur la réservation.
 */
final class Version20261105093537 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reconstruction d’ongles sur les réservations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation ADD reconstruction_ongles INT NOT NULL, ADD reconstruction_prix_centimes INT NOT NULL, ADD reconstruction_minutes INT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation DROP reconstruction_ongles, DROP reconstruction_prix_centimes, DROP reconstruction_minutes');
    }
}
