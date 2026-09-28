<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Prestation;
use App\Repository\PrestationRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Référencement : pages indexables, données structurées, aperçus de partage, fichiers pour les robots.
 */
final class ReferencementTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        (new CommandTester((new Application(self::$kernel))->find('app:demo:charger')))->execute(['--purger' => true]);
    }

    public function testLAccueilDecritLeSalonPourGoogle(): void
    {
        $crawler = $this->client->request('GET', '/');

        $salon = $this->jsonLd($crawler)[0];
        self::assertSame('NailSalon', $salon['@type']);
        self::assertSame(50.269614, $salon['geo']['latitude']);
        self::assertStringContainsString('€', $salon['priceRange']);
        self::assertNotEmpty($salon['hasOfferCatalog']['itemListElement']);
        self::assertContains('https://www.instagram.com/tsyynails', $salon['sameAs']);
        self::assertSame('Arras', $salon['areaServed'][0]['name']);
    }

    public function testChaquePrestationASaPageIndexable(): void
    {
        $prestation = $this->prestation('Pose complète gel');

        $crawler = $this->client->request('GET', '/prestations/'.$prestation->getSlug());

        self::assertResponseIsSuccessful();
        self::assertSame('/prestations/pose-complete-gel', parse_url((string) $crawler->filter('link[rel="canonical"]')->attr('href'), \PHP_URL_PATH));
        self::assertSelectorTextContains('h1', 'Pose complète gel');
        self::assertStringContainsString('Arras', $crawler->filter('title')->text());

        $graphe = $this->jsonLd($crawler)[0]['@graph'];
        self::assertSame('Service', $graphe[0]['@type']);
        self::assertSame('55.00', $graphe[0]['offers']['price']);
        self::assertSame('BreadcrumbList', $graphe[1]['@type']);
    }

    public function testUnePrestationInactiveOuInconnueRenvoie404(): void
    {
        $this->client->request('GET', '/prestations/pose-resine-arretee');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/prestations/inconnue');
        self::assertResponseStatusCodeSame(404);
    }

    public function testLesAnciennesAdressesDeGalerieRedirigentDefinitivement(): void
    {
        $this->client->request('GET', '/galerie?theme=automne-cosy');
        self::assertResponseRedirects('/galerie/automne-cosy', 301);

        $prestation = $this->prestation('Pose complète gel');
        $this->client->request('GET', '/galerie?prestation='.$prestation->getId());
        self::assertResponseRedirects('/prestations/pose-complete-gel#realisations', 301);
    }

    public function testLesThemesOntLeurProprePage(): void
    {
        $crawler = $this->client->request('GET', '/galerie/automne-cosy');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Automne cosy');
        self::assertSame('BreadcrumbList', $this->jsonLd($crawler)[0]['@type']);
    }

    public function testLaFaqEstBaliseePourLesMoteurs(): void
    {
        $crawler = $this->client->request('GET', '/questions-frequentes');

        self::assertResponseIsSuccessful();
        $faq = $this->jsonLd($crawler)[0];
        self::assertSame('FAQPage', $faq['@type']);
        self::assertGreaterThanOrEqual(5, \count($faq['mainEntity']));
        self::assertSame('Answer', $faq['mainEntity'][0]['acceptedAnswer']['@type']);
    }

    public function testLesPagesOntUnApercuDePartage(): void
    {
        $crawler = $this->client->request('GET', '/prestations');

        self::assertStringStartsWith('http', (string) $crawler->filter('meta[property="og:image"]')->attr('content'));
        self::assertStringContainsString('Prestations et tarifs', (string) $crawler->filter('meta[property="og:title"]')->attr('content'));
        self::assertSelectorExists('link[rel="icon"][type="image/svg+xml"]');
        self::assertSelectorExists('link[rel="manifest"]');
    }

    public function testLeSitemapListeLesPrestationsLesThemesEtLesPhotos(): void
    {
        $this->client->request('GET', '/sitemap.xml');
        $xml = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('/prestations/pose-complete-gel</loc>', $xml);
        self::assertStringContainsString('/galerie/inspiration-ete</loc>', $xml);
        self::assertStringContainsString('/questions-frequentes</loc>', $xml);
        self::assertStringContainsString('<image:loc>', $xml);
        self::assertNotFalse(simplexml_load_string($xml), 'XML valide.');
    }

    public function testRobotsExclutLesPagesPriveesEtLlmsPresenteLeSalon(): void
    {
        $this->client->request('GET', '/robots.txt');
        $robots = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Disallow: /reservation/suivi/', $robots);
        self::assertStringContainsString('Disallow: /compte', $robots);

        $this->client->request('GET', '/llms.txt');
        self::assertResponseIsSuccessful();
        $llms = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsWith('# Tsyynails', trim($llms));
        self::assertStringContainsString('Pose complète gel', $llms);
        self::assertStringContainsString('/prestations/pose-complete-gel', $llms);
        self::assertStringNotContainsString('&amp;', $llms);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function jsonLd(Crawler $crawler): array
    {
        return $crawler->filter('script[type="application/ld+json"]')->each(
            static fn (Crawler $script): array => json_decode($script->text(), true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    private function prestation(string $nom): Prestation
    {
        $prestation = static::getContainer()->get(PrestationRepository::class)->findOneBy(['nom' => $nom]);
        self::assertInstanceOf(Prestation::class, $prestation);

        return $prestation;
    }
}
