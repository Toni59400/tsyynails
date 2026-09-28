<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\Functional\CreationUtilisateurTrait;
use OTPHP\TOTP;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ConnexionTest extends WebTestCase
{
    use CreationUtilisateurTrait;

    public function testLaPageDeConnexionSAffiche(): void
    {
        $client = static::createClient();
        $client->request('GET', '/connexion');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="_csrf_token"]');
    }

    public function testUnMauvaisMotDePasseAfficheUneErreur(): void
    {
        $client = static::createClient();
        $this->creerUtilisateur('admin@example.com', [User::ROLE_ADMIN], totpActive: true);

        $this->seConnecter($client, 'admin@example.com', 'mauvais-mot-de-passe');

        self::assertResponseRedirects('/connexion');
        $client->followRedirect();
        self::assertSelectorExists('.alerte--erreur');
    }

    public function testUnAdminDoitSaisirSonCodeApresLeMotDePasse(): void
    {
        $client = static::createClient();
        $this->creerUtilisateur('admin@example.com', [User::ROLE_ADMIN], totpActive: true);

        $this->seConnecter($client, 'admin@example.com', self::MOT_DE_PASSE);
        $client->followRedirect();
        self::assertResponseRedirects('/2fa');

        // L'admin reste bloqué tant que le code n'est pas saisi.
        $client->request('GET', '/admin');
        self::assertResponseRedirects('/2fa');

        $crawler = $client->request('GET', '/2fa');
        $client->submit($crawler->selectButton('Valider')->form([
            '_auth_code' => TOTP::createFromSecret(self::SECRET_TOTP)->now(),
        ]));
        // Retour vers la page demandée pendant la vérification.
        self::assertResponseRedirects('/admin');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testActivationDeLaDoubleAuthentification(): void
    {
        $client = static::createClient();
        $client->loginUser($this->creerUtilisateur('admin@example.com', [User::ROLE_ADMIN]));

        $crawler = $client->request('GET', '/admin/securite/double-authentification');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('img.auth__qr');
        $secret = trim($crawler->filter('.auth__secret')->text());

        $client->submit($crawler->selectButton('Activer')->form(['code' => '000000']));
        self::assertSelectorExists('.alerte--erreur');
        self::assertFalse($this->admin()->isTotpAuthenticationEnabled());

        $crawler = $client->request('GET', '/admin/securite/double-authentification');
        self::assertSame($secret, trim($crawler->filter('.auth__secret')->text()), 'Le secret en attente reste le même pendant la session.');
        $client->submit($crawler->selectButton('Activer')->form(['code' => TOTP::createFromSecret($secret)->now()]));

        self::assertResponseRedirects('/admin');
        self::assertTrue($this->admin()->isTotpAuthenticationEnabled());
    }

    private function seConnecter(KernelBrowser $client, string $email, string $motDePasse): void
    {
        $crawler = $client->request('GET', '/connexion');
        $client->submit($crawler->selectButton('Se connecter')->form([
            'email' => $email,
            'password' => $motDePasse,
        ]));
    }

    private function admin(): User
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'admin@example.com']);
        self::assertInstanceOf(User::class, $user);
        static::getContainer()->get('doctrine')->getManager()->refresh($user);

        return $user;
    }
}
