<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261028183837 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Paiement par carte ou en espèces : mode de l\'acompte, acompte débité, règlement du reste au rendez-vous';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation ADD mode_acompte VARCHAR(10) DEFAULT NULL, ADD acompte_debite_at DATETIME DEFAULT NULL, ADD solde_mode VARCHAR(10) DEFAULT NULL, ADD solde_centimes INT DEFAULT 0 NOT NULL, ADD solde_paye_at DATETIME DEFAULT NULL, ADD solde_payment_intent_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE reservation ALTER solde_centimes DROP DEFAULT');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_42C84955DCE001B6 ON reservation (solde_payment_intent_id)');

        // Réservations existantes : celles du tunnel avaient toutes un acompte par carte.
        $this->addSql("UPDATE reservation SET mode_acompte = 'carte' WHERE stripe_payment_intent_id IS NOT NULL");
        // Acompte débité à la validation (ou remboursé ensuite, donc débité auparavant).
        $this->addSql("UPDATE reservation SET acompte_debite_at = COALESCE(decision_at, created_at) WHERE stripe_payment_intent_id IS NOT NULL AND acompte_centimes > 0 AND (statut IN ('confirmee', 'honoree', 'non_honoree') OR acompte_rembourse_at IS NOT NULL)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_42C84955DCE001B6 ON reservation');
        $this->addSql('ALTER TABLE reservation DROP mode_acompte, DROP acompte_debite_at, DROP solde_mode, DROP solde_centimes, DROP solde_paye_at, DROP solde_payment_intent_id');
    }
}
