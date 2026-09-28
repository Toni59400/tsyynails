<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Entity\Prestation;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Référencement : page par prestation (adresse lisible, texte détaillé) et FAQ.
 */
final class Version20260928171343 extends AbstractMigration
{
    /** FAQ de départ, à relire et compléter dans l'admin. */
    private const FAQ = [
        ['Comment réserver un rendez-vous ?',
            "En ligne, à tout moment : choisissez votre prestation, puis un créneau libre, et laissez un acompte. Je valide ensuite votre demande, en général sous 24 heures, et vous recevez la confirmation par email.\nVous pouvez aussi m'appeler ou m'écrire."],
        ['Pourquoi un acompte, et quand est-il débité ?',
            "L'acompte protège le créneau que je vous réserve. Il est seulement bloqué sur votre carte au moment de la demande : il n'est débité que si je valide le rendez-vous, puis déduit du prix le jour J. Si je ne peux pas vous recevoir, l'empreinte est libérée sans aucun frais."],
        ['Que se passe-t-il si ma demande n\'est pas validée ?',
            "Rien n'est débité : l'empreinte sur votre carte est libérée et vous êtes prévenue par email. Sans réponse de ma part sous 7 jours, la demande est annulée automatiquement."],
        ['Combien de temps tient une pose en gel ?',
            'En général 3 à 4 semaines, selon la pousse de vos ongles et leur usage au quotidien. Un remplissage toutes les 3 à 4 semaines garde des ongles solides et soignés.'],
        ['Quelle différence entre le gel et le semi-permanent ?',
            "Le semi-permanent est un vernis posé sur l'ongle naturel, sans le rallonger, qui tient environ 2 à 3 semaines. Le gel renforce l'ongle, permet de le rallonger et de le façonner, et tient plus longtemps avec un remplissage régulier."],
        ['J\'ai une allergie ou une contre-indication : que faire ?',
            "Prévenez-moi avant votre rendez-vous, par téléphone ou par email, pour que j'adapte les produits. Ces informations de santé ne sont conservées qu'avec votre accord, chiffrées, et ne sont visibles que par moi."],
        ['Comment fonctionne le programme de fidélité ?',
            "Avec un compte, chaque rendez-vous vous rapporte 1 point par euro payé. Vos points s'échangent contre des récompenses : réduction à la réservation en ligne ou avantage au salon. Le détail est dans votre espace et dans les conditions de réservation."],
    ];

    public function getDescription(): string
    {
        return 'Référencement : adresse lisible et texte détaillé des prestations, FAQ';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE question_frequente (id INT AUTO_INCREMENT NOT NULL, question VARCHAR(200) NOT NULL, reponse LONGTEXT NOT NULL, ordre INT NOT NULL, publiee TINYINT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE prestation ADD slug VARCHAR(140) DEFAULT NULL, ADD contenu LONGTEXT DEFAULT NULL');

        // Adresses des prestations existantes, avec le même algorithme que l'entité (doublons suffixés).
        $utilises = [];
        foreach ($this->connection->fetchAllAssociative('SELECT id, nom FROM prestation ORDER BY id') as $prestation) {
            $slug = Prestation::slugifier((string) $prestation['nom']);
            $base = $slug;
            for ($i = 2; isset($utilises[$slug]); ++$i) {
                $slug = $base.'-'.$i;
            }
            $utilises[$slug] = true;
            $this->addSql('UPDATE prestation SET slug = ? WHERE id = ?', [$slug, $prestation['id']]);
        }

        $this->addSql('ALTER TABLE prestation MODIFY slug VARCHAR(140) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_51C88FAD989D9B62 ON prestation (slug)');

        foreach (self::FAQ as $ordre => [$question, $reponse]) {
            $this->addSql('INSERT INTO question_frequente (question, reponse, ordre, publiee) VALUES (?, ?, ?, 1)', [$question, $reponse, $ordre]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE question_frequente');
        $this->addSql('DROP INDEX UNIQ_51C88FAD989D9B62 ON prestation');
        $this->addSql('ALTER TABLE prestation DROP slug, DROP contenu');
    }
}
