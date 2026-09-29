<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Bandeau de consentement (GOOGLE_TAG_MANAGER_ID défini dans .env.test) : aucun script Google
 * dans le HTML, refus aussi accessible que l'accord, choix modifiable depuis chaque page.
 */
final class ConsentementCookiesTest extends WebTestCase
{
    public function testAucunTraceurNEstChargeAvantLeConsentement(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('googletagmanager.com', $html, 'Google Tag Manager est chargé par le JavaScript, seulement après accord.');
        self::assertStringNotContainsString('<noscript><iframe', $html);
        self::assertSame('GTM-TEST123', $crawler->filter('[data-controller="consentement"]')->attr('data-consentement-gtm-value'));
    }

    public function testLeBandeauProposeRefuserAussiSimplementQuAccepter(): void
    {
        $crawler = static::createClient()->request('GET', '/prestations');

        $bandeau = $crawler->filter('.consentement');
        self::assertNotNull($bandeau->attr('hidden'), 'Masqué tant que le JavaScript n\'a pas vérifié le choix.');
        self::assertSame('bouton', $bandeau->filter('[data-action="consentement#refuser"]')->attr('class'));
        self::assertSame('bouton', $bandeau->filter('[data-action="consentement#accepter"]')->attr('class'));
        self::assertNull($bandeau->filter('#consentement-statistiques')->attr('checked'), 'Case non pré-cochée.');
        self::assertCount(1, $crawler->filter('footer [data-action="consentement#ouvrir"]'));
    }

    public function testLaPolitiqueDeConfidentialiteDetailleLesCookies(): void
    {
        $crawler = static::createClient()->request('GET', '/confidentialite');

        self::assertSelectorTextContains('#cookies ~ h3', 'Cookies indispensables');
        self::assertStringContainsString('Google Ireland', $crawler->filter('main')->text());
        self::assertStringContainsString('13 mois', $crawler->filter('main')->text());
    }
}
