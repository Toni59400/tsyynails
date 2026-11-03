<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lien d'abonnement à l'agenda (flux iCal) de l'admin.
 */
final class Version20261103201321 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Jeton du lien d’abonnement à l’agenda';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD jeton_agenda VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649699851E8 ON `user` (jeton_agenda)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_8D93D649699851E8 ON `user`');
        $this->addSql('ALTER TABLE `user` DROP jeton_agenda');
    }
}
