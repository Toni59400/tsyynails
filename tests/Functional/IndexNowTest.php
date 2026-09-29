<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Prestation;
use App\Entity\User;
use App\Repository\PrestationRepository;
use App\Service\Seo\IndexNow;
use App\Tests\Double\ReponsesHttpSimulees;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * IndexNow (INDEXNOW_KEY définie dans .env.test) : clé publiée, pages signalées après une modification
 * dans l'admin et par la commande de soumission complète. Aucun appel réseau (ReponsesHttpSimulees).
 */
final class IndexNowTest extends WebTestCase
{
    use CreationUtilisateurTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        (new CommandTester((new Application(self::$kernel))->find('app:demo:charger')))->execute(['--purger' => true]);
        // Le testeur de commande ne déclenche pas console.terminate : les pages de démonstration partent à la première requête.
        $this->client->request('GET', '/');
        ReponsesHttpSimulees::$requetes = [];
    }

    public function testLaCleEstPublieeEnTexteBrut(): void
    {
        $this->client->request('GET', '/indexnow-cle.txt');

        self::assertResponseIsSuccessful();
        self::assertSame('cle-de-test-indexnow', $this->client->getResponse()->getContent());
    }

    public function testUnePhotoAjouteeDansLAdminSignaleLaGalerieEtLaPrestation(): void
    {
        $prestation = static::getContainer()->get(PrestationRepository::class)->findOneBy(['active' => true]);
        self::assertInstanceOf(Prestation::class, $prestation);
        $this->client->loginUser($this->creerUtilisateur('admin@example.com', [User::ROLE_ADMIN], totpActive: true));
        $jeton = $this->client->request('GET', '/admin/photos/import')->filter('input[name="_token"]')->attr('value');
        self::assertCount(0, ReponsesHttpSimulees::$requetes, 'Consulter l\'admin ne signale rien.');

        $fichier = sys_get_temp_dir().'/indexnow-'.bin2hex(random_bytes(4)).'.png';
        copy(__DIR__.'/../../public/images/icone-576.png', $fichier);
        $this->client->request('POST', '/admin/photos/import/fichier', ['_token' => $jeton, 'prestation' => (string) $prestation->getId(), 'publiee' => '1'], [
            'photo' => new UploadedFile($fichier, 'photo.png', 'image/png', null, true),
        ]);
        self::assertResponseStatusCodeSame(201);

        self::assertCount(1, ReponsesHttpSimulees::$requetes);
        $envoi = ReponsesHttpSimulees::$requetes[0];
        self::assertSame(IndexNow::API, $envoi['url']);
        $corps = json_decode((string) $envoi['options']['body'], true);
        self::assertSame('cle-de-test-indexnow', $corps['key']);
        self::assertStringEndsWith('/indexnow-cle.txt', $corps['keyLocation']);
        self::assertContains('http://localhost/galerie', $corps['urlList']);
        self::assertContains('http://localhost/prestations/'.$prestation->getSlug(), $corps['urlList']);

        $photo = json_decode((string) $this->client->getResponse()->getContent(), true);
        @unlink(static::getContainer()->getParameter('app.dossier_galerie').'/'.$photo['fichier']);
    }

    public function testLaCommandeSignaleToutesLesPagesPubliques(): void
    {
        $testeur = new CommandTester((new Application(self::$kernel))->find('app:indexnow:soumettre'));

        self::assertSame(0, $testeur->execute([]));
        $corps = json_decode((string) ReponsesHttpSimulees::$requetes[0]['options']['body'], true);
        self::assertContains('http://localhost/', $corps['urlList']);
        self::assertContains('http://localhost/questions-frequentes', $corps['urlList']);
        $prestations = static::getContainer()->get(PrestationRepository::class)->findActives();
        self::assertGreaterThanOrEqual(\count($prestations) + 10, \count($corps['urlList']));
    }
}
