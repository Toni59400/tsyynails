<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Service\Paiement\DiagnosticStripe;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Passage en production : mentions légales complètes et diagnostic Stripe (mode, cohérence des clés, webhook).
 */
final class ProductionStripeTest extends WebTestCase
{
    use CreationUtilisateurTrait;

    public function testLesMentionsLegalesAffichentSirenSiretEtStatut(): void
    {
        static::createClient()->request('GET', '/mentions-legales');

        self::assertSelectorTextContains('main', 'SIREN : 132 172 198');
        self::assertSelectorTextContains('main', 'SIRET : 132 172 198 00013');
        self::assertSelectorTextContains('main', 'entrepreneur individuel (ei)');
    }

    public function testLeModeEtLesErreursDeConfigurationSontDetectes(): void
    {
        static::bootKernel();
        $routeur = static::getContainer()->get(UrlGeneratorInterface::class);

        $simulation = new DiagnosticStripe('', '', '', $routeur, new NullLogger());
        self::assertSame('simulation', $simulation->mode());

        // Clés de modes différents, secret du webhook absent.
        $incoherent = new DiagnosticStripe('sk_live_abc', 'pk_test_abc', '', $routeur, new NullLogger());
        self::assertSame('live', $incoherent->mode());
        $problemes = implode(' ', $incoherent->problemesLocaux());
        self::assertStringContainsString('pas du même mode', $problemes);
        self::assertStringContainsString('STRIPE_WEBHOOK_SECRET', $problemes);

        // Clé restreinte de production, configuration complète.
        $complet = new DiagnosticStripe('rk_live_abc', 'pk_live_abc', 'whsec_abc', $routeur, new NullLogger());
        self::assertSame('live', $complet->mode());
        self::assertSame([], $complet->problemesLocaux());
        self::assertStringEndsWith('/stripe/webhook', $complet->urlWebhook());
    }

    public function testLaPageDeDiagnosticStripeSAfficheDansLAdmin(): void
    {
        $client = static::createClient();
        $client->loginUser($this->creerUtilisateur('admin@example.com', [User::ROLE_ADMIN], totpActive: true));

        $client->request('GET', '/admin/reglages/stripe');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.tdb-carte', 'Simulation');
        self::assertSelectorTextContains('#main', 'payment_intent.succeeded');
    }
}
