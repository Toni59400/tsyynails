<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Client;
use App\Entity\Reservation;
use App\Entity\RetourCliente;
use App\Entity\User;
use App\Enum\StatutReservation;
use App\Repository\ClientRepository;
use App\Repository\MouvementPointsRepository;
use App\Repository\PrestationRepository;
use App\Repository\RetourClienteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Questionnaire de satisfaction de l'affiche QR code : points une fois par cliente déjà venue, jamais liés à un avis Google.
 */
final class QuestionnaireTest extends WebTestCase
{
    use CreationUtilisateurTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        (new CommandTester((new Application(self::$kernel))->find('app:demo:charger')))->execute(['--purger' => true]);
    }

    public function testUneClienteVenueGagneSesPointsUneSeuleFois(): void
    {
        $emma = $this->clienteVenue();
        $soldeAvant = $this->solde($emma);

        $this->repondre('  '.strtoupper((string) $emma->getEmail()).' ', 5, 'Super accueil !');
        self::assertResponseRedirects('/votre-avis/merci');
        self::assertEmailCount(1);
        self::assertEmailHeaderSame(self::getMailerMessage(0) ?? throw new \LogicException(), 'subject', 'Merci pour votre avis : 10 points offerts · Tsyynails');
        self::assertSame($soldeAvant + 10, $this->solde($emma));

        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Merci');

        // Deuxième réponse : enregistrée, mais sans nouveaux points.
        $this->repondre((string) $emma->getEmail(), 4, null);
        self::assertSame($soldeAvant + 10, $this->solde($emma));

        $retours = static::getContainer()->get(RetourClienteRepository::class)->findBy([], ['id' => 'ASC']);
        self::assertCount(2, $retours);
        self::assertSame($emma->getId(), $retours[0]->getClient()?->getId());
        self::assertSame([10, 0], array_map(static fn (RetourCliente $r): int => $r->getPointsCredites(), $retours));
        self::assertSame('Super accueil !', $retours[0]->getCommentaire());
    }

    public function testUnEmailInconnuOuSansRendezVousNeRapporteRien(): void
    {
        $nouvelle = (new Client('Nora', 'Test', '+33639987010'))->setEmail('nora@example.com');
        $this->em()->persist($nouvelle);
        $this->em()->flush();

        $this->repondre('nora@example.com', 3, null);
        $this->repondre('inconnue@example.com', 2, null);

        self::assertResponseRedirects('/votre-avis/merci');
        self::assertSame(0, $this->solde($nouvelle), 'Jamais venue au salon : pas de points.');
        $retours = static::getContainer()->get(RetourClienteRepository::class)->findAll();
        self::assertCount(2, $retours);
        self::assertNull($retours[1]->getClient());
    }

    public function testUneReponseIncompleteEstRefusee(): void
    {
        $crawler = $this->client->request('GET', '/votre-avis');
        self::assertSelectorTextContains('.questionnaire__intro', '10 points');
        $this->client->submit($crawler->selectButton('Envoyer mes réponses')->form(), ['questionnaire[email]' => 'x@example.com']);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, static::getContainer()->get(RetourClienteRepository::class)->findAll());
    }

    public function testLAdminImprimeLAfficheEtLitLesReponses(): void
    {
        $this->repondre('inconnue@example.com', 4, 'Très bien');
        $this->client->loginUser($this->creerUtilisateur('admin@example.com', [User::ROLE_ADMIN], totpActive: true));
        $routeur = static::getContainer()->get(UrlGeneratorInterface::class);

        $this->client->request('GET', $routeur->generate('admin_affiche_avis_index'));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.affiche__qr img[src^="data:image/svg+xml"]');
        self::assertSelectorTextContains('.affiche__points', '+10 points');

        $this->client->request('GET', $routeur->generate('admin_retour_cliente_index'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Très bien');
    }

    private function repondre(string $email, int $note, ?string $commentaire): void
    {
        $crawler = $this->client->request('GET', '/votre-avis');
        $this->client->submit($crawler->selectButton('Envoyer mes réponses')->form(), [
            'questionnaire[note]' => (string) $note,
            'questionnaire[recommande]' => RetourCliente::RECOMMANDE_OUI,
            'questionnaire[commentaire]' => (string) $commentaire,
            'questionnaire[email]' => $email,
        ]);
    }

    /** Emma, avec au moins un rendez-vous honoré. */
    private function clienteVenue(): Client
    {
        $emma = static::getContainer()->get(ClientRepository::class)->findOneBy(['prenom' => 'Emma']);
        self::assertInstanceOf(Client::class, $emma);
        $prestation = static::getContainer()->get(PrestationRepository::class)->findOneBy(['active' => true]);
        self::assertNotNull($prestation);
        $reservation = new Reservation($emma, $prestation, new \DateTimeImmutable('-10 days'), 900);
        $reservation->changerStatut(StatutReservation::EN_ATTENTE, new \DateTimeImmutable());
        $reservation->changerStatut(StatutReservation::CONFIRMEE, new \DateTimeImmutable());
        $reservation->changerStatut(StatutReservation::HONOREE, new \DateTimeImmutable());
        $this->em()->persist($reservation);
        $this->em()->flush();

        return $emma;
    }

    private function solde(Client $cliente): int
    {
        return static::getContainer()->get(MouvementPointsRepository::class)->soldePour($cliente);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
