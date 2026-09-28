<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260928133729 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Réservations : jeton de la page de suivi, acceptation des conditions, statut « paiement en cours »';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation ADD jeton_suivi VARCHAR(32) DEFAULT NULL, ADD conditions_acceptees_at DATETIME DEFAULT NULL');
        // Réservations existantes : un jeton aléatoire de 128 bits en base64url, comme Reservation::__construct().
        $this->addSql("UPDATE reservation SET jeton_suivi = TRIM(TRAILING '=' FROM REPLACE(REPLACE(TO_BASE64(RANDOM_BYTES(16)), '+', '-'), '/', '_'))");
        $this->addSql('ALTER TABLE reservation MODIFY jeton_suivi VARCHAR(32) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_42C84955847920A2 ON reservation (jeton_suivi)');
        $this->addSql('CREATE INDEX idx_reservation_created ON reservation (created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_42C84955847920A2 ON reservation');
        $this->addSql('DROP INDEX idx_reservation_created ON reservation');
        $this->addSql('ALTER TABLE reservation DROP jeton_suivi, DROP conditions_acceptees_at');
    }
}
