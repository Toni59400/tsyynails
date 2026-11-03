<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Questionnaire de satisfaction (affiche QR code du salon).
 */
final class Version20261103200603 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Réponses au questionnaire de satisfaction';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE retour_cliente (id INT AUTO_INCREMENT NOT NULL, note SMALLINT NOT NULL, recommande VARCHAR(12) NOT NULL, commentaire LONGTEXT DEFAULT NULL, points_credites INT NOT NULL, created_at DATETIME NOT NULL, client_id INT DEFAULT NULL, INDEX IDX_645BFA98B8E8428 (created_at), INDEX IDX_645BFA919EB6921 (client_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE retour_cliente ADD CONSTRAINT FK_645BFA919EB6921 FOREIGN KEY (client_id) REFERENCES client (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE retour_cliente DROP FOREIGN KEY FK_645BFA919EB6921');
        $this->addSql('DROP TABLE retour_cliente');
    }
}
