<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260929091125 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Suppléments de réservation (nail art niveau 1 et 2, créés désactivés)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE supplement (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(120) NOT NULL, description LONGTEXT DEFAULT NULL, prix_centimes INT NOT NULL, duree_minutes INT NOT NULL, active TINYINT NOT NULL, ordre INT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE supplement_prestation (supplement_id INT NOT NULL, prestation_id INT NOT NULL, INDEX IDX_DECF98CD7793FA21 (supplement_id), INDEX IDX_DECF98CD9E45C554 (prestation_id), PRIMARY KEY (supplement_id, prestation_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE supplement_prestation ADD CONSTRAINT FK_DECF98CD7793FA21 FOREIGN KEY (supplement_id) REFERENCES supplement (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE supplement_prestation ADD CONSTRAINT FK_DECF98CD9E45C554 FOREIGN KEY (prestation_id) REFERENCES prestation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reservation ADD supplement_nom VARCHAR(120) DEFAULT NULL, ADD supplement_prix_centimes INT DEFAULT 0 NOT NULL, ADD supplement_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE reservation ALTER supplement_prix_centimes DROP DEFAULT');
        $this->addSql('ALTER TABLE reservation ADD CONSTRAINT FK_42C849557793FA21 FOREIGN KEY (supplement_id) REFERENCES supplement (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_42C849557793FA21 ON reservation (supplement_id)');
        // Créés désactivés : prix, temps et description à confirmer dans Admin → Suppléments.
        $this->addSql("INSERT INTO supplement (nom, description, prix_centimes, duree_minutes, active, ordre) VALUES ('Nail art niveau 1', 'Décor simple sur quelques ongles.', 500, 15, 0, 1), ('Nail art niveau 2', 'Décor travaillé sur plusieurs ongles.', 1000, 30, 0, 2)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE supplement_prestation DROP FOREIGN KEY FK_DECF98CD7793FA21');
        $this->addSql('ALTER TABLE supplement_prestation DROP FOREIGN KEY FK_DECF98CD9E45C554');
        $this->addSql('DROP TABLE supplement');
        $this->addSql('DROP TABLE supplement_prestation');
        $this->addSql('ALTER TABLE reservation DROP FOREIGN KEY FK_42C849557793FA21');
        $this->addSql('DROP INDEX IDX_42C849557793FA21 ON reservation');
        $this->addSql('ALTER TABLE reservation DROP supplement_nom, DROP supplement_prix_centimes, DROP supplement_id');
    }
}
