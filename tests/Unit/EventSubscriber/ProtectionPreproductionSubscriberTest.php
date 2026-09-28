<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\ProtectionPreproductionSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ProtectionPreproductionSubscriberTest extends TestCase
{
    private const MOT_DE_PASSE = 'secret-de-test';

    public function testDesactiveeSansConfiguration(): void
    {
        self::assertNull($this->reponse('', Request::create('/')));
    }

    public function testDemandeUnIdentifiantSansAuthentification(): void
    {
        $reponse = $this->reponse($this->acces(), Request::create('/'));

        self::assertSame(401, $reponse?->getStatusCode());
        self::assertStringStartsWith('Basic', (string) $reponse->headers->get('WWW-Authenticate'));
        self::assertSame('noindex, nofollow', $reponse->headers->get('X-Robots-Tag'));
    }

    public function testRefuseUnMauvaisMotDePasse(): void
    {
        $requete = Request::create('/', server: ['PHP_AUTH_USER' => 'tsyy', 'PHP_AUTH_PW' => 'faux']);

        self::assertSame(401, $this->reponse($this->acces(), $requete)?->getStatusCode());
    }

    public function testLaisseEntrerAvecLesBonsIdentifiants(): void
    {
        $requete = Request::create('/admin', server: ['PHP_AUTH_USER' => 'tsyy', 'PHP_AUTH_PW' => self::MOT_DE_PASSE]);

        self::assertNull($this->reponse($this->acces(), $requete));
    }

    public function testLeWebhookStripeResteJoignable(): void
    {
        self::assertNull($this->reponse($this->acces(), Request::create('/stripe/webhook', 'POST')));
    }

    private function acces(): string
    {
        return 'tsyy:'.password_hash(self::MOT_DE_PASSE, \PASSWORD_DEFAULT);
    }

    private function reponse(string $acces, Request $requete): ?\Symfony\Component\HttpFoundation\Response
    {
        $noyau = $this->createStub(HttpKernelInterface::class);
        $evenement = new RequestEvent($noyau, $requete, HttpKernelInterface::MAIN_REQUEST);
        (new ProtectionPreproductionSubscriber($acces))->verifier($evenement);

        return $evenement->getResponse();
    }
}
