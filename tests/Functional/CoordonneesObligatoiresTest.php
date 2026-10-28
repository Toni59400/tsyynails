<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Client;
use App\Entity\Prestation;
use App\Entity\Reservation;
use App\Entity\User;
use App\Repository\ClientRepository;
use App\Repository\PrestationRepository;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * Prénom, nom, téléphone et email obligatoires pour réserver, et jamais d'échec « muet » :
 * une cliente connectée dont la fiche est incomplète la complète dans le formulaire.
 */
final class CoordonneesObligatoiresTest extends WebTestCase
{
    use CreationUtilisateurTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        (new CommandTester((new Application(self::$kernel))->find('app:demo:charger')))->execute(['--purger' => true]);
    }

    public function testUneClienteConnecteeAvecUneFicheIncompleteLaCompleteEtReserve(): void
    {
        $user = $this->creerUtilisateur('fiche.incomplete@example.com', ['ROLE_CLIENT']);
        $user->verifierEmail(new \DateTimeImmutable());
        $fiche = new Client('', '', '');
        $fiche->setUser($user);
        $this->em()->persist($fiche);
        $this->em()->flush();
        $this->client->loginUser($user);

        $crawler = $this->recapitulatif();
        self::assertSelectorTextContains('.formulaire', 'Merci de compléter votre fiche');
        $formulaire = $crawler->selectButton('Continuer vers l\'acompte')->form([
            'coordonnees[prenom]' => 'Jade',
            'coordonnees[nom]' => 'Complète',
            'coordonnees[telephone]' => '06 39 98 72 72',
        ]);
        $this->cocherConditions($formulaire);
        $this->client->submit($formulaire);

        self::assertResponseRedirects();
        $fiche = static::getContainer()->get(ClientRepository::class)->find($fiche->getId());
        self::assertInstanceOf(Client::class, $fiche);
        self::assertSame('Jade', $fiche->getPrenom());
        self::assertSame('+33639987272', $fiche->getTelephone());
        $reservation = static::getContainer()->get(ReservationRepository::class)->findOneBy([], ['id' => 'DESC']);
        self::assertInstanceOf(Reservation::class, $reservation);
        self::assertSame($fiche->getId(), $reservation->getClient()->getId());
    }

    public function testSansNomNiEmailLaDemandeEstRefuseeAvecDesErreursVisibles(): void
    {
        $crawler = $this->recapitulatif();
        $formulaire = $crawler->selectButton('Continuer vers l\'acompte')->form([
            'coordonnees[prenom]' => '',
            'coordonnees[nom]' => '',
            'coordonnees[telephone]' => '06 39 98 73 73',
            'coordonnees[email]' => '',
        ]);
        $this->cocherConditions($formulaire);
        $this->client->submit($formulaire);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.formulaire', 'Indiquez votre prénom.');
        self::assertSelectorTextContains('.formulaire', 'Indiquez votre nom.');
        self::assertSelectorTextContains('.formulaire', 'Indiquez votre adresse email');
    }

    public function testUneInscriptionSansPrenomAfficheLErreurAuLieuDePlanter(): void
    {
        $crawler = $this->client->request('GET', '/inscription');
        $formulaire = $crawler->selectButton('Créer mon compte')->form([
            'inscription[prenom]' => '',
            'inscription[nom]' => '',
            'inscription[telephone]' => '06 39 98 75 75',
            'inscription[email]' => '',
            'inscription[motDePasse]' => 'un-mot-de-passe-solide-2026',
        ]);
        $case = $formulaire['inscription[accepteConditions]'];
        self::assertInstanceOf(ChoiceFormField::class, $case);
        $case->tick();
        $this->client->submit($formulaire);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.champ--erreur');
    }

    public function testLEmailEstObligatoireDansLeNouveauRendezVousDeLAdmin(): void
    {
        $this->client->loginUser($this->creerUtilisateur('admin@example.com', [User::ROLE_ADMIN], totpActive: true));
        $crawler = $this->client->request('GET', '/admin/rendez-vous/nouveau');
        self::assertNotNull($crawler->filter('#rendez_vous_salon_email')->attr('required'));

        $this->client->submit($crawler->selectButton('Confirmer le rendez-vous')->form(), [
            'rendez_vous_salon[telephone]' => '06 39 98 74 74',
            'rendez_vous_salon[prenom]' => 'Sans',
            'rendez_vous_salon[nom]' => 'Email',
            'rendez_vous_salon[prestation]' => (string) $this->prestation()->getId(),
            'rendez_vous_salon[date]' => (new \DateTimeImmutable('+30 days'))->format('Y-m-d'),
            'rendez_vous_salon[heure]' => '21:00',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.rdv-salon', 'Indiquez l\'email de la cliente.');
    }

    private function recapitulatif(): Crawler
    {
        $crawler = $this->client->request('GET', '/reservation/'.$this->prestation()->getId());

        return $this->client->click($crawler->filter('a.creneau')->first()->link());
    }

    private function cocherConditions(\Symfony\Component\DomCrawler\Form $formulaire): void
    {
        $case = $formulaire['coordonnees[accepteConditions]'];
        self::assertInstanceOf(ChoiceFormField::class, $case);
        $case->tick();
    }

    private function prestation(): Prestation
    {
        $prestation = static::getContainer()->get(PrestationRepository::class)->findOneBy(['nom' => 'Semi-permanent mains']);
        self::assertInstanceOf(Prestation::class, $prestation);

        return $prestation;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
