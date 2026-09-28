<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Programme de fidélité et comptes clientes.
 */
final class Version20260928162139 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Programme de fidélité : paliers de récompenses, points par euro payé, comptes clientes (email vérifié), email de contact des réservations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE recompense_fidelite (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(80) NOT NULL, description LONGTEXT DEFAULT NULL, seuil_points INT NOT NULL, type VARCHAR(20) NOT NULL, valeur_centimes INT NOT NULL, active TINYINT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE client ADD avertissement_points_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE prestation DROP points');
        $this->addSql('ALTER TABLE reservation ADD email_contact VARCHAR(180) DEFAULT NULL');
        $this->addSql('ALTER TABLE user ADD email_verifie_at DATETIME DEFAULT NULL');

        // Barème validé : 100 pts = 5 €, 150 pts = nail art offert (au salon), 300 pts = 18 €.
        $this->addSql("INSERT INTO recompense_fidelite (nom, description, seuil_points, type, valeur_centimes, active) VALUES
            ('5 € de réduction', 'À utiliser en réservant en ligne.', 100, 'reduction', 500, 1),
            ('Nail art offert', 'Décor sur 10 ongles, à demander au salon.', 150, 'en_salon', 1000, 1),
            ('18 € de réduction', 'À utiliser en réservant en ligne.', 300, 'reduction', 1800, 1)");
        // L'ancien réglage « valeur d'un point » est remplacé par les paliers.
        $this->addSql("DELETE FROM parametre WHERE cle = 'fidelite.valeur_point_centimes'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE recompense_fidelite');
        $this->addSql('ALTER TABLE client DROP avertissement_points_at');
        $this->addSql('ALTER TABLE prestation ADD points INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE reservation DROP email_contact');
        $this->addSql('ALTER TABLE `user` DROP email_verifie_at');
    }
}
