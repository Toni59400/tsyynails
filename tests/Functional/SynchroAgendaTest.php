<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Reservation;
use App\Entity\User;
use App\Enum\StatutReservation;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Abonnement de l'agenda personnel de l'admin (Google, Apple, Outlook) au flux iCal du salon.
 */
final class SynchroAgendaTest extends WebTestCase
{
    use CreationUtilisateurTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        (new CommandTester((new Application(self::$kernel))->find('app:demo:charger')))->execute(['--purger' => true]);
    }

    public function testLAdminCreeSonLienEtLeFluxContientLesRendezVous(): void
    {
        $admin = $this->creerUtilisateur('admin@example.com', [User::ROLE_ADMIN], totpActive: true);
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/admin/synchro-agenda');
        self::assertSelectorTextContains('.alert-info', 'pas activée');
        $this->client->submit($crawler->selectButton('Créer mon lien')->form());
        $crawler = $this->client->followRedirect();

        $lien = (string) $crawler->filter('#lien-agenda')->attr('value');
        self::assertMatchesRegularExpression('#^http://localhost/calendrier/[a-f0-9]{64}\.ics$#', $lien);
        self::assertSelectorExists('a[href^="webcal://localhost/calendrier/"]');

        // Les applications d'agenda ne se connectent pas : le lien seul suffit.
        $this->client->request('GET', '/deconnexion');
        $this->client->request('GET', $lien);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/calendar; charset=utf-8');
        $ics = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        self::assertStringEndsWith("END:VCALENDAR\r\n", $ics);

        $reservation = static::getContainer()->get(ReservationRepository::class)->findOneBy(['statut' => StatutReservation::CONFIRMEE]);
        self::assertInstanceOf(Reservation::class, $reservation);
        $depliee = str_replace("\r\n ", '', $ics);
        self::assertStringContainsString('UID:rendez-vous-'.$reservation->getId().'@localhost', $depliee);
        self::assertStringContainsString('DTSTART:'.$reservation->getDebut()->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'), $depliee);
        self::assertStringContainsString($reservation->getClient()->getPrenom().' '.mb_substr($reservation->getClient()->getNom(), 0, 1).'.', $depliee);
        self::assertStringNotContainsString((string) $reservation->getClient()->getTelephone(), $depliee, 'Pas de téléphone dans l\'agenda externe.');
        foreach (explode("\r\n", $ics) as $ligne) {
            self::assertLessThanOrEqual(75, \strlen($ligne), 'Lignes pliées à 75 octets.');
        }
    }

    public function testUnLienRegenereOuDesactiveNeFonctionnePlus(): void
    {
        $admin = $this->creerUtilisateur('admin@example.com', [User::ROLE_ADMIN], totpActive: true);
        $ancien = $admin->regenererJetonAgenda();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->flush();

        $this->client->request('GET', '/calendrier/'.$ancien.'.ics');
        self::assertResponseIsSuccessful();

        $admin->regenererJetonAgenda();
        $em->flush();
        $this->client->request('GET', '/calendrier/'.$ancien.'.ics');
        self::assertResponseStatusCodeSame(404);

        $admin->desactiverJetonAgenda();
        $em->flush();
        $this->client->request('GET', '/calendrier/'.str_repeat('a', 64).'.ics');
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnJetonDeClienteNeDonneRien(): void
    {
        $cliente = $this->creerUtilisateur('cliente@example.com', [User::ROLE_CLIENT]);
        $jeton = $cliente->regenererJetonAgenda();
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->client->request('GET', '/calendrier/'.$jeton.'.ics');
        self::assertResponseStatusCodeSame(404);
    }
}
