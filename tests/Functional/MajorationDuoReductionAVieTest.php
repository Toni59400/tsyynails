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
use App\Repository\PrestationRepository;
use App\Repository\ReservationRepository;
use App\Service\Fidelite\ReductionAVie;
use App\Service\Reservation\Tarification;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;

/**
 * Majoration du soir, RDV duo (en ligne et depuis l'admin) et réduction fidélité à vie.
 */
final class MajorationDuoReductionAVieTest extends WebTestCase
{
    use CreationUtilisateurTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        (new CommandTester((new Application(self::$kernel))->find('app:demo:charger')))->execute(['--purger' => true]);
    }

    // ---------- Majoration du soir ----------

    public function testUnCreneauAPartirDeLHeureRegleeEstMajore(): void
    {
        // Le salon de démonstration ferme à 18 h : la majoration est réglée à partir de 14 h pour le test.
        $this->regler([Parametre::MAJORATION_SOIR_EUROS => 5, Parametre::MAJORATION_SOIR_HEURE => 14]);
        $crawler = $this->client->request('GET', '/reservation/'.$this->prestation('Semi-permanent mains')->getId());

        self::assertSelectorTextContains('.aide-majoration', 'à partir de 14 h');
        // L'agenda de démonstration est chargé : on parcourt plusieurs jours. Chaque créneau à partir de 14 h
        // affiche la majoration, aucun créneau plus tôt ne l'affiche.
        $lienMajore = null;
        $vus = ['majores' => 0, 'normaux' => 0];
        foreach (\array_slice($crawler->filter('a.jour')->links(), 0, 10) as $jour) {
            foreach ($this->client->click($jour)->filter('a.creneau') as $creneau) {
                $heure = (int) strtok(trim($creneau->textContent), ' ');
                $majore = str_contains($creneau->textContent, '+');
                self::assertSame($heure >= 14, $majore, 'Créneau de '.$heure.' h.');
                ++$vus[$majore ? 'majores' : 'normaux'];
                $lienMajore ??= $majore && $creneau instanceof \DOMElement ? $creneau->getAttribute('href') : null;
            }
        }
        self::assertGreaterThan(0, $vus['majores']);
        self::assertGreaterThan(0, $vus['normaux']);

        $reservation = $this->reserverDepuis($this->client->request('GET', (string) $lienMajore), '06 39 98 11 11');
        self::assertSame(500, $reservation->getMajorationCentimes());
        self::assertSame(3500, $reservation->getPrixCentimes());
        self::assertSame(1100, $reservation->getAcompteCentimes(), '30 % de 35 €, arrondi à l\'euro supérieur.');
    }

    // ---------- RDV duo en ligne ----------

    public function testLeRdvDuoEnLigneEnchaineLesDeuxPrestationsAvecLaRemise(): void
    {
        $this->regler([Parametre::DUO_ACTIF => 1, Parametre::DUO_REDUCTION_POURCENTAGE => 10]);
        $semi = $this->prestation('Semi-permanent mains');
        $gel = $this->prestation('Pose complète gel');

        $crawler = $this->client->request('GET', '/prestations');
        self::assertSelectorTextContains('.carte-duo', '−10 %');
        $this->client->request('GET', '/reservation/duo?personne1='.$gel->getId().'&personne2='.$semi->getId());
        self::assertResponseRedirects('/reservation/'.$gel->getId().'?duo='.$semi->getId());

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.recap-court', 'Pose complète gel + Semi-permanent mains');
        $crawler = $this->client->click($crawler->filter('a.creneau')->first()->link());
        self::assertSelectorTextContains('.recap', 'Duo, 2e personne : Semi-permanent mains');
        self::assertSelectorTextContains('.recap', 'Remise RDV duo (−10 %)');

        $reservation = $this->reserverDepuis($crawler, '06 39 98 22 22');
        self::assertTrue($reservation->estDuo());
        self::assertSame(8500, $reservation->getPrixCentimes(), '55 € + 30 €.');
        self::assertSame(850, $reservation->getRemiseCentimes());
        self::assertSame('duo', $reservation->getRemiseMotif());
        self::assertSame(7650, $reservation->getMontantDuCentimes());
        self::assertSame(120 + 60, (int) (($reservation->getFin()->getTimestamp() - $reservation->getDebut()->getTimestamp()) / 60));
    }

    public function testLeRdvDuoDesactiveNEstNiAfficheNiReservable(): void
    {
        $this->regler([Parametre::DUO_ACTIF => 0]);
        $semi = $this->prestation('Semi-permanent mains');

        $this->client->request('GET', '/prestations');
        self::assertSelectorNotExists('.carte-duo');
        $this->client->request('GET', '/reservation/duo');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/reservation/'.$semi->getId().'?duo='.$semi->getId());
        self::assertResponseRedirects('/reservation/'.$semi->getId());
    }

    public function testLaRemiseNePortePasSurLaMajoration(): void
    {
        $this->regler([Parametre::MAJORATION_SOIR_EUROS => 5, Parametre::MAJORATION_SOIR_HEURE => 19, Parametre::DUO_ACTIF => 1, Parametre::DUO_REDUCTION_POURCENTAGE => 10]);
        $semi = $this->prestation('Semi-permanent mains');

        $devis = static::getContainer()->get(Tarification::class)->devis($semi, null, $semi, new \DateTimeImmutable('tomorrow 19:30'), null);

        self::assertSame(6000 + 500, $devis->prixCentimes());
        self::assertSame(600, $devis->remiseCentimes(), '10 % de 60 €, sans les 5 € de majoration.');
        self::assertSame(5900, $devis->aPayerCentimes());
    }

    // ---------- Rendez-vous pris dans l'admin ----------

    public function testLAdminSaisitUnRdvDuoAvecSaReduction(): void
    {
        $this->regler([Parametre::MAJORATION_SOIR_EUROS => 5, Parametre::MAJORATION_SOIR_HEURE => 19, Parametre::DUO_REDUCTION_POURCENTAGE => 10]);
        $this->client->loginUser($this->creerUtilisateur('admin@example.com', [User::ROLE_ADMIN], totpActive: true));
        $semi = $this->prestation('Semi-permanent mains');
        $jour = new \DateTimeImmutable('first monday of next month');

        $crawler = $this->client->request('GET', '/admin/rendez-vous/nouveau');
        self::assertResponseIsSuccessful();
        self::assertSame('10', $crawler->filter('#rendez_vous_salon_remiseDuo')->attr('value'), 'Réduction du duo pré-remplie.');

        $this->client->submit($crawler->selectButton('Confirmer le rendez-vous')->form(), [
            'rendez_vous_salon[telephone]' => '06 39 98 33 33',
            'rendez_vous_salon[prenom]' => 'Inès',
            'rendez_vous_salon[nom]' => 'Duo',
            'rendez_vous_salon[email]' => 'ines.duo@example.com',
            'rendez_vous_salon[prestation]' => (string) $semi->getId(),
            'rendez_vous_salon[date]' => $jour->format('Y-m-d'),
            'rendez_vous_salon[heure]' => '19:00',
            'rendez_vous_salon[duo]' => '1',
            'rendez_vous_salon[prestationDuo]' => (string) $semi->getId(),
            'rendez_vous_salon[remiseDuo]' => '15',
        ]);
        self::assertResponseRedirects();

        $reservation = static::getContainer()->get(ReservationRepository::class)->findOneBy(['debut' => $jour->setTime(19, 0)]);
        self::assertInstanceOf(Reservation::class, $reservation);
        self::assertSame(StatutReservation::CONFIRMEE, $reservation->getStatut(), 'Confirmé d\'emblée, hors horaires d\'ouverture.');
        self::assertSame(0, $reservation->getAcompteCentimes());
        self::assertSame(15, $reservation->getRemisePourcentage());
        self::assertSame('manuelle', $reservation->getRemiseMotif());
        self::assertSame(500, $reservation->getMajorationCentimes(), 'Commence à 19 h : majoré.');
        self::assertSame(6000 + 500 - 900, $reservation->getMontantDuCentimes());

        // Même créneau une seconde fois : refusé.
        $crawler = $this->client->request('GET', '/admin/rendez-vous/nouveau');
        $this->client->submit($crawler->selectButton('Confirmer le rendez-vous')->form(), [
            'rendez_vous_salon[telephone]' => '06 39 98 44 44',
            'rendez_vous_salon[prenom]' => 'Autre',
            'rendez_vous_salon[nom]' => 'Cliente',
            'rendez_vous_salon[email]' => 'autre.cliente@example.com',
            'rendez_vous_salon[prestation]' => (string) $semi->getId(),
            'rendez_vous_salon[date]' => $jour->format('Y-m-d'),
            'rendez_vous_salon[heure]' => '19:30',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.rdv-salon', 'chevauche');
    }

    // ---------- Réduction à vie ----------

    public function testLaReductionAVieSObtientEvolueEtSePerd(): void
    {
        $cliente = $this->cliente();
        $service = static::getContainer()->get(ReductionAVie::class);
        self::assertFalse($service->verifierSeuil($cliente), 'Pas encore 340 points.');

        $this->em()->persist(new MouvementPoints($cliente, 340, MotifMouvementPoints::VISITE, null, null, 'Test'));
        $this->em()->flush();
        self::assertTrue($service->verifierSeuil($cliente));
        self::assertSame(10, $cliente->getPrivilegePourcentage());
        self::assertEmailCount(1);

        // La réduction s'applique au devis, mais le duo (10 %) ne s'y cumule pas : la plus avantageuse l'emporte.
        $tarification = static::getContainer()->get(Tarification::class);
        self::assertSame([10, 'privilege'], $tarification->remise($cliente, false));

        // Anniversaire : 8 rendez-vous honorés dans l'année → 15 %.
        $this->avancerRevision($cliente);
        for ($i = 1; $i <= 8; ++$i) {
            $this->rendezVousHonore($cliente, \sprintf('-%d weeks', $i * 4));
        }
        $service->traiter();
        self::assertSame(15, $cliente->getPrivilegePourcentage());
        self::assertSame([15, 'privilege'], $tarification->remise($cliente, true));

        // Année suivante sans assez de rendez-vous : réduction perdue, et pas regagnée avec les anciens points.
        $this->em()->createQuery('DELETE FROM App\Entity\Reservation r WHERE r.client = :c')->setParameter('c', $cliente)->execute();
        $this->avancerRevision($cliente);
        $service->traiter();
        self::assertSame(0, $cliente->getPrivilegePourcentage());
        self::assertNotNull($cliente->getPrivilegePerduAt());
        self::assertFalse($service->verifierSeuil($cliente), 'Les 340 points gagnés avant la perte ne comptent plus.');
    }

    public function testLeRappelPartTrenteJoursAvantSIlManqueDesRendezVous(): void
    {
        $cliente = $this->cliente();
        $cliente->accorderPrivilege(10, new \DateTimeImmutable('-340 days'));
        $this->em()->flush();

        $service = static::getContainer()->get(ReductionAVie::class);
        $service->traiter();
        $service->traiter();

        self::assertNotNull($cliente->getPrivilegeAvertiAt());
        self::assertSame(10, $cliente->getPrivilegePourcentage(), 'Pas encore l\'anniversaire : rien ne change.');
        // D'autres clientes de démonstration dépassent déjà le seuil : seuls les emails d'Emma comptent ici.
        $rappels = array_filter(self::getMailerMessages(), static fn ($m): bool => $m instanceof \Symfony\Component\Mime\Email
            && $m->getTo()[0]->getAddress() === $cliente->getEmail() && str_contains((string) $m->getSubject(), 'Gardez'));
        self::assertCount(1, $rappels, 'Un seul rappel malgré deux passages.');
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

    private function reserverDepuis(\Symfony\Component\DomCrawler\Crawler $crawler, string $telephone): Reservation
    {
        $formulaire = $crawler->selectButton('Continuer vers l\'acompte')->form();
        $this->remplir($formulaire, $telephone);
        $this->client->submit($formulaire);
        self::assertResponseRedirects();
        $jeton = basename((string) $this->client->getResponse()->headers->get('Location'));
        $reservation = static::getContainer()->get(ReservationRepository::class)->findOneBy(['jetonSuivi' => $jeton]);
        self::assertInstanceOf(Reservation::class, $reservation);

        return $reservation;
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

    private function avancerRevision(Client $cliente): void
    {
        (new \ReflectionProperty($cliente, 'privilegeRevisionAt'))->setValue($cliente, new \DateTimeImmutable('-1 hour'));
        $this->em()->flush();
    }

    private function rendezVousHonore(Client $cliente, string $quand): void
    {
        $reservation = new Reservation($cliente, $this->prestation('Semi-permanent mains'), new \DateTimeImmutable($quand), 900);
        $reservation->changerStatut(StatutReservation::EN_ATTENTE, new \DateTimeImmutable());
        $reservation->changerStatut(StatutReservation::CONFIRMEE, new \DateTimeImmutable());
        $reservation->changerStatut(StatutReservation::HONOREE, new \DateTimeImmutable());
        $this->em()->persist($reservation);
        $this->em()->flush();
    }

    private function cliente(): Client
    {
        $cliente = static::getContainer()->get(ClientRepository::class)->findOneBy(['prenom' => 'Emma']);
        self::assertInstanceOf(Client::class, $cliente);
        $this->em()->createQuery('DELETE FROM App\Entity\MouvementPoints m WHERE m.client = :c')->setParameter('c', $cliente)->execute();
        $this->em()->createQuery('DELETE FROM App\Entity\Reservation r WHERE r.client = :c')->setParameter('c', $cliente)->execute();

        return $cliente;
    }

    private function prestation(string $nom): Prestation
    {
        $prestation = static::getContainer()->get(PrestationRepository::class)->findOneBy(['nom' => $nom]);
        self::assertInstanceOf(Prestation::class, $prestation);

        return $prestation;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
