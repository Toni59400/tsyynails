<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025160217 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'RDV duo, majoration du soir, remises et réduction fidélité à vie';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client ADD privilege_pourcentage INT DEFAULT 0 NOT NULL, ADD privilege_depuis DATETIME DEFAULT NULL, ADD privilege_revision_at DATETIME DEFAULT NULL, ADD privilege_averti_at DATETIME DEFAULT NULL, ADD privilege_perdu_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE reservation ADD prestation_duo_nom VARCHAR(120) DEFAULT NULL, ADD prestation_duo_prix_centimes INT DEFAULT 0 NOT NULL, ADD majoration_centimes INT DEFAULT 0 NOT NULL, ADD remise_pourcentage INT DEFAULT 0 NOT NULL, ADD remise_centimes INT DEFAULT 0 NOT NULL, ADD remise_motif VARCHAR(20) DEFAULT NULL, ADD prestation_duo_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE reservation ADD CONSTRAINT FK_42C8495573AD40DA FOREIGN KEY (prestation_duo_id) REFERENCES prestation (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_42C8495573AD40DA ON reservation (prestation_duo_id)');
        // Valeurs par défaut utiles seulement pour les lignes existantes.
        $this->addSql('ALTER TABLE client ALTER privilege_pourcentage DROP DEFAULT');
        $this->addSql('ALTER TABLE reservation ALTER prestation_duo_prix_centimes DROP DEFAULT, ALTER majoration_centimes DROP DEFAULT, ALTER remise_pourcentage DROP DEFAULT, ALTER remise_centimes DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client DROP privilege_pourcentage, DROP privilege_depuis, DROP privilege_revision_at, DROP privilege_averti_at, DROP privilege_perdu_at');
        $this->addSql('ALTER TABLE reservation DROP FOREIGN KEY FK_42C8495573AD40DA');
        $this->addSql('DROP INDEX IDX_42C8495573AD40DA ON reservation');
        $this->addSql('ALTER TABLE reservation DROP prestation_duo_nom, DROP prestation_duo_prix_centimes, DROP majoration_centimes, DROP remise_pourcentage, DROP remise_centimes, DROP remise_motif, DROP prestation_duo_id');
    }
}
