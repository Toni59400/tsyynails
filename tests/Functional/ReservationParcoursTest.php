<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Client;
use App\Entity\Prestation;
use App\Entity\Reservation;
use App\Entity\User;
use App\Enum\MotifMouvementPoints;
use App\Enum\StatutReservation;
use App\Repository\ClientRepository;
use App\Repository\MouvementPointsRepository;
use App\Repository\PrestationRepository;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * Parcours complet d'une réservation, en mode paiement simulé (aucune clé Stripe en test).
 */
final class ReservationParcoursTest extends WebTestCase
{
    use CreationUtilisateurTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->commande('app:demo:charger', ['--purger' => true]);
    }

    public function testUneClienteReserveEtLaDemandePartEnValidation(): void
    {
        $reservation = $this->reserver('Semi-permanent mains', '06 39 98 77 77');

        self::assertSame(StatutReservation::PAIEMENT_EN_COURS, $reservation->getStatut());
        self::assertStringStartsWith('sim_', (string) $reservation->getStripePaymentIntentId());
        self::assertSame(900, $reservation->getAcompteCentimes());
        self::assertNotNull($reservation->getConditionsAccepteesAt());
        self::assertSame('+33639987777', $reservation->getClient()->getTelephone());

        // Page de paiement puis empreinte simulée
        $crawler = $this->client->request('GET', '/reservation/paiement/'.$reservation->getJetonSuivi());
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Simuler l\'empreinte bancaire')->form());

        self::assertResponseRedirects('/reservation/suivi/'.$reservation->getJetonSuivi().'?redirect_status=succeeded');
        self::assertEmailCount(2, message: 'Accusé de réception à la cliente et alerte à la prothésiste.');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Demande envoyée');
        self::assertSame(StatutReservation::EN_ATTENTE, $this->recharger($reservation)->getStatut());

        // Mesure d'audience : la demande est signalée une fois, sans donnée personnelle ni jeton de suivi.
        $mesure = json_decode((string) $crawler->filter('template[data-mesure]')->attr('data-mesure'), true);
        self::assertSame('reservation_demandee', $mesure['event']);
        self::assertSame('Semi-permanent mains', $mesure['prestation']);
        self::assertSame(9, $mesure['acompte']);
        self::assertStringNotContainsString($reservation->getJetonSuivi(), (string) json_encode($mesure));
        $crawler = $this->client->request('GET', '/reservation/suivi/'.$reservation->getJetonSuivi());
        self::assertCount(0, $crawler->filter('template[data-mesure]'), 'Pas de nouvel événement en revenant par le lien de l\x27email.');
    }

    public function testUnCreneauPrisPendantLaSaisieEstRefuse(): void
    {
        $prestation = $this->prestation('Semi-permanent mains');
        $crawler = $this->client->request('GET', '/reservation/'.$prestation->getId());
        $lien = $crawler->filter('a.creneau')->first()->link();

        // Première cliente
        $formulaire = $this->client->click($lien)->selectButton('Continuer vers l\'acompte')->form();
        $this->remplir($formulaire, '06 39 98 11 11');
        $crawler = $this->client->click($lien);
        $formulaireConcurrente = $crawler->selectButton('Continuer vers l\'acompte')->form();
        $this->client->submit($formulaire);

        // Deuxième cliente, même créneau, formulaire affiché avant la première demande
        $this->remplir($formulaireConcurrente, '06 39 98 22 22');
        $this->client->submit($formulaireConcurrente);

        self::assertResponseRedirects('/reservation/'.$prestation->getId());
        self::assertNull(static::getContainer()->get(ClientRepository::class)->findOneBy(['telephone' => '+33639982222']));
    }

    public function testLeFormulaireSignaleLesErreurs(): void
    {
        $prestation = $this->prestation('Semi-permanent mains');
        $crawler = $this->client->request('GET', '/reservation/'.$prestation->getId());
        $crawler = $this->client->click($crawler->filter('a.creneau')->first()->link());

        $formulaire = $crawler->selectButton('Continuer vers l\'acompte')->form([
            'coordonnees[prenom]' => 'Zoé',
            'coordonnees[nom]' => 'Test',
            'coordonnees[telephone]' => '123',
            'coordonnees[email]' => 'pas-un-email',
        ]);
        $this->client->submit($formulaire);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.formulaire', 'Numéro de téléphone invalide');
        self::assertSelectorTextContains('.formulaire', 'Adresse email invalide');
        self::assertSelectorTextContains('.formulaire', 'accepter les conditions');
    }

    public function testUneClienteConnueEstRetrouveeParSonTelephoneSansEcraserSaFiche(): void
    {
        $emma = static::getContainer()->get(ClientRepository::class)->findOneBy(['prenom' => 'Emma']);
        self::assertInstanceOf(Client::class, $emma);
        $nombreAvant = \count(static::getContainer()->get(ClientRepository::class)->findAll());

        $reservation = $this->reserver('Semi-permanent mains', (string) $emma->getTelephone(), prenom: 'Usurpatrice');

        self::assertSame($emma->getId(), $reservation->getClient()->getId());
        self::assertSame('Emma', $reservation->getClient()->getPrenom());
        self::assertCount($nombreAvant, static::getContainer()->get(ClientRepository::class)->findAll());
    }

    public function testLAdminValideLaDemande(): void
    {
        $reservation = $this->demandeEnAttente();
        $this->connecterAdmin();

        $crawler = $this->client->request('GET', '/admin/reservation/'.$reservation->getId());
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Valider et débiter l\'acompte')->form());

        self::assertResponseRedirects('/admin/reservation/'.$reservation->getId());
        self::assertSame(StatutReservation::CONFIRMEE, $this->recharger($reservation)->getStatut());
        self::assertEmailCount(1);
    }

    public function testLAdminRefuseLaDemandeEtLeCreneauSeLibere(): void
    {
        $reservation = $this->demandeEnAttente();
        $this->connecterAdmin();

        $crawler = $this->client->request('GET', '/admin/reservation/'.$reservation->getId());
        $this->client->submit($crawler->selectButton('Refuser')->form());

        self::assertSame(StatutReservation::REFUSEE, $this->recharger($reservation)->getStatut());
        $creneau = '/reservation/'.$reservation->getPrestation()->getId().'/'.$reservation->getDebut()->format('Y-m-d\TH:i');
        $this->client->request('GET', $creneau);
        self::assertResponseIsSuccessful('Le créneau refusé est de nouveau proposé.');
    }

    public function testUnRendezVousHonoreCrediteLesPointsUneSeuleFois(): void
    {
        $reservation = $this->demandeEnAttente();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $reservation->changerStatut(StatutReservation::CONFIRMEE, new \DateTimeImmutable());
        // Rendez-vous passé
        (new \ReflectionProperty($reservation, 'debut'))->setValue($reservation, new \DateTimeImmutable('-2 hours'));
        $entityManager->flush();

        $this->connecterAdmin();
        $crawler = $this->client->request('GET', '/admin/reservation/'.$reservation->getId());
        $formulaire = $crawler->selectButton('Cliente venue')->form();
        $this->client->submit($formulaire);
        $this->client->submit($formulaire);

        $reservation = $this->recharger($reservation);
        self::assertSame(StatutReservation::HONOREE, $reservation->getStatut());
        $visites = array_filter(
            static::getContainer()->get(MouvementPointsRepository::class)->historiquePour($reservation->getClient()),
            static fn ($m): bool => MotifMouvementPoints::VISITE === $m->getMotif() && $m->getReservation()?->getId() === $reservation->getId(),
        );
        self::assertCount(1, $visites);
    }

    public function testLesPaiementsAbandonnesExpirentEtLibèrentLeCreneau(): void
    {
        $reservation = $this->reserver('Semi-permanent mains', '06 39 98 33 33');
        (new \ReflectionProperty($reservation, 'createdAt'))->setValue($reservation, new \DateTimeImmutable('-31 minutes'));
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->commande('app:reservations:expirer');

        self::assertSame(StatutReservation::EXPIREE, $this->recharger($reservation)->getStatut());
    }

    public function testLeWebhookRefuseUneSignatureInvalide(): void
    {
        $this->client->request('POST', '/stripe/webhook', server: ['HTTP_STRIPE_SIGNATURE' => 't=1,v1=faux'], content: '{"id":"evt_1"}');

        // Sans secret configuré en test, le webhook est désactivé ; avec un secret, la signature serait refusée.
        self::assertContains($this->client->getResponse()->getStatusCode(), [400, 503]);
    }

    public function testUnePageDeSuiviInconnueRenvoie404(): void
    {
        $this->client->request('GET', '/reservation/suivi/AAAAAAAAAAAAAAAAAAAAAA');

        self::assertResponseStatusCodeSame(404);
    }

    public function testUnSupplementNailArtAllongeLeRendezVousEtAjouteSonPrix(): void
    {
        $prestation = $this->prestation('Semi-permanent mains');
        $crawler = $this->client->request('GET', '/reservation/'.$prestation->getId());

        // Niveau 1 proposé partout, niveau 2 réservé aux poses : absent ici.
        self::assertStringContainsString('Nail art niveau 1', $crawler->filter('.options-resa')->text());
        self::assertStringNotContainsString('Nail art niveau 2', $crawler->filter('.options-resa')->text());

        $crawler = $this->client->submit($crawler->filter('.options-resa')->form(), ['supplement' => $crawler->filter('.options-resa input[value!=""]')->attr('value')]);
        self::assertSelectorTextContains('.recap-court', 'Semi-permanent mains + Nail art niveau 1');
        self::assertStringContainsString('supplement=', (string) $crawler->filter('a.creneau')->first()->attr('href'), 'Le choix suit la cliente jusqu\'au récapitulatif.');

        $crawler = $this->client->click($crawler->filter('a.creneau')->first()->link());
        self::assertSelectorTextContains('.recap', 'Nail art niveau 1');
        $formulaire = $crawler->selectButton('Continuer vers l\'acompte')->form();
        $this->remplir($formulaire, '06 39 98 55 55');
        $this->client->submit($formulaire);
        self::assertResponseRedirects();

        $jeton = basename((string) $this->client->getResponse()->headers->get('Location'));
        $reservation = static::getContainer()->get(ReservationRepository::class)->findOneBy(['jetonSuivi' => $jeton]);
        self::assertInstanceOf(Reservation::class, $reservation);
        self::assertSame('Nail art niveau 1', $reservation->getSupplementNom());
        self::assertSame(3500, $reservation->getPrixCentimes(), '30 € + 5 € de nail art.');
        self::assertSame(500, $reservation->getSupplementPrixCentimes());
        self::assertSame(1100, $reservation->getAcompteCentimes(), '30 % de 35 €, arrondi à l\'euro supérieur.');
        self::assertSame(60 + 15, (int) (($reservation->getFin()->getTimestamp() - $reservation->getDebut()->getTimestamp()) / 60));
    }

    public function testUnSupplementNonProposePourLaPrestationEstRefuse(): void
    {
        $prestation = $this->prestation('Semi-permanent mains');
        $niveau2 = static::getContainer()->get(\App\Repository\SupplementRepository::class)->findOneBy(['nom' => 'Nail art niveau 2']);
        self::assertInstanceOf(\App\Entity\Supplement::class, $niveau2);
        $crawler = $this->client->request('GET', '/reservation/'.$prestation->getId());
        $lien = (string) $crawler->filter('a.creneau')->first()->attr('href');

        // Adresse modifiée à la main : le niveau 2 n'est pas proposé sur cette prestation.
        $this->client->request('GET', $lien.'?supplement='.$niveau2->getId());

        self::assertResponseRedirects('/reservation/'.$prestation->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--erreur', 'plus proposé');
    }

    private function reserver(string $nomPrestation, string $telephone, string $prenom = 'Zoé'): Reservation
    {
        $prestation = $this->prestation($nomPrestation);
        $crawler = $this->client->request('GET', '/reservation/'.$prestation->getId());
        $crawler = $this->client->click($crawler->filter('a.creneau')->first()->link());

        $formulaire = $crawler->selectButton('Continuer vers l\'acompte')->form();
        $this->remplir($formulaire, $telephone, $prenom);
        $this->client->submit($formulaire);

        self::assertResponseRedirects();
        $jeton = basename((string) $this->client->getResponse()->headers->get('Location'));
        $reservation = static::getContainer()->get(ReservationRepository::class)->findOneBy(['jetonSuivi' => $jeton]);
        self::assertInstanceOf(Reservation::class, $reservation);

        return $reservation;
    }

    private function demandeEnAttente(): Reservation
    {
        $reservation = $this->reserver('Semi-permanent mains', '06 39 98 44 44');
        $reservation->changerStatut(StatutReservation::EN_ATTENTE, new \DateTimeImmutable());
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        return $reservation;
    }

    private function remplir(\Symfony\Component\DomCrawler\Form $formulaire, string $telephone, string $prenom = 'Zoé'): void
    {
        $formulaire['coordonnees[prenom]'] = $prenom;
        $formulaire['coordonnees[nom]'] = 'Test';
        $formulaire['coordonnees[telephone]'] = $telephone;
        $formulaire['coordonnees[email]'] = 'zoe.test@example.com';
        $conditions = $formulaire['coordonnees[accepteConditions]'];
        self::assertInstanceOf(ChoiceFormField::class, $conditions);
        $conditions->tick();
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

    private function prestation(string $nom): Prestation
    {
        $prestation = static::getContainer()->get(PrestationRepository::class)->findOneBy(['nom' => $nom]);
        self::assertInstanceOf(Prestation::class, $prestation);

        return $prestation;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function commande(string $nom, array $arguments = []): void
    {
        (new CommandTester((new Application(self::$kernel))->find($nom)))->execute($arguments);
    }
}
