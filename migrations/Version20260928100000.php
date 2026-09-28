<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260928100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Modèle de données initial : clientes, comptes, prestations, réservations, planning, fidélité, galerie, paramètres';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE carte_fidelite (id INT AUTO_INCREMENT NOT NULL, jeton VARCHAR(32) NOT NULL, active TINYINT NOT NULL, created_at DATETIME NOT NULL, associee_at DATETIME DEFAULT NULL, client_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_64AD2B2D2CF647B (jeton), INDEX IDX_64AD2B2D19EB6921 (client_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE client (id INT AUTO_INCREMENT NOT NULL, prenom VARCHAR(100) NOT NULL, nom VARCHAR(100) NOT NULL, telephone VARCHAR(20) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, notes_sante_chiffrees LONGTEXT DEFAULT NULL, consentement_sante_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, derniere_visite_at DATETIME DEFAULT NULL, anonymise_at DATETIME DEFAULT NULL, user_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_C7440455450FF010 (telephone), UNIQUE INDEX UNIQ_C7440455A76ED395 (user_id), INDEX idx_client_derniere_visite (derniere_visite_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE evenement_stripe (id VARCHAR(255) NOT NULL, type VARCHAR(100) NOT NULL, recu_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE horaire_ouverture (id INT AUTO_INCREMENT NOT NULL, jour_semaine SMALLINT NOT NULL, heure_debut TIME NOT NULL, heure_fin TIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE indisponibilite (id INT AUTO_INCREMENT NOT NULL, debut DATETIME NOT NULL, fin DATETIME NOT NULL, motif VARCHAR(120) DEFAULT NULL, INDEX idx_indisponibilite_periode (debut, fin), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE mouvement_points (id INT AUTO_INCREMENT NOT NULL, delta INT NOT NULL, motif VARCHAR(20) NOT NULL, auteur VARCHAR(180) DEFAULT NULL, commentaire VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, client_id INT NOT NULL, reservation_id INT DEFAULT NULL, INDEX idx_mouvement_client (client_id), UNIQUE INDEX uniq_mouvement_reservation_motif (reservation_id, motif), INDEX IDX_5DFA001FB83297E7 (reservation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE parametre (cle VARCHAR(64) NOT NULL, valeur VARCHAR(255) NOT NULL, PRIMARY KEY (cle)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE photo (id INT AUTO_INCREMENT NOT NULL, fichier VARCHAR(255) NOT NULL, legende VARCHAR(255) NOT NULL, ordre INT NOT NULL, publiee TINYINT NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE prestation (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(120) NOT NULL, description LONGTEXT DEFAULT NULL, prix_centimes INT NOT NULL, duree_minutes INT NOT NULL, points INT NOT NULL, active TINYINT NOT NULL, ordre INT NOT NULL, photo VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE reservation (id INT AUTO_INCREMENT NOT NULL, debut DATETIME NOT NULL, fin DATETIME NOT NULL, statut VARCHAR(20) NOT NULL, prix_centimes INT NOT NULL, acompte_centimes INT NOT NULL, reduction_centimes INT NOT NULL, points_utilises INT NOT NULL, stripe_payment_intent_id VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, decision_at DATETIME DEFAULT NULL, client_id INT NOT NULL, prestation_id INT NOT NULL, UNIQUE INDEX UNIQ_42C84955FC72F97E (stripe_payment_intent_id), INDEX idx_reservation_debut (debut), INDEX idx_reservation_statut (statut), INDEX IDX_42C8495519EB6921 (client_id), INDEX IDX_42C849559E45C554 (prestation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `user` (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_8D93D649E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE carte_fidelite ADD CONSTRAINT FK_64AD2B2D19EB6921 FOREIGN KEY (client_id) REFERENCES client (id)');
        $this->addSql('ALTER TABLE client ADD CONSTRAINT FK_C7440455A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mouvement_points ADD CONSTRAINT FK_5DFA001F19EB6921 FOREIGN KEY (client_id) REFERENCES client (id)');
        $this->addSql('ALTER TABLE mouvement_points ADD CONSTRAINT FK_5DFA001FB83297E7 FOREIGN KEY (reservation_id) REFERENCES reservation (id)');
        $this->addSql('ALTER TABLE reservation ADD CONSTRAINT FK_42C8495519EB6921 FOREIGN KEY (client_id) REFERENCES client (id)');
        $this->addSql('ALTER TABLE reservation ADD CONSTRAINT FK_42C849559E45C554 FOREIGN KEY (prestation_id) REFERENCES prestation (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE carte_fidelite DROP FOREIGN KEY FK_64AD2B2D19EB6921');
        $this->addSql('ALTER TABLE client DROP FOREIGN KEY FK_C7440455A76ED395');
        $this->addSql('ALTER TABLE mouvement_points DROP FOREIGN KEY FK_5DFA001F19EB6921');
        $this->addSql('ALTER TABLE mouvement_points DROP FOREIGN KEY FK_5DFA001FB83297E7');
        $this->addSql('ALTER TABLE reservation DROP FOREIGN KEY FK_42C8495519EB6921');
        $this->addSql('ALTER TABLE reservation DROP FOREIGN KEY FK_42C849559E45C554');
        $this->addSql('DROP TABLE carte_fidelite');
        $this->addSql('DROP TABLE client');
        $this->addSql('DROP TABLE evenement_stripe');
        $this->addSql('DROP TABLE horaire_ouverture');
        $this->addSql('DROP TABLE indisponibilite');
        $this->addSql('DROP TABLE mouvement_points');
        $this->addSql('DROP TABLE parametre');
        $this->addSql('DROP TABLE photo');
        $this->addSql('DROP TABLE prestation');
        $this->addSql('DROP TABLE reservation');
        $this->addSql('DROP TABLE `user`');
    }
}
