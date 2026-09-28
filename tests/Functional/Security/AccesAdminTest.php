<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Tests\Functional\CreationUtilisateurTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AccesAdminTest extends WebTestCase
{
    use CreationUtilisateurTrait;

    public function testUnVisiteurEstRedirigeVersLaConnexion(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin');

        self::assertResponseRedirects('/connexion');
    }

    public function testUneClienteNAPasAccesALAdmin(): void
    {
        $client = static::createClient();
        $client->loginUser($this->creerUtilisateur('cliente@example.com'));
        $client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(403);
    }

    public function testUnAdminSansDoubleAuthentificationDoitLActiver(): void
    {
        $client = static::createClient();
        $client->loginUser($this->creerUtilisateur('admin@example.com', [User::ROLE_ADMIN]));

        $client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/securite/double-authentification');

        $client->request('GET', '/admin/prestation');
        self::assertResponseRedirects('/admin/securite/double-authentification');
    }

    #[DataProvider('pagesAdmin')]
    public function testLesPagesDeLAdminSAffichent(string $url): void
    {
        $client = static::createClient();
        $client->loginUser($this->creerUtilisateur('admin@example.com', [User::ROLE_ADMIN], totpActive: true));
        $client->request('GET', $url);

        self::assertResponseIsSuccessful();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pagesAdmin(): iterable
    {
        yield 'tableau de bord' => ['/admin'];
        yield 'réservations' => ['/admin/reservation'];
        yield 'clientes' => ['/admin/client'];
        yield 'nouvelle cliente' => ['/admin/client/new'];
        yield 'prestations' => ['/admin/prestation'];
        yield 'nouvelle prestation' => ['/admin/prestation/new'];
        yield 'horaires' => ['/admin/horaire-ouverture'];
        yield 'nouvel horaire' => ['/admin/horaire-ouverture/new'];
        yield 'congés' => ['/admin/indisponibilite'];
        yield 'nouveau congé' => ['/admin/indisponibilite/new'];
    }
}
