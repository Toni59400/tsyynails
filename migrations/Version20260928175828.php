<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Parrainage des nouvelles clientes.
 */
final class Version20260928175828 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Parrainage : code personnel, marraine, date de la récompense';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client ADD code_parrainage VARCHAR(12) DEFAULT NULL, ADD parrainage_recompense_at DATETIME DEFAULT NULL, ADD marraine_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE client ADD CONSTRAINT FK_C74404554F02B3DF FOREIGN KEY (marraine_id) REFERENCES client (id) ON DELETE SET NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C744045531F55253 ON client (code_parrainage)');
        $this->addSql('CREATE INDEX IDX_C74404554F02B3DF ON client (marraine_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client DROP FOREIGN KEY FK_C74404554F02B3DF');
        $this->addSql('DROP INDEX UNIQ_C744045531F55253 ON client');
        $this->addSql('DROP INDEX IDX_C74404554F02B3DF ON client');
        $this->addSql('ALTER TABLE client DROP code_parrainage, DROP parrainage_recompense_at, DROP marraine_id');
    }
}
