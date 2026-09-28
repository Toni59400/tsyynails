<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260928125536 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Thèmes d\'inspiration, photos liées aux prestations et aux thèmes ; retire le champ photo inutilisé des prestations';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE inspiration (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(80) NOT NULL, slug VARCHAR(100) NOT NULL, description LONGTEXT DEFAULT NULL, ordre INT NOT NULL, publiee TINYINT NOT NULL, UNIQUE INDEX UNIQ_FDEC4440989D9B62 (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE photo_inspiration (photo_id INT NOT NULL, inspiration_id INT NOT NULL, INDEX IDX_F37BA89C7E9E4C8C (photo_id), INDEX IDX_F37BA89C2B726C5F (inspiration_id), PRIMARY KEY (photo_id, inspiration_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE photo_inspiration ADD CONSTRAINT FK_F37BA89C7E9E4C8C FOREIGN KEY (photo_id) REFERENCES photo (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE photo_inspiration ADD CONSTRAINT FK_F37BA89C2B726C5F FOREIGN KEY (inspiration_id) REFERENCES inspiration (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE photo ADD prestation_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE photo ADD CONSTRAINT FK_14B784189E45C554 FOREIGN KEY (prestation_id) REFERENCES prestation (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_photo_publiee_ordre ON photo (publiee, ordre)');
        $this->addSql('CREATE INDEX IDX_14B784189E45C554 ON photo (prestation_id)');
        $this->addSql('ALTER TABLE prestation DROP photo');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE photo_inspiration DROP FOREIGN KEY FK_F37BA89C7E9E4C8C');
        $this->addSql('ALTER TABLE photo_inspiration DROP FOREIGN KEY FK_F37BA89C2B726C5F');
        $this->addSql('DROP TABLE inspiration');
        $this->addSql('DROP TABLE photo_inspiration');
        $this->addSql('ALTER TABLE photo DROP FOREIGN KEY FK_14B784189E45C554');
        $this->addSql('DROP INDEX idx_photo_publiee_ordre ON photo');
        $this->addSql('DROP INDEX IDX_14B784189E45C554 ON photo');
        $this->addSql('ALTER TABLE photo DROP prestation_id');
        $this->addSql('ALTER TABLE prestation ADD photo VARCHAR(255) DEFAULT NULL');
    }
}
