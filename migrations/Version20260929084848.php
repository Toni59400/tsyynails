<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260929084848 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Demandes d\x27avis Google après un rendez-vous (dernier envoi, refus de la cliente)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client ADD demande_avis_at DATETIME DEFAULT NULL, ADD refus_demandes_avis_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client DROP demande_avis_at, DROP refus_demandes_avis_at');
    }
}
