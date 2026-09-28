<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Client;
use App\Entity\Prestation;
use App\Entity\Reservation;
use App\Entity\User;
use App\Enum\StatutReservation;
use App\Repository\ClientRepository;
use App\Repository\PrestationRepository;
use App\Repository\UserRepository;
use App\Service\Reservation\ReservationWorkflow;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;

final class RappelsAnnulationsRgpdTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Base vide de rendez-vous : on maîtrise chaque cas.
        $this->commande('app:demo:charger', ['--purger' => true]);
        $this->em()->createQuery('DELETE FROM App\Entity\MouvementPoints m')->execute();
        $this->em()->createQuery('DELETE FROM App\Entity\Reservation r')->execute();
    }

    // ---------- Rappel de la veille ----------

    public function testLeRappelPartUneSeuleFoisDansLes24HeuresAvant(): void
    {
        $demain = $this->reservationConfirmee('+20 hours');
        $apresDemain = $this->reservationConfirmee('+30 hours');

        $this->commande('app:rappels:envoyer');
        $this->commande('app:rappels:envoyer');

        self::assertNotNull($this->recharger($demain)->getRappelEnvoyeAt());
        self::assertNull($this->recharger($apresDemain)->getRappelEnvoyeAt());
        self::assertEmailCount(1, message: 'Un seul rappel malgré deux exécutions.');
    }

    public function testLeRappelContientLaDateEtLeResteAPayer(): void
    {
        $reservation = $this->reservationConfirmee('+20 hours');

        $this->client->request('GET', '/');
        static::getContainer()->get(\App\Service\Reservation\NotificationsReservation::class)->rappel($reservation);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertStringContainsString('Rappel', (string) $email->getSubject());
        self::assertStringContainsString('Reste à régler', (string) $email->getHtmlBody());
    }

    // ---------- Annulation ----------

    public function testUneAnnulationAuMoins48HeuresAvantRembourseLAcompte(): void
    {
        $reservation = $this->reservationConfirmee('+3 days');

        $this->workflow()->annuler($reservation, 'test');

        self::assertSame(StatutReservation::ANNULEE, $reservation->getStatut());
        self::assertNotNull($reservation->getAcompteRembourseAt());
    }

    public function testUneAnnulationAMoinsDe48HeuresConserveLAcompte(): void
    {
        $reservation = $this->reservationConfirmee('+30 hours');

        $this->workflow()->annuler($reservation, 'test');

        self::assertSame(StatutReservation::ANNULEE, $reservation->getStatut());
        self::assertNull($reservation->getAcompteRembourseAt());
    }

    public function testUneAnnulationParLeSalonRembourseToujours(): void
    {
        $reservation = $this->reservationConfirmee('+5 hours');

        $this->workflow()->annulerParLeSalon($reservation, 'test');

        self::assertNotNull($reservation->getAcompteRembourseAt());
    }

    public function testLAdminVoitLesDeuxBoutonsDAnnulation(): void
    {
        $reservation = $this->reservationConfirmee('+3 days');
        $this->connecterAdmin();

        $crawler = $this->client->request('GET', '/admin/reservation/'.$reservation->getId());

        self::assertSelectorTextContains('.actions-reservation', 'Annuler (demande de la cliente)');
        self::assertSelectorTextContains('.actions-reservation', 'Annuler (empêchement du salon)');
        self::assertStringContainsString('sera remboursé', (string) $crawler->filter('form[data-confirmation]')->eq(0)->attr('data-confirmation'));
    }

    public function testLesConditionsAnnoncentLaRegleDes48Heures(): void
    {
        $this->client->request('GET', '/conditions-de-reservation');

        self::assertSelectorTextContains('#annulation + ul', 'au moins 48 heures avant');
        self::assertSelectorTextContains('#annulation + ul', 'Absence sans prévenir');
        self::assertSelectorTextNotContains('main', 'seront précisés');
    }

    // ---------- Durées de conservation (RGPD) ----------

    public function testUneFicheInactiveDepuis3AnsEstAnonymiseeEtSonCompteSupprime(): void
    {
        $ancienne = $this->cliente('Emma');
        $this->antidater($ancienne, '-4 years');
        $idCompte = $this->creerCompte($ancienne, '-4 years')->getId();
        $recente = $this->cliente('Léa');

        $this->commande('app:rgpd:purger');

        self::assertTrue($this->rechargerCliente($ancienne)->estAnonymise());
        self::assertNull(static::getContainer()->get(UserRepository::class)->find($idCompte));
        self::assertFalse($this->rechargerCliente($recente)->estAnonymise());
    }

    public function testUnRendezVousAVenirEmpecheLAnonymisation(): void
    {
        $cliente = $this->cliente('Emma');
        $this->antidater($cliente, '-4 years');
        $this->reservationConfirmee('+3 days', $cliente);

        $this->commande('app:rgpd:purger');

        self::assertFalse($this->rechargerCliente($cliente)->estAnonymise());
    }

    public function testUnCompteBientotInactifEstPrevenuUneFois(): void
    {
        $cliente = $this->cliente('Emma');
        $this->antidater($cliente, '-3 years +10 days');
        $user = $this->creerCompte($cliente, '-3 years +10 days');

        $this->commande('app:rgpd:purger');
        $this->commande('app:rgpd:purger');

        $user = static::getContainer()->get(UserRepository::class)->find($user->getId());
        self::assertInstanceOf(User::class, $user);
        self::assertNotNull($user->getAvertissementSuppressionAt());
        self::assertFalse($this->rechargerCliente($cliente)->estAnonymise());
    }

    public function testUnCompteJamaisConfirmeEstSupprimeApres30JoursEtLAdminJamais(): void
    {
        $nonConfirme = new User('jamais.confirme@example.com');
        $nonConfirme->setPassword('x');
        (new \ReflectionProperty($nonConfirme, 'createdAt'))->setValue($nonConfirme, new \DateTimeImmutable('-31 days'));
        $admin = (new User('admin.ancien@example.com'))->setRoles([User::ROLE_ADMIN])->setPassword('x');
        (new \ReflectionProperty($admin, 'createdAt'))->setValue($admin, new \DateTimeImmutable('-5 years'));
        $this->em()->persist($nonConfirme);
        $this->em()->persist($admin);
        $this->em()->flush();

        $this->commande('app:rgpd:purger');

        $users = static::getContainer()->get(UserRepository::class);
        self::assertNull($users->findOneBy(['email' => 'jamais.confirme@example.com']));
        self::assertNotNull($users->findOneBy(['email' => 'admin.ancien@example.com']));
    }

    public function testLaConnexionEnregistreLaDerniereActivite(): void
    {
        $user = $this->creerCompte($this->cliente('Emma'), '-1 year');
        $user->setPassword(static::getContainer()->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class)->hashPassword($user, 'un-mot-de-passe-solide'));
        $this->em()->flush();

        $crawler = $this->client->request('GET', '/connexion');
        $this->client->submit($crawler->selectButton('Se connecter')->form(['email' => $user->getEmail(), 'password' => 'un-mot-de-passe-solide']));

        $user = static::getContainer()->get(UserRepository::class)->find($user->getId());
        self::assertInstanceOf(User::class, $user);
        self::assertNotNull($user->getDerniereConnexionAt());
        self::assertGreaterThan(new \DateTimeImmutable('-1 minute'), $user->getDerniereConnexionAt());
    }

    // ---------- Outils ----------

    private function reservationConfirmee(string $dans, ?Client $cliente = null): Reservation
    {
        $prestation = static::getContainer()->get(PrestationRepository::class)->findOneBy(['nom' => 'Semi-permanent mains']);
        self::assertInstanceOf(Prestation::class, $prestation);
        $reservation = new Reservation($cliente ?? $this->cliente('Léa'), $prestation, new \DateTimeImmutable($dans), 900);
        $reservation->setStripePaymentIntentId('sim_'.bin2hex(random_bytes(6)));
        $reservation->changerStatut(StatutReservation::EN_ATTENTE, new \DateTimeImmutable('-1 day'));
        $reservation->changerStatut(StatutReservation::CONFIRMEE, new \DateTimeImmutable('-1 day'));
        $this->em()->persist($reservation);
        $this->em()->flush();

        return $reservation;
    }

    private function creerCompte(Client $cliente, string $depuis): User
    {
        $user = new User((string) $cliente->getEmail());
        $user->setPassword('x');
        $user->verifierEmail(new \DateTimeImmutable($depuis));
        (new \ReflectionProperty($user, 'createdAt'))->setValue($user, new \DateTimeImmutable($depuis));
        $cliente->setUser($user);
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    /** Fiche créée et dernière visite à cette date, sans autre activité. */
    private function antidater(Client $cliente, string $quand): void
    {
        $date = new \DateTimeImmutable($quand);
        (new \ReflectionProperty($cliente, 'createdAt'))->setValue($cliente, $date);
        (new \ReflectionProperty($cliente, 'derniereVisiteAt'))->setValue($cliente, $date);
        $this->em()->flush();
    }

    private function connecterAdmin(): void
    {
        $admin = (new User('admin@example.com'))->setRoles([User::ROLE_ADMIN])->setPassword('x');
        $admin->activerTotp('JBSWY3DPEHPK3PXP', new \DateTimeImmutable());
        $this->em()->persist($admin);
        $this->em()->flush();
        $this->client->loginUser($admin);
    }

    private function recharger(Reservation $reservation): Reservation
    {
        $this->em()->refresh($reservation);

        return $reservation;
    }

    private function rechargerCliente(Client $cliente): Client
    {
        $this->em()->clear();
        $recharge = static::getContainer()->get(ClientRepository::class)->find($cliente->getId());
        self::assertInstanceOf(Client::class, $recharge);

        return $recharge;
    }

    private function cliente(string $prenom): Client
    {
        $cliente = static::getContainer()->get(ClientRepository::class)->findOneBy(['prenom' => $prenom]);
        self::assertInstanceOf(Client::class, $cliente);

        return $cliente;
    }

    private function workflow(): ReservationWorkflow
    {
        return static::getContainer()->get(ReservationWorkflow::class);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function commande(string $nom, array $arguments = []): void
    {
        (new CommandTester((new Application(self::$kernel))->find($nom)))->execute($arguments);
    }
}
