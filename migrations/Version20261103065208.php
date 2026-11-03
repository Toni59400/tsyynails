<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Programme fidélité TsyyArt : nouvelle échelle de récompenses (suppléments offerts en ligne), statut VIP
 * (480 points cumulés, 5 → 10 → 15 %, jamais perdu).
 */
final class Version20261103065208 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Nouvelle échelle de récompenses (suppléments offerts) et statut VIP 480 points, 5/10/15 %';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client DROP privilege_perdu_at');
        $this->addSql('ALTER TABLE recompense_fidelite ADD supplement_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE recompense_fidelite ADD CONSTRAINT FK_B3F282F27793FA21 FOREIGN KEY (supplement_id) REFERENCES supplement (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_B3F282F27793FA21 ON recompense_fidelite (supplement_id)');

        // Supplément « French ou effet chrome » : créé désactivé, prix et durée à régler dans l'admin avant de l'activer.
        $this->addSql("INSERT INTO supplement (nom, description, prix_centimes, duree_minutes, active, ordre)
            SELECT 'French ou effet chrome', 'Finition French ou effet chrome.', 500, 15, 0, COALESCE(MAX(ordre), 0) + 1 FROM supplement
            WHERE NOT EXISTS (SELECT 1 FROM supplement WHERE nom = 'French ou effet chrome')");

        // Les récompenses ne sont référencées nulle part (les points dépensés sont des mouvements) : on remplace l'échelle.
        $this->addSql('DELETE FROM recompense_fidelite');
        $this->addSql("INSERT INTO recompense_fidelite (nom, description, seuil_points, type, valeur_centimes, active, supplement_id) VALUES
            ('Nail Art niveau 1 offert', 'Ajoutez l''option Nail art niveau 1 en réservant en ligne : elle vous est offerte.', 100, 'supplement', 0, 1, (SELECT id FROM supplement WHERE nom = 'Nail art niveau 1' ORDER BY id LIMIT 1)),
            ('5 € de réduction', 'Déduits du prix de votre rendez-vous en réservant en ligne.', 150, 'reduction', 500, 1, NULL),
            ('French ou effet chrome offert', 'Ajoutez l''option French ou effet chrome en réservant en ligne : elle vous est offerte.', 200, 'supplement', 0, 1, (SELECT id FROM supplement WHERE nom = 'French ou effet chrome' ORDER BY id LIMIT 1)),
            ('10 € de réduction', 'Déduits du prix de votre rendez-vous en réservant en ligne.', 300, 'reduction', 1000, 1, NULL),
            ('Nail Art niveau 2 offert', 'Ajoutez l''option Nail art niveau 2 en réservant en ligne : elle vous est offerte.', 400, 'supplement', 0, 1, (SELECT id FROM supplement WHERE nom = 'Nail art niveau 2' ORDER BY id LIMIT 1))");

        // Statut VIP : 480 points cumulés, 5 % au départ, +5 % par année confirmée (8 rendez-vous), plafond 15 %.
        foreach (['privilege.seuil_points' => 480, 'privilege.reduction_depart' => 5, 'privilege.reduction_pas' => 5, 'privilege.reduction_max' => 15, 'privilege.visites_annuelles' => 8] as $cle => $valeur) {
            $this->addSql('UPDATE parametre SET valeur = :valeur WHERE cle = :cle', ['valeur' => (string) $valeur, 'cle' => $cle]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client ADD privilege_perdu_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE recompense_fidelite DROP FOREIGN KEY FK_B3F282F27793FA21');
        $this->addSql('DROP INDEX IDX_B3F282F27793FA21 ON recompense_fidelite');
        $this->addSql('ALTER TABLE recompense_fidelite DROP supplement_id');
        $this->addSql("UPDATE recompense_fidelite SET type = 'en_salon' WHERE type = 'supplement'");
    }
}
