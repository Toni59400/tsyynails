<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Client;
use App\Entity\Parametre;
use App\Entity\Prestation;
use App\Entity\Reservation;
use App\Entity\User;
use App\Enum\MotifMouvementPoints;
use App\Enum\StatutReservation;
use App\Repository\ClientRepository;
use App\Repository\MouvementPointsRepository;
use App\Repository\PrestationRepository;
use App\Service\Fidelite\Parrainage;
use App\Service\Reservation\ReservationWorkflow;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

final class ParrainageTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        (new CommandTester((new Application(self::$kernel))->find('app:demo:charger')))->execute(['--purger' => true]);
    }

    public function testUneAmieParraineeRecoitSesPointsAvecSaMarraineAuPremierRendezVous(): void
    {
        $emma = $this->cliente('Emma');
        $code = $this->parrainage()->codePour($emma);

        // Le lien mène à l'inscription, code pré-rempli
        $this->client->request('GET', '/parrainage/'.$code);
        self::assertResponseRedirects('/inscription');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Emma vous recommande le salon');
        self::assertSame($code, $crawler->filter('#inscription_codeParrainage')->attr('value'));

        $this->inscrire($crawler, 'amie@example.com', '06 39 98 71 01');
        $amie = $this->clienteParTelephone('+33639987101');
        self::assertSame($emma->getId(), $amie->getMarraine()?->getId());

        $soldeEmma = $this->solde($emma);
        $this->honorerPremierRendezVous($amie);
        $this->honorerPremierRendezVous($amie);

        self::assertSame($soldeEmma + 50, $this->solde($emma), 'Marraine récompensée une seule fois.');
        self::assertSame(1, $this->compterMouvements($amie, MotifMouvementPoints::PARRAINAGE));
        self::assertSame(25 + 30 + 30, $this->solde($amie), 'Bienvenue parrainage + 2 visites à 30 €.');
    }

    public function testUneClienteDejaVenueNePeutPasEtreParrainee(): void
    {
        $emma = $this->cliente('Emma');
        $lea = $this->cliente('Léa');
        self::assertNotNull($lea->getDerniereVisiteAt());

        self::assertFalse($this->parrainage()->rattacher($lea, $this->parrainage()->codePour($emma)));
        self::assertNull($lea->getMarraine());
    }

    public function testOnNePeutPasSeParrainerSoiMeme(): void
    {
        $nouvelle = new Client('Zoé', 'Nouvelle', '+33639987102');
        static::getContainer()->get(EntityManagerInterface::class)->persist($nouvelle);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        self::assertFalse($this->parrainage()->rattacher($nouvelle, $this->parrainage()->codePour($nouvelle)));
    }

    public function testLePlafondAnnuelLimiteLesPointsDeLaMarraine(): void
    {
        $this->regler(Parametre::PARRAINAGE_MAX_PAR_AN, '1');
        $emma = $this->cliente('Emma');
        $code = $this->parrainage()->codePour($emma);
        $soldeEmma = $this->solde($emma);

        foreach (['+33639987103', '+33639987104'] as $telephone) {
            $filleule = new Client('Amie', 'Test', $telephone);
            static::getContainer()->get(EntityManagerInterface::class)->persist($filleule);
            static::getContainer()->get(EntityManagerInterface::class)->flush();
            self::assertTrue($this->parrainage()->rattacher($filleule, $code));
            $this->honorerPremierRendezVous($filleule);
            self::assertSame(25 + 30, $this->solde($filleule), 'La filleule reçoit toujours ses points.');
        }

        self::assertSame($soldeEmma + 50, $this->solde($emma), 'Une seule filleule récompensée pour la marraine.');
    }

    public function testUnCodeInconnuEstRefuse(): void
    {
        $this->client->request('GET', '/parrainage/ZZZZ2222');

        self::assertResponseRedirects('/');
    }

    public function testLEspaceClienteProposeLeLienDeParrainage(): void
    {
        $emma = $this->cliente('Emma');
        $user = new User((string) $emma->getEmail());
        $user->setPassword('x');
        $user->verifierEmail(new \DateTimeImmutable());
        $emma->setUser($user);
        static::getContainer()->get(EntityManagerInterface::class)->persist($user);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/compte/fidelite');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/parrainage/', (string) $crawler->filter('#lien-parrainage')->attr('value'));
        self::assertStringStartsWith('https://wa.me/?text=', (string) $crawler->filter('.parrainage__partage a')->first()->attr('href'));
    }

    private function inscrire(\Symfony\Component\DomCrawler\Crawler $crawler, string $email, string $telephone): void
    {
        $formulaire = $crawler->selectButton('Créer mon compte')->form([
            'inscription[prenom]' => 'Amie',
            'inscription[nom]' => 'Test',
            'inscription[telephone]' => $telephone,
            'inscription[email]' => $email,
            'inscription[motDePasse]' => 'un-mot-de-passe-solide',
        ]);
        $case = $formulaire['inscription[accepteConditions]'];
        self::assertInstanceOf(ChoiceFormField::class, $case);
        $case->tick();
        $this->client->submit($formulaire);
        self::assertResponseIsSuccessful();
    }

    /** Un rendez-vous confirmé dans le passé, puis honoré par la prothésiste. */
    private function honorerPremierRendezVous(Client $cliente): void
    {
        $prestation = static::getContainer()->get(PrestationRepository::class)->findOneBy(['nom' => 'Semi-permanent mains']);
        self::assertInstanceOf(Prestation::class, $prestation);
        $reservation = new Reservation($cliente, $prestation, new \DateTimeImmutable('-3 hours'), 900);
        $reservation->changerStatut(StatutReservation::EN_ATTENTE, new \DateTimeImmutable('-2 days'));
        $reservation->changerStatut(StatutReservation::CONFIRMEE, new \DateTimeImmutable('-2 days'));
        static::getContainer()->get(EntityManagerInterface::class)->persist($reservation);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        static::getContainer()->get(ReservationWorkflow::class)->honorer($reservation, 'test');
    }

    private function regler(string $cle, string $valeur): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->persist(new Parametre($cle, $valeur));
        static::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    private function parrainage(): Parrainage
    {
        return static::getContainer()->get(Parrainage::class);
    }

    private function solde(Client $cliente): int
    {
        return static::getContainer()->get(MouvementPointsRepository::class)->soldePour($cliente);
    }

    private function compterMouvements(Client $cliente, MotifMouvementPoints $motif): int
    {
        return \count(static::getContainer()->get(MouvementPointsRepository::class)->findBy(['client' => $cliente, 'motif' => $motif]));
    }

    private function cliente(string $prenom): Client
    {
        $cliente = static::getContainer()->get(ClientRepository::class)->findOneBy(['prenom' => $prenom]);
        self::assertInstanceOf(Client::class, $cliente);

        return $cliente;
    }

    private function clienteParTelephone(string $telephone): Client
    {
        $cliente = static::getContainer()->get(ClientRepository::class)->findOneBy(['telephone' => $telephone]);
        self::assertInstanceOf(Client::class, $cliente);

        return $cliente;
    }
}
