<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Client;
use App\Entity\Prestation;
use App\Entity\RecompenseFidelite;
use App\Entity\Reservation;
use App\Entity\User;
use App\Enum\MotifMouvementPoints;
use App\Enum\StatutReservation;
use App\Repository\ClientRepository;
use App\Repository\MouvementPointsRepository;
use App\Repository\PrestationRepository;
use App\Repository\RecompenseFideliteRepository;
use App\Repository\ReservationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Comptes clientes, espace cliente et programme de fidélité, sur les données de démonstration.
 */
final class CompteFideliteTest extends WebTestCase
{
    private const MOT_DE_PASSE = 'un-mot-de-passe-solide';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->commande('app:demo:charger', ['--purger' => true]);
    }

    // ---------- Inscription et confirmation ----------

    public function testUneNouvelleClienteSInscritConfirmeSonEmailEtRecoitSonBonus(): void
    {
        $this->inscrire('nouvelle@example.com', '06 39 98 70 01');

        self::assertSelectorTextContains('h1', 'Plus qu\'une étape');
        self::assertEmailCount(1);
        // Les emails capturés ne concernent que la dernière requête : le lien est lu tout de suite.
        $lien = $this->lienDuDernierEmail('/inscription/confirmer');

        // Connexion refusée tant que l'email n'est pas confirmé
        $this->seConnecter('nouvelle@example.com');
        self::assertSelectorTextContains('#erreur-connexion', 'Confirmez d\'abord votre adresse email');

        $this->client->request('GET', $lien);
        self::assertResponseRedirects('/connexion');

        $this->seConnecter('nouvelle@example.com');
        self::assertResponseRedirects('/apres-connexion');
        $this->client->followRedirect();
        self::assertResponseRedirects('/compte');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.carte-fidelite__solde', '20');
    }

    public function testUneClienteExistanteRetrouveSaFicheEtSesPointsParSonEmail(): void
    {
        $emma = $this->cliente('Emma');
        $soldeAvant = $this->solde($emma);

        $this->inscrire((string) $emma->getEmail(), '06 39 98 70 02');
        $this->client->request('GET', $this->lienDuDernierEmail('/inscription/confirmer'));

        $emma = $this->cliente('Emma');
        self::assertNotNull($emma->getUser(), 'La fiche est rattachée au compte confirmé.');
        self::assertSame($soldeAvant + 20, $this->solde($emma));
        self::assertNull(static::getContainer()->get(ClientRepository::class)->findOneBy(['telephone' => '+33639987002']), 'Aucune fiche en double.');
    }

    public function testLeTelephoneDUneAutreClienteNePermetPasDeRecupererSesPoints(): void
    {
        $emma = $this->cliente('Emma');

        $this->inscrire('usurpatrice@example.com', (string) $emma->getTelephone());
        $this->client->request('GET', $this->lienDuDernierEmail('/inscription/confirmer'));

        self::assertNull($this->cliente('Emma')->getUser(), 'Fiche non rattachée : l\'email ne correspond pas.');

        $this->seConnecter('usurpatrice@example.com');
        $this->client->followRedirects();
        $this->client->request('GET', '/compte');
        self::assertSelectorTextContains('main', 'bientôt relié à votre fiche');
        self::assertSelectorNotExists('.carte-fidelite');
    }

    public function testUneAdresseDejaUtiliseeNeLeRevelePasEtPrevientParEmail(): void
    {
        $this->creerCompteVerifie($this->cliente('Emma'));

        $this->inscrire((string) $this->cliente('Emma')->getEmail(), '06 39 98 70 03');

        self::assertSelectorTextContains('h1', 'Plus qu\'une étape');
        self::assertEmailSubjectContains(self::getMailerMessage() ?? throw new \LogicException(), 'Vous avez déjà un compte');
        self::assertCount(1, static::getContainer()->get(UserRepository::class)->findBy(['email' => $this->cliente('Emma')->getEmail()]));
    }

    public function testLeLienDeReinitialisationFonctionneUneSeuleFois(): void
    {
        $user = $this->creerCompteVerifie($this->cliente('Emma'));

        $crawler = $this->client->request('GET', '/mot-de-passe-oublie');
        $this->client->submit($crawler->selectButton('Envoyer')->form(['email_seul[email]' => $user->getEmail()]));
        $lien = $this->lienDuDernierEmail('/mot-de-passe-oublie/nouveau');

        $crawler = $this->client->request('GET', $lien);
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'nouveau_mot_de_passe[motDePasse][first]' => 'nouveau-mot-de-passe-2026',
            'nouveau_mot_de_passe[motDePasse][second]' => 'nouveau-mot-de-passe-2026',
        ]));
        self::assertResponseRedirects('/connexion');

        $this->client->request('GET', $lien);
        self::assertResponseRedirects('/mot-de-passe-oublie', message: 'Lien déjà utilisé.');

        $this->seConnecter((string) $user->getEmail(), 'nouveau-mot-de-passe-2026');
        self::assertResponseRedirects('/apres-connexion');
    }

    public function testLaConnexionNeRedirigeJamaisVersUnSiteExterne(): void
    {
        foreach (['//pirate.example', 'https://pirate.example', '/\\pirate.example'] as $cible) {
            $crawler = $this->client->request('GET', '/connexion?cible='.urlencode($cible));
            self::assertCount(0, $crawler->filter('input[name="_target_path"]'), $cible);
        }

        $crawler = $this->client->request('GET', '/connexion?cible=/reservation/1');
        self::assertSame('/reservation/1', $crawler->filter('input[name="_target_path"]')->attr('value'));
    }

    // ---------- Espace cliente ----------

    public function testLEspaceClienteEstReserveAuxConnectees(): void
    {
        $this->client->request('GET', '/compte');

        self::assertResponseRedirects('/connexion');
    }

    public function testLExportNeContientQueLesDonneesDeLaCliente(): void
    {
        $emma = $this->cliente('Emma');
        $this->client->loginUser($this->creerCompteVerifie($emma));

        $this->client->request('GET', '/compte/donnees/export');

        self::assertResponseIsSuccessful();
        $donnees = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('Emma', $donnees['fiche']['prenom']);
        self::assertNotEmpty($donnees['rendez_vous']);
        self::assertNotNull($donnees['fiche']['notes_sante'], 'Les notes santé, déchiffrées, font partie de ses données.');
        self::assertStringNotContainsString('Léa', (string) $this->client->getResponse()->getContent());
    }

    public function testLaSuppressionDuCompteAnonymiseLaFiche(): void
    {
        $emma = $this->cliente('Emma');
        // Pas de rendez-vous à venir pour pouvoir supprimer
        foreach (static::getContainer()->get(ReservationRepository::class)->findPourClient($emma, 1000) as $r) {
            if (\in_array($r->getStatut(), StatutReservation::bloquantLeCreneau(), true)) {
                $r->changerStatut(StatutReservation::ANNULEE, new \DateTimeImmutable());
            }
        }
        $this->client->loginUser($this->creerCompteVerifie($emma));

        $crawler = $this->client->request('GET', '/compte/donnees');
        $formulaire = $crawler->selectButton('Supprimer mon compte')->form();
        $case = $formulaire['confirmation'];
        self::assertInstanceOf(ChoiceFormField::class, $case);
        $case->tick();
        $this->client->submit($formulaire);

        self::assertResponseRedirects('/');
        $fiche = static::getContainer()->get(ClientRepository::class)->find($emma->getId());
        self::assertInstanceOf(Client::class, $fiche);
        self::assertTrue($fiche->estAnonymise());
        self::assertNull(static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'emma.martin@example.com']));
    }

    // ---------- Réservation avec compte ----------

    public function testUneClienteConnecteeUtiliseUnPalierEtRecupereSesPointsSiRefus(): void
    {
        $emma = $this->cliente('Emma');
        $this->crediter($emma, 150);
        $soldeAvant = $this->solde($emma);
        $this->client->loginUser($this->creerCompteVerifie($emma));

        $crawler = $this->pageCoordonnees('Pose complète gel');
        self::assertSelectorTextContains('.fidelite-tunnel', 'Vous avez');
        $formulaire = $crawler->selectButton('Continuer vers l\'acompte')->form();
        $palier = $this->palier(100);
        $choix = $formulaire['coordonnees[recompense]'];
        self::assertInstanceOf(ChoiceFormField::class, $choix);
        $choix->select((string) $palier->getId());
        $this->cocherConditions($formulaire);
        $this->client->submit($formulaire);

        $reservation = $this->derniereReservation();
        self::assertSame($emma->getId(), $reservation->getClient()->getId());
        self::assertSame(500, $reservation->getReductionCentimes());
        self::assertSame(100, $reservation->getPointsUtilises());
        self::assertSame(1500, $reservation->getAcompteCentimes(), '30 % de 50 € (55 € − 5 €).');
        self::assertSame($soldeAvant - 100, $this->solde($emma));

        $reservation->changerStatut(StatutReservation::EN_ATTENTE, new \DateTimeImmutable());
        static::getContainer()->get(\App\Service\Reservation\ReservationWorkflow::class)->refuser($reservation, 'test');
        self::assertSame($soldeAvant, $this->solde($emma), 'Points rendus au refus.');
    }

    public function testUnPalierNePeutPasDepasserLaMoitieDuPrix(): void
    {
        $emma = $this->cliente('Emma');
        $this->crediter($emma, 400);
        $this->client->loginUser($this->creerCompteVerifie($emma));

        // Semi-permanent à 30 € : 18 € dépasse 50 % du prix, seul 5 € est proposé.
        $crawler = $this->pageCoordonnees('Semi-permanent mains');

        $choix = $crawler->filter('input[name="coordonnees[recompense]"]')->each(static fn ($n): string => (string) $n->attr('value'));
        self::assertContains((string) $this->palier(100)->getId(), $choix);
        self::assertNotContains((string) $this->palier(300)->getId(), $choix);
    }

    public function testCreerSonCompteEnReservantRattacheLaNouvelleFiche(): void
    {
        $crawler = $this->pageCoordonnees('Semi-permanent mains');
        $formulaire = $crawler->selectButton('Continuer vers l\'acompte')->form();
        $formulaire['coordonnees[prenom]'] = 'Nina';
        $formulaire['coordonnees[nom]'] = 'Test';
        $formulaire['coordonnees[telephone]'] = '06 39 98 70 04';
        $formulaire['coordonnees[email]'] = 'nina@example.com';
        $case = $formulaire['coordonnees[creerCompte]'];
        self::assertInstanceOf(ChoiceFormField::class, $case);
        $case->tick();
        $formulaire['coordonnees[motDePasse]'] = self::MOT_DE_PASSE;
        $this->cocherConditions($formulaire);
        $this->client->submit($formulaire);
        self::assertResponseRedirects();

        $this->client->request('GET', $this->lienDuDernierEmail('/inscription/confirmer'));

        $nina = static::getContainer()->get(ClientRepository::class)->findOneBy(['telephone' => '+33639987004']);
        self::assertInstanceOf(Client::class, $nina);
        self::assertSame('nina@example.com', $nina->getUser()?->getEmail());
    }

    public function testUneReservationAnonymeNeModifiePasLaFicheExistante(): void
    {
        $emma = $this->cliente('Emma');
        $emailAvant = $emma->getEmail();

        $crawler = $this->pageCoordonnees('Semi-permanent mains');
        $formulaire = $crawler->selectButton('Continuer vers l\'acompte')->form();
        $formulaire['coordonnees[prenom]'] = 'Emma';
        $formulaire['coordonnees[nom]'] = 'Martin';
        $formulaire['coordonnees[telephone]'] = (string) $emma->getTelephone();
        $formulaire['coordonnees[email]'] = 'autre.adresse@example.com';
        $this->cocherConditions($formulaire);
        $this->client->submit($formulaire);

        $reservation = $this->derniereReservation();
        self::assertSame($emailAvant, $this->cliente('Emma')->getEmail());
        self::assertSame('autre.adresse@example.com', $reservation->getEmailNotification());
    }

    public function testUneDateAuDelaDeLHorizonEstRefusee(): void
    {
        $prestation = $this->prestation('Semi-permanent mains');
        $loin = (new \DateTimeImmutable('+40 days'))->modify('tuesday this week')->setTime(10, 0);

        $this->client->request('GET', '/reservation/'.$prestation->getId().'/'.$loin->format('Y-m-d\TH:i'));

        self::assertResponseRedirects('/reservation/'.$prestation->getId());
    }

    public function testUnRendezVousHonoreRapporteUnPointParEuroPaye(): void
    {
        $emma = $this->cliente('Emma');
        $reservation = new Reservation($emma, $this->prestation('Semi-permanent mains'), new \DateTimeImmutable('-3 hours'), 900, 500, 100);
        $reservation->changerStatut(StatutReservation::EN_ATTENTE, new \DateTimeImmutable('-2 days'));
        $reservation->changerStatut(StatutReservation::CONFIRMEE, new \DateTimeImmutable('-2 days'));
        static::getContainer()->get(EntityManagerInterface::class)->persist($reservation);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        static::getContainer()->get(\App\Service\Reservation\ReservationWorkflow::class)->honorer($reservation, 'test');

        $visite = static::getContainer()->get(MouvementPointsRepository::class)->findOneBy(['reservation' => $reservation, 'motif' => MotifMouvementPoints::VISITE]);
        self::assertSame(25, $visite?->getDelta(), '30 € − 5 € de réduction = 25 points.');
    }

    // ---------- Expiration ----------

    public function testLesPointsSontAnnoncesPuisExpires(): void
    {
        $emma = $this->cliente('Emma');
        $lea = $this->cliente('Léa');
        $this->viderPoints($emma);
        $this->viderPoints($lea);
        $this->crediter($emma, 80, new \DateTimeImmutable('-13 months'));
        $this->crediter($lea, 60, new \DateTimeImmutable('-11 months -10 days'));

        $this->commande('app:fidelite:expirer');

        self::assertSame(0, $this->solde($emma), 'Solde expiré après 12 mois sans gain.');
        self::assertSame(60, $this->solde($lea));
        self::assertEmailCount(1);

        $this->commande('app:fidelite:expirer');
        self::assertEmailCount(1, message: 'Un seul avertissement.');
    }

    // ---------- Admin ----------

    public function testLAdminRemetUnAvantageAuSalonEtModifieLesReglages(): void
    {
        $emma = $this->cliente('Emma');
        $this->crediter($emma, 200);
        $solde = $this->solde($emma);
        $admin = new User('admin@example.com');
        $admin->setRoles([User::ROLE_ADMIN])->setPassword('x');
        $admin->activerTotp('JBSWY3DPEHPK3PXP', new \DateTimeImmutable());
        static::getContainer()->get(EntityManagerInterface::class)->persist($admin);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/admin/client/'.$emma->getId());
        $this->client->submit($crawler->selectButton('Remettre et déduire les points')->form(['recompense' => (string) $this->palier(150)->getId()]));
        self::assertSame($solde - 150, $this->solde($emma));

        $crawler = $this->client->request('GET', '/admin/reglages');
        $formulaire = $crawler->selectButton('Enregistrer')->form();
        $formulaire['reglages[fidelite_bonus_inscription]'] = '30';
        $this->client->submit($formulaire);
        self::assertResponseRedirects('/admin/reglages');
        $this->client->request('GET', '/conditions-de-reservation');
        self::assertSelectorTextContains('#fidelite + ul', '30 points de bienvenue');
    }

    // ---------- Outils ----------

    private function inscrire(string $email, string $telephone): void
    {
        $crawler = $this->client->request('GET', '/inscription');
        $formulaire = $crawler->selectButton('Créer mon compte')->form([
            'inscription[prenom]' => 'Test',
            'inscription[nom]' => 'Cliente',
            'inscription[telephone]' => $telephone,
            'inscription[email]' => $email,
            'inscription[motDePasse]' => self::MOT_DE_PASSE,
        ]);
        $case = $formulaire['inscription[accepteConditions]'];
        self::assertInstanceOf(ChoiceFormField::class, $case);
        $case->tick();
        $this->client->submit($formulaire);
    }

    private function seConnecter(string $email, string $motDePasse = self::MOT_DE_PASSE): void
    {
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->submit($crawler->selectButton('Se connecter')->form(['email' => $email, 'password' => $motDePasse]));
        // Échec : retour sur la page de connexion (URL absolue), où l'erreur s'affiche.
        if (str_ends_with((string) $this->client->getResponse()->headers->get('Location'), '/connexion')) {
            $this->client->followRedirect();
        }
    }

    private function lienDuDernierEmail(string $chemin): string
    {
        $email = self::getMailerMessage(self::getMailerMessages() ? \count(self::getMailerMessages()) - 1 : 0);
        self::assertInstanceOf(\Symfony\Component\Mime\Email::class, $email);
        $html = (string) $email->getHtmlBody();
        self::assertMatchesRegularExpression('#href="(https?://[^"]*'.preg_quote($chemin, '#').'[^"]*)"#', $html);
        preg_match('#href="(https?://[^"]*'.preg_quote($chemin, '#').'[^"]*)"#', $html, $morceaux);

        return html_entity_decode($morceaux[1]);
    }

    private function creerCompteVerifie(Client $cliente): User
    {
        $container = static::getContainer();
        $user = new User((string) $cliente->getEmail());
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::MOT_DE_PASSE));
        $user->verifierEmail(new \DateTimeImmutable());
        $cliente->setUser($user);
        $container->get(EntityManagerInterface::class)->persist($user);
        $container->get(EntityManagerInterface::class)->flush();

        return $user;
    }

    private function pageCoordonnees(string $nomPrestation): \Symfony\Component\DomCrawler\Crawler
    {
        $crawler = $this->client->request('GET', '/reservation/'.$this->prestation($nomPrestation)->getId());

        return $this->client->click($crawler->filter('a.creneau')->first()->link());
    }

    private function cocherConditions(Form $formulaire): void
    {
        $case = $formulaire['coordonnees[accepteConditions]'];
        self::assertInstanceOf(ChoiceFormField::class, $case);
        $case->tick();
    }

    private function crediter(Client $cliente, int $points, ?\DateTimeImmutable $at = null): void
    {
        $mouvement = new \App\Entity\MouvementPoints($cliente, $points, MotifMouvementPoints::CORRECTION, null, 'test', 'test');
        if (null !== $at) {
            (new \ReflectionProperty($mouvement, 'createdAt'))->setValue($mouvement, $at);
        }
        static::getContainer()->get(EntityManagerInterface::class)->persist($mouvement);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    private function viderPoints(Client $cliente): void
    {
        static::getContainer()->get(EntityManagerInterface::class)
            ->createQuery('DELETE FROM App\Entity\MouvementPoints m WHERE m.client = :c')
            ->setParameter('c', $cliente)
            ->execute();
    }

    private function solde(Client $cliente): int
    {
        return static::getContainer()->get(MouvementPointsRepository::class)->soldePour($cliente);
    }

    private function cliente(string $prenom): Client
    {
        $cliente = static::getContainer()->get(ClientRepository::class)->findOneBy(['prenom' => $prenom]);
        self::assertInstanceOf(Client::class, $cliente);
        static::getContainer()->get(EntityManagerInterface::class)->refresh($cliente);

        return $cliente;
    }

    private function prestation(string $nom): Prestation
    {
        $prestation = static::getContainer()->get(PrestationRepository::class)->findOneBy(['nom' => $nom]);
        self::assertInstanceOf(Prestation::class, $prestation);

        return $prestation;
    }

    private function palier(int $seuil): RecompenseFidelite
    {
        $palier = static::getContainer()->get(RecompenseFideliteRepository::class)->findOneBy(['seuilPoints' => $seuil]);
        self::assertInstanceOf(RecompenseFidelite::class, $palier);

        return $palier;
    }

    private function derniereReservation(): Reservation
    {
        $reservation = static::getContainer()->get(ReservationRepository::class)->findOneBy([], ['id' => 'DESC']);
        self::assertInstanceOf(Reservation::class, $reservation);

        return $reservation;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function commande(string $nom, array $arguments = []): void
    {
        (new CommandTester((new Application(self::$kernel))->find($nom)))->execute($arguments);
    }
}
