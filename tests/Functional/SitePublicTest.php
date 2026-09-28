<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Prestation;
use App\Entity\Reservation;
use App\Enum\StatutReservation;
use App\Repository\PrestationRepository;
use App\Repository\ReservationRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SitePublicTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $commande = (new Application(self::$kernel))->find('app:demo:charger');
        (new CommandTester($commande))->execute(['--purger' => true]);
    }

    #[DataProvider('pagesPubliques')]
    public function testLesPagesPubliquesSAffichent(string $url): void
    {
        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, 'h1');
        self::assertSelectorExists('a[href="/reservation"]');
    }

    /**
     * @return iterable<array{string}>
     */
    public static function pagesPubliques(): iterable
    {
        foreach (['/', '/prestations', '/galerie', '/infos-pratiques', '/reservation', '/mentions-legales', '/confidentialite', '/conditions-de-reservation'] as $url) {
            yield $url => [$url];
        }
    }

    public function testLAccueilContientLesDonneesStructureesDuSalon(): void
    {
        $crawler = $this->client->request('GET', '/');

        $jsonLd = json_decode($crawler->filter('script[type="application/ld+json"]')->text(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($jsonLd);
        self::assertSame('NailSalon', $jsonLd['@type']);
        self::assertNotEmpty($jsonLd['openingHoursSpecification']);
    }

    public function testSeulesLesPrestationsActivesSontProposees(): void
    {
        $this->client->request('GET', '/prestations');

        self::assertSelectorTextContains('main', 'Pose complète gel');
        self::assertSelectorTextNotContains('main', 'Pose résine');
    }

    public function testUnePrestationInactiveNeSeReservePas(): void
    {
        $inactive = static::getContainer()->get(PrestationRepository::class)->findOneBy(['active' => false]);
        self::assertInstanceOf(Prestation::class, $inactive);

        $this->client->request('GET', '/reservation/'.$inactive->getId());

        self::assertResponseStatusCodeSame(404);
    }

    public function testLeChoixDUnCreneauMeneAuRecapitulatif(): void
    {
        $crawler = $this->client->request('GET', '/reservation/'.$this->prestation('Semi-permanent mains')->getId());
        self::assertResponseIsSuccessful();

        $creneaux = $crawler->filter('a.creneau');
        self::assertGreaterThan(0, $creneaux->count(), 'Au moins un créneau doit être proposé sur 4 semaines.');

        $this->client->click($creneaux->first()->link());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.recap', 'Semi-permanent mains');
        // Acompte de 30 % de 30 € recalculé côté serveur.
        self::assertStringContainsString("9\u{00A0}€, déduit du prix", (string) $this->client->getResponse()->getContent());
    }

    public function testUnCreneauDejaReserveEstRefuse(): void
    {
        /** @var ReservationRepository $reservations */
        $reservations = static::getContainer()->get(ReservationRepository::class);
        $confirmee = $reservations->findOneBy(['statut' => StatutReservation::CONFIRMEE]);
        self::assertInstanceOf(Reservation::class, $confirmee);

        $url = \sprintf('/reservation/%d/%s', $confirmee->getPrestation()->getId(), $confirmee->getDebut()->format('Y-m-d\TH:i'));
        $this->client->request('GET', $url);

        self::assertResponseRedirects('/reservation/'.$confirmee->getPrestation()->getId());
    }

    public function testLaGalerieSeFiltreParTheme(): void
    {
        $crawler = $this->client->request('GET', '/galerie');
        $toutes = $crawler->filter('.galerie__item')->count();

        $crawler = $this->client->click($crawler->filter('.filtre')->reduce(static fn ($lien): bool => 'Automne cosy' === $lien->text())->link());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Automne cosy');
        self::assertSelectorExists('.filtre[aria-current="page"]');
        self::assertGreaterThan(0, $crawler->filter('.galerie__item')->count());
        self::assertLessThan($toutes, $crawler->filter('.galerie__item')->count());
        self::assertSelectorTextNotContains('.galerie', 'Rouge cerise');
    }

    public function testUnFiltreDeGalerieInconnuAfficheToutesLesPhotos(): void
    {
        $this->client->request('GET', '/galerie?theme=inconnu&prestation=abc');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Galerie');
    }

    public function testLesPrestationsAffichentLeursPhotos(): void
    {
        $crawler = $this->client->request('GET', '/prestations');

        self::assertGreaterThan(0, $crawler->filter('.carte-prestation__photo')->count());

        $this->client->click($crawler->filter('.carte-prestation__lien')->first()->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#realisations .galerie__item');
    }

    /**
     * Régression : un import CSS dans le JavaScript devient un module « data: » dans l'importmap,
     * refusé par la CSP de production (script-src sans data:), ce qui bloquait tout le JavaScript
     * (paiement Stripe compris). Les styles doivent être chargés par des balises <link>.
     */
    public function testLImportmapNeContientAucunModuleDataRefuseParLaCsp(): void
    {
        foreach (['/', '/reservation', '/connexion'] as $url) {
            $crawler = $this->client->request('GET', $url);

            $importmap = $crawler->filter('script[type="importmap"]')->text();
            self::assertStringNotContainsString('data:', $importmap, $url);
            self::assertGreaterThan(0, $crawler->filter('link[rel="stylesheet"][href*="/assets/styles/app"]')->count(), $url);
        }
    }

    public function testLeSitemapListeLesPagesPubliques(): void
    {
        $this->client->request('GET', '/sitemap.xml');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/prestations</loc>', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('/admin', (string) $this->client->getResponse()->getContent());
    }

    private function prestation(string $nom): Prestation
    {
        $prestation = static::getContainer()->get(PrestationRepository::class)->findOneBy(['nom' => $nom]);
        self::assertInstanceOf(Prestation::class, $prestation);

        return $prestation;
    }
}
