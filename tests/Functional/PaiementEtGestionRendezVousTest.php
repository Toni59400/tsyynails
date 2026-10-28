<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Client;
use App\Entity\MouvementPoints;
use App\Entity\Parametre;
use App\Entity\Prestation;
use App\Entity\Reservation;
use App\Entity\User;
use App\Enum\MotifMouvementPoints;
use App\Enum\StatutReservation;
use App\Repository\ClientRepository;
use App\Repository\MouvementPointsRepository;
use App\Repository\PrestationRepository;
use App\Repository\ReservationRepository;
use App\Service\Paiement\SimulationPaiementGateway;
use App\Service\Reservation\CoordonneesCliente;
use App\Service\Reservation\ReservationWorkflow;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;

/**
 * Paiement en ligne par carte ou en espèces, règlement du reste au rendez-vous,
 * modification et suppression des rendez-vous et des fiches clientes dans l'admin.
 */
final class PaiementEtGestionRendezVousTest extends WebTestCase
{
    use CreationUtilisateurTrait;

    private const SEMI = 'Semi-permanent mains';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        (new CommandTester((new Application(self::$kernel))->find('app:demo:charger')))->execute(['--purger' => true]);
    }

    // ---------- Tunnel : carte ou espèces ----------

    public function testEnEspecesSeulementLaDemandePartSansAcompteNiStripe(): void
    {
        $this->regler([Parametre::PAIEMENT_CARTE_ACTIF => 0, Parametre::PAIEMENT_ESPECES_ACTIF => 1]);

        $crawler = $this->recapitulatif(self::SEMI);
        self::assertSelectorTextContains('.formulaire', 'en espèces le jour du rendez-vous');
        $formulaire = $crawler->selectButton('Envoyer ma demande')->form();
        $this->remplir($formulaire, '06 39 98 61 61');
        $this->client->submit($formulaire);

        $reservation = $this->derniereReservation();
        self::assertResponseRedirects('/reservation/suivi/'.$reservation->getJetonSuivi().'?redirect_status=succeeded');
        self::assertSame(StatutReservation::EN_ATTENTE, $reservation->getStatut());
        self::assertSame(Reservation::MODE_ESPECES, $reservation->getModeAcompte());
        self::assertSame(0, $reservation->getAcompteCentimes());
        self::assertNull($reservation->getStripePaymentIntentId());
        self::assertEmailCount(2, message: 'Accusé de réception et alerte à la prothésiste, tout de suite.');

        // Validation : rien à débiter.
        $this->workflow()->valider($reservation, 'test');
        self::assertSame(StatutReservation::CONFIRMEE, $reservation->getStatut());
        self::assertNull($reservation->getAcompteDebiteAt());
        self::assertSame(3000, $reservation->getResteAPayerCentimes());
    }

    public function testLaClienteChoisitEntreCarteEtEspecesQuandLesDeuxSontProposes(): void
    {
        $this->regler([Parametre::PAIEMENT_CARTE_ACTIF => 1, Parametre::PAIEMENT_ESPECES_ACTIF => 1]);

        $crawler = $this->recapitulatif(self::SEMI);
        self::assertCount(2, $crawler->filter('.choix-paiement input[type=radio]'));
        $formulaire = $crawler->selectButton('Continuer')->form();
        $this->remplir($formulaire, '06 39 98 62 62');
        $formulaire['coordonnees[modePaiement]'] = Reservation::MODE_ESPECES;
        $this->client->submit($formulaire);

        self::assertSame(Reservation::MODE_ESPECES, $this->derniereReservation()->getModeAcompte());
    }

    public function testSansAucunModeDePaiementLaReservationEnLigneEstFermee(): void
    {
        $this->regler([Parametre::PAIEMENT_CARTE_ACTIF => 0, Parametre::PAIEMENT_ESPECES_ACTIF => 0]);

        $this->recapitulatif(self::SEMI);

        self::assertSelectorTextContains('h1', 'Réservation par téléphone');
    }

    // ---------- Reste à payer au rendez-vous ----------

    public function testLeResteSeRegleEnEspecesDepuisLaFiche(): void
    {
        $reservation = $this->reservationCarteConfirmee();
        $this->connecterAdmin();

        $crawler = $this->client->request('GET', '/admin/reservation/'.$reservation->getId());
        self::assertSelectorTextContains('.reglement__montant', '21');
        $this->client->submit($crawler->selectButton('Espèces reçues')->form());

        $reservation = $this->recharger($reservation);
        self::assertSame(Reservation::MODE_ESPECES, $reservation->getSoldeMode());
        self::assertSame(2100, $reservation->getSoldeCentimes(), '30 € − 9 € d\'acompte.');
        self::assertNotNull($reservation->getSoldePayeAt());
    }

    public function testLeResteSeRegleParCarteAvecUnLienEtUnQrCode(): void
    {
        $reservation = $this->reservationCarteConfirmee();
        $this->connecterAdmin();

        $crawler = $this->client->request('GET', '/admin/reservation/'.$reservation->getId());
        $this->client->submit($crawler->selectButton('Paiement par carte')->form());
        $crawler = $this->client->followRedirect();
        self::assertSelectorExists('.reglement__lien img[alt="QR code du paiement par carte"]');
        $lien = (string) $crawler->filter('.reglement__lien input')->attr('value');
        self::assertStringContainsString('/reservation/reglement/'.$reservation->getJetonSuivi(), $lien);

        // La cliente ouvre le lien (simulation : pas de clé Stripe) et paie.
        $crawler = $this->client->request('GET', '/reservation/reglement/'.$reservation->getJetonSuivi());
        self::assertSelectorTextContains('h1', 'Régler 21');
        $this->client->submit($crawler->selectButton('Simuler le paiement')->form());

        $reservation = $this->recharger($reservation);
        self::assertSame(Reservation::MODE_CARTE, $reservation->getSoldeMode());
        self::assertTrue($reservation->soldeEstRegle());
        self::assertTrue($reservation->aUnPaiementParCarte());
        self::assertStringStartsWith(SimulationPaiementGateway::PREFIXE.'pay_', (string) $reservation->getSoldePaymentIntentId());
    }

    public function testLeLienCarteEstAnnuleSiLaClientePaieFinalementEnEspeces(): void
    {
        $reservation = $this->reservationCarteConfirmee();
        $this->workflow()->preparerSoldeParCarte($reservation, 'test');
        self::assertNotNull($reservation->getSoldePaymentIntentId());

        $this->workflow()->reglerSoldeEnEspeces($reservation, 'test');

        self::assertSame(Reservation::MODE_ESPECES, $reservation->getSoldeMode());
        self::assertTrue($reservation->soldeEstRegle());
        self::assertNull($reservation->getSoldePaymentIntentId(), 'Lien de paiement par carte annulé.');
    }

    // ---------- Suppression d'un rendez-vous ----------

    public function testUnRendezVousSansPaiementCarteSeSupprimeEtRendLesPoints(): void
    {
        $cliente = $this->cliente('Emma');
        $soldeAvant = $this->solde($cliente);
        $reservation = new Reservation($cliente, $this->prestation(self::SEMI), new \DateTimeImmutable('+5 days 10:00'), 0, 500, 100);
        $reservation->changerStatut(StatutReservation::EN_ATTENTE, new \DateTimeImmutable());
        $this->em()->persist($reservation);
        $this->em()->persist(new MouvementPoints($cliente, -100, MotifMouvementPoints::UTILISATION, $reservation, null, 'Test'));
        $this->em()->flush();
        $id = $reservation->getId();

        $this->connecterAdmin();
        $crawler = $this->client->request('GET', '/admin/reservation/'.$id);
        $this->client->submit($crawler->selectButton('Supprimer')->form());

        self::assertResponseRedirects('/admin/reservation');
        self::assertNull(static::getContainer()->get(ReservationRepository::class)->find($id));
        self::assertSame($soldeAvant, $this->solde($this->cliente('Emma')), 'Points utilisés rendus, historique conservé.');
    }

    public function testUnRendezVousAvecAcompteDebiteParCarteNePeutPasEtreSupprime(): void
    {
        $reservation = $this->reservationCarteConfirmee();
        $this->connecterAdmin();

        $this->client->request('GET', '/admin/reservation/'.$reservation->getId());
        self::assertSelectorNotExists('form[action$="/supprimer"] button');
        self::assertSelectorTextContains('.gestion-reservation', 'un paiement par carte a eu lieu');

        $this->expectException(\LogicException::class);
        $this->workflow()->supprimer($this->recharger($reservation), 'test');
    }

    // ---------- Modification d'un rendez-vous ----------

    public function testLAdminDeplaceUnRendezVousAvecAcompteCarteMaisPasSaPrestation(): void
    {
        $reservation = $this->reservationCarteConfirmee();
        $reservation->marquerRappelEnvoye(new \DateTimeImmutable());
        $this->em()->flush();
        $nouveauJour = new \DateTimeImmutable('first tuesday of next month');
        $this->connecterAdmin();

        $crawler = $this->client->request('GET', '/admin/rendez-vous/'.$reservation->getId().'/modifier');
        self::assertResponseIsSuccessful();
        self::assertNotNull($crawler->filter('#modification_rendez_vous_prestation')->attr('disabled'), 'Prestation figée : acompte par carte.');
        $this->client->submit($crawler->selectButton('Enregistrer les modifications')->form(), [
            'modification_rendez_vous[date]' => $nouveauJour->format('Y-m-d'),
            'modification_rendez_vous[heure]' => '20:00',
        ]);
        self::assertResponseRedirects();
        self::assertEmailCount(1, message: 'Récapitulatif à jour envoyé à la cliente.');

        $reservation = $this->recharger($reservation);
        self::assertEquals($nouveauJour->setTime(20, 0), $reservation->getDebut());
        self::assertNull($reservation->getRappelEnvoyeAt(), 'Le rappel de la veille repartira.');
        self::assertSame(900, $reservation->getAcompteCentimes(), 'Acompte inchangé.');

        $this->expectException(\LogicException::class);
        $this->workflow()->modifier($reservation, $this->prestation('Pose complète gel'), $reservation->getDebut(), null, null, 0, 'test');
    }

    public function testUnRendezVousSansAcompteChangeDePrestationEtDePrix(): void
    {
        $reservation = $this->workflow()->reserverParLeSalon($this->prestation(self::SEMI), new \DateTimeImmutable('+40 days 21:00'),
            $this->coordonnees('06 39 98 63 63'), 'test');

        $this->workflow()->modifier($reservation, $this->prestation('Pose complète gel'), $reservation->getDebut(), null, null, 10, 'test', false);

        self::assertSame('Pose complète gel', $reservation->getPrestation()->getNom());
        self::assertSame(5500, $reservation->getPrixCentimes());
        self::assertSame(550, $reservation->getRemiseCentimes());
        self::assertSame('manuelle', $reservation->getRemiseMotif());
    }

    // ---------- Suppression d'une fiche cliente ----------

    public function testUneFicheSansRendezVousEstSupprimeeEtUneFicheAvecHistoriqueAnonymisee(): void
    {
        $nouvelle = (new Client('Nina', 'Sans-Rdv', '+33639986464'))->setEmail('nina@example.com');
        $this->em()->persist($nouvelle);
        $this->em()->flush();
        $id = $nouvelle->getId();
        $this->connecterAdmin();

        $crawler = $this->client->request('GET', '/admin/client/'.$id);
        $this->client->submit($crawler->selectButton('Supprimer')->form());
        self::assertResponseRedirects('/admin/client');
        self::assertNull(static::getContainer()->get(ClientRepository::class)->find($id));

        // Cliente avec un historique mais sans rendez-vous à venir : anonymisée.
        $ancienne = $this->clienteAvecHistoriqueSeulement();
        $crawler = $this->client->request('GET', '/admin/client/'.$ancienne->getId());
        $this->client->submit($crawler->selectButton('Supprimer')->form());
        $ancienne = static::getContainer()->get(ClientRepository::class)->find($ancienne->getId());
        self::assertInstanceOf(Client::class, $ancienne);
        self::assertTrue($ancienne->estAnonymise());
        self::assertNull($ancienne->getEmail());
    }

    public function testUneFicheSeSupprimeAussiDepuisLaListeDesClientes(): void
    {
        $nouvelle = (new Client('Nina', 'Depuis-Liste', '+33639986666'))->setEmail('nina.liste@example.com');
        $this->em()->persist($nouvelle);
        $this->em()->flush();
        $id = $nouvelle->getId();
        $this->connecterAdmin();

        $crawler = $this->client->request('GET', '/admin/client?query=Depuis-Liste');
        $lien = $crawler->filter('a.action-delete')->first();
        self::assertCount(1, $lien, 'Action « Supprimer » proposée dans la liste.');
        $jeton = (string) $crawler->filter('#modal-delete [name="token"], form#delete-form [name="token"], [name="token"]')->first()->attr('value');
        $this->client->request('POST', (string) ($lien->attr('formaction') ?? $lien->attr('href')), ['token' => $jeton]);

        self::assertResponseRedirects();
        self::assertNull(static::getContainer()->get(ClientRepository::class)->find($id));
    }

    public function testUneFicheAvecUnRendezVousAVenirNePeutPasEtreSupprimee(): void
    {
        $reservation = $this->reservationCarteConfirmee();
        $this->connecterAdmin();

        $crawler = $this->client->request('GET', '/admin/client/'.$reservation->getClient()->getId());
        $this->client->submit($crawler->selectButton('Supprimer')->form());
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-danger', 'rendez-vous à venir');
    }

    // ---------- Outils ----------

    private function recapitulatif(string $prestation): Crawler
    {
        $crawler = $this->client->request('GET', '/reservation/'.$this->prestation($prestation)->getId());

        return $this->client->click($crawler->filter('a.creneau')->first()->link());
    }

    /** Rendez-vous confirmé dans 5 jours, acompte de 9 € débité par carte. */
    private function reservationCarteConfirmee(): Reservation
    {
        $reservation = new Reservation($this->cliente('Léa'), $this->prestation(self::SEMI), new \DateTimeImmutable('+5 days 15:00'), 900);
        $reservation->setStripePaymentIntentId(SimulationPaiementGateway::PREFIXE.bin2hex(random_bytes(6)));
        $reservation->setModeAcompte(Reservation::MODE_CARTE);
        $reservation->changerStatut(StatutReservation::EN_ATTENTE, new \DateTimeImmutable());
        $reservation->changerStatut(StatutReservation::CONFIRMEE, new \DateTimeImmutable());
        $reservation->marquerAcompteDebite(new \DateTimeImmutable());
        $this->em()->persist($reservation);
        $this->em()->flush();

        return $reservation;
    }

    private function clienteAvecHistoriqueSeulement(): Client
    {
        $cliente = (new Client('Olga', 'Ancienne', '+33639986565'))->setEmail('olga@example.com');
        $this->em()->persist($cliente);
        $passe = new \DateTimeImmutable('-2 months');
        $reservation = new Reservation($cliente, $this->prestation(self::SEMI), $passe, 0);
        $reservation->changerStatut(StatutReservation::EN_ATTENTE, $passe);
        $reservation->changerStatut(StatutReservation::CONFIRMEE, $passe);
        $reservation->changerStatut(StatutReservation::HONOREE, $passe);
        $this->em()->persist($reservation);
        $this->em()->flush();

        return $cliente;
    }

    private function coordonnees(string $telephone): CoordonneesCliente
    {
        $coordonnees = new CoordonneesCliente();
        $coordonnees->prenom = 'Test';
        $coordonnees->nom = 'Salon';
        $coordonnees->telephone = $telephone;

        return $coordonnees;
    }

    /**
     * @param array<string, int> $valeurs
     */
    private function regler(array $valeurs): void
    {
        foreach ($valeurs as $cle => $valeur) {
            $this->em()->persist(($this->em()->find(Parametre::class, $cle) ?? new Parametre($cle, '0'))->setValeur((string) $valeur));
        }
        $this->em()->flush();
    }

    private function remplir(Form $formulaire, string $telephone): void
    {
        $formulaire['coordonnees[prenom]'] = 'Zoé';
        $formulaire['coordonnees[nom]'] = 'Test';
        $formulaire['coordonnees[telephone]'] = $telephone;
        $formulaire['coordonnees[email]'] = 'zoe.test@example.com';
        $conditions = $formulaire['coordonnees[accepteConditions]'];
        self::assertInstanceOf(ChoiceFormField::class, $conditions);
        $conditions->tick();
    }

    private function derniereReservation(): Reservation
    {
        $reservation = static::getContainer()->get(ReservationRepository::class)->findOneBy([], ['id' => 'DESC']);
        self::assertInstanceOf(Reservation::class, $reservation);

        return $reservation;
    }

    private function connecterAdmin(): void
    {
        $this->client->loginUser($this->creerUtilisateur('admin@example.com', [User::ROLE_ADMIN], totpActive: true));
    }

    private function recharger(Reservation $reservation): Reservation
    {
        $recharge = static::getContainer()->get(ReservationRepository::class)->find($reservation->getId());
        self::assertInstanceOf(Reservation::class, $recharge);
        static::getContainer()->get(EntityManagerInterface::class)->refresh($recharge);

        return $recharge;
    }

    private function solde(Client $cliente): int
    {
        return static::getContainer()->get(MouvementPointsRepository::class)->soldePour($cliente);
    }

    private function cliente(string $prenom): Client
    {
        $cliente = static::getContainer()->get(ClientRepository::class)->findOneBy(['prenom' => $prenom]);
        self::assertInstanceOf(Client::class, $cliente);

        return $cliente;
    }

    private function prestation(string $nom): Prestation
    {
        $prestation = static::getContainer()->get(PrestationRepository::class)->findOneBy(['nom' => $nom]);
        self::assertInstanceOf(Prestation::class, $prestation);

        return $prestation;
    }

    private function workflow(): ReservationWorkflow
    {
        return static::getContainer()->get(ReservationWorkflow::class);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
