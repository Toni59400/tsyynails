<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Client;
use App\Entity\Photo;
use App\Entity\Prestation;
use App\Entity\User;
use App\Enum\MotifMouvementPoints;
use App\Repository\ClientRepository;
use App\Repository\MouvementPointsRepository;
use App\Repository\PhotoRepository;
use App\Repository\PrestationRepository;
use App\Tests\Functional\CreationUtilisateurTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Écrans de l'admin sur une base remplie par les données de démonstration.
 */
final class AdminAvecDonneesTest extends WebTestCase
{
    use CreationUtilisateurTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        (new CommandTester((new Application(self::$kernel))->find('app:demo:charger')))->execute(['--purger' => true]);
        $this->client->loginUser($this->creerUtilisateur('admin@example.com', [User::ROLE_ADMIN], totpActive: true));
    }

    public function testLeTableauDeBordAfficheLesIndicateurs(): void
    {
        $this->client->request('GET', '/admin?periode=mois');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.tdb', 'Chiffre d\'affaires réalisé');
        self::assertSelectorTextContains('.tdb', 'Prochains rendez-vous');
        self::assertSelectorExists('#demandes .liste-rdv__item');
    }

    public function testLAgendaSemaineAfficheLesRendezVousEtLesLiensVersLeDetail(): void
    {
        $crawler = $this->client->request('GET', '/admin/agenda?vue=semaine&date='.(new \DateTimeImmutable('tuesday next week'))->format('Y-m-d'));

        self::assertResponseIsSuccessful();
        self::assertCount(7, $crawler->filter('.agenda-jour'));
        self::assertGreaterThan(0, $crawler->filter('a.agenda-rdv')->count());

        $this->client->click($crawler->filter('a.agenda-rdv')->first()->link());
        self::assertResponseIsSuccessful();
    }

    public function testLaListeDesClientesAfficheLeSoldeDePoints(): void
    {
        $cliente = $this->cliente('Emma');
        $solde = static::getContainer()->get(MouvementPointsRepository::class)->soldePour($cliente);

        $crawler = $this->client->request('GET', '/admin/client?query=Emma');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString((string) $solde, $crawler->filter('tbody tr')->first()->text());
    }

    public function testLaFicheClienteAfficheLaFideliteEtPermetDeCorrigerLesPoints(): void
    {
        $cliente = $this->cliente('Emma');
        $mouvements = static::getContainer()->get(MouvementPointsRepository::class);
        $soldeAvant = $mouvements->soldePour($cliente);

        $crawler = $this->client->request('GET', '/admin/client/'.$cliente->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.fiche-cliente', 'Historique des points');

        $this->client->submit($crawler->selectButton('Enregistrer la correction')->form([
            'delta' => '15',
            'commentaire' => 'Geste commercial',
        ]));

        self::assertResponseRedirects('/admin/client/'.$cliente->getId());
        self::assertSame($soldeAvant + 15, $mouvements->soldePour($cliente));
        $dernier = $mouvements->historiquePour($cliente)[0];
        self::assertSame(MotifMouvementPoints::CORRECTION, $dernier->getMotif());
        self::assertSame('admin@example.com', $dernier->getAuteur());
    }

    public function testUneCorrectionNePeutPasRendreLeSoldeNegatif(): void
    {
        $cliente = $this->cliente('Emma');
        $mouvements = static::getContainer()->get(MouvementPointsRepository::class);
        $soldeAvant = $mouvements->soldePour($cliente);

        $crawler = $this->client->request('GET', '/admin/client/'.$cliente->getId());
        $this->client->submit($crawler->selectButton('Enregistrer la correction')->form([
            'delta' => (string) -($soldeAvant + 1),
            'commentaire' => 'Erreur',
        ]));

        self::assertSame($soldeAvant, $mouvements->soldePour($cliente));
    }

    public function testUneCorrectionSansJetonCsrfEstRefusee(): void
    {
        $cliente = $this->cliente('Emma');

        $this->client->request('POST', '/admin/client/'.$cliente->getId().'/points', ['delta' => '1000', 'commentaire' => 'x', '_token' => 'faux']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testLEnvoiDUnePhotoRetireSesMetadonnees(): void
    {
        $crawler = $this->client->request('GET', '/admin/photo/new');
        self::assertResponseIsSuccessful();

        $fichier = sys_get_temp_dir().'/photo-test-'.bin2hex(random_bytes(4)).'.png';
        file_put_contents($fichier, $this->pngAvecMetadonnees());

        $formulaire = $this->formulaireEasyAdmin($crawler);
        $champFichier = $formulaire['Photo[fichier][file]'];
        self::assertInstanceOf(FileFormField::class, $champFichier);
        $champFichier->upload($fichier);
        $formulaire['Photo[legende]'] = 'French test';
        $this->client->submit($formulaire);

        self::assertResponseRedirects();
        $photo = static::getContainer()->get(PhotoRepository::class)->findOneBy(['legende' => 'French test']);
        self::assertInstanceOf(Photo::class, $photo);
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}\.png$/', $photo->getFichier(), 'Nom de fichier aléatoire, jamais le nom d\'origine.');

        $chemin = static::getContainer()->getParameter('kernel.project_dir').'/public/uploads/galerie/'.$photo->getFichier();
        self::assertFileExists($chemin);
        self::assertStringNotContainsString('GPS', (string) file_get_contents($chemin));
        unlink($chemin);
    }

    public function testLaListeDesPrestationsProposeDAjouterDesPhotos(): void
    {
        $prestation = $this->prestation();

        $crawler = $this->client->request('GET', '/admin/prestation');
        self::assertResponseIsSuccessful();
        $lien = $crawler->filter('a[href*="/admin/photos/import"][href*="prestation='.$prestation->getId().'"]');
        self::assertGreaterThan(0, $lien->count());

        $crawler = $this->client->request('GET', '/admin/photos/import?prestation='.$prestation->getId());
        self::assertResponseIsSuccessful();
        self::assertSame((string) $prestation->getId(), $crawler->filter('#import-prestation option[selected]')->attr('value'));
    }

    public function testLImportEnLotEnregistreChaquePhotoSurLaPrestation(): void
    {
        $prestation = $this->prestation();
        $photos = static::getContainer()->get(PhotoRepository::class);
        $avant = \count($photos->findBy(['prestation' => $prestation]));
        $jeton = $this->client->request('GET', '/admin/photos/import')->filter('input[name="_token"]')->attr('value');

        for ($i = 0; $i < 3; ++$i) {
            $this->client->request('POST', '/admin/photos/import/fichier', [
                '_token' => $jeton,
                'prestation' => (string) $prestation->getId(),
                'publiee' => '1',
            ], ['photo' => $this->fichierPng('ma-photo.png')]);
            self::assertResponseStatusCodeSame(201);
        }

        $importees = $photos->findBy(['prestation' => $prestation], ['id' => 'DESC'], 3);
        self::assertCount($avant + 3, $photos->findBy(['prestation' => $prestation]));
        foreach ($importees as $photo) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{40}\.png$/', $photo->getFichier());
            self::assertSame('Réalisation '.$prestation->getNom(), $photo->getLegende());
            self::assertTrue($photo->isPubliee());
            $chemin = static::getContainer()->getParameter('app.dossier_galerie').'/'.$photo->getFichier();
            self::assertFileExists($chemin);
            self::assertStringNotContainsString('GPS', (string) file_get_contents($chemin));
            unlink($chemin);
        }

        // Aucune limite d'affichage : toutes les photos publiées apparaissent sur la page de la prestation.
        $publiees = \count($photos->findBy(['prestation' => $prestation, 'publiee' => true]));
        $crawler = $this->client->request('GET', '/prestations/'.$prestation->getSlug());
        self::assertResponseIsSuccessful();
        self::assertCount($publiees, $crawler->filter('img[src*="/uploads/galerie/"]:not(.prestation-entete__photo)'));
    }

    public function testLImportRefuseUnFichierQuiNEstPasUneImage(): void
    {
        $jeton = $this->client->request('GET', '/admin/photos/import')->filter('input[name="_token"]')->attr('value');
        $fichier = sys_get_temp_dir().'/faux-'.bin2hex(random_bytes(4)).'.png';
        file_put_contents($fichier, '<?php echo "non";');

        $this->client->request('POST', '/admin/photos/import/fichier', ['_token' => $jeton], [
            'photo' => new UploadedFile($fichier, 'faux.png', null, null, true),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('erreur', json_decode((string) $this->client->getResponse()->getContent(), true));
    }

    public function testLImportSansJetonCsrfEstRefuse(): void
    {
        $this->client->request('POST', '/admin/photos/import/fichier', ['_token' => 'faux'], ['photo' => $this->fichierPng('x.png')]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testLaCommandeDOptimisationSeRelanceSansErreur(): void
    {
        $testeur = new CommandTester((new Application(self::$kernel))->find('app:photos:optimiser'));

        self::assertSame(0, $testeur->execute([]));
        self::assertStringContainsString('photo(s) optimisée(s)', $testeur->getDisplay());
    }

    private function fichierPng(string $nom): UploadedFile
    {
        $fichier = sys_get_temp_dir().'/import-test-'.bin2hex(random_bytes(4)).'.png';
        file_put_contents($fichier, $this->pngAvecMetadonnees());

        return new UploadedFile($fichier, $nom, 'image/png', null, true);
    }

    private function prestation(): Prestation
    {
        $prestation = static::getContainer()->get(PrestationRepository::class)->findOneBy(['active' => true], ['ordre' => 'ASC']);
        self::assertInstanceOf(Prestation::class, $prestation);

        return $prestation;
    }

    private function formulaireEasyAdmin(Crawler $crawler): \Symfony\Component\DomCrawler\Form
    {
        return $crawler->filter('form#new-Photo-form')->form();
    }

    private function cliente(string $prenom): Client
    {
        $cliente = static::getContainer()->get(ClientRepository::class)->findOneBy(['prenom' => $prenom]);
        self::assertInstanceOf(Client::class, $cliente);

        return $cliente;
    }

    /**
     * PNG valide de 1 × 1 pixel avec un bloc de texte contenant une position GPS.
     */
    private function pngAvecMetadonnees(): string
    {
        $bloc = static fn (string $type, string $donnees): string => pack('N', \strlen($donnees)).$type.$donnees.pack('N', crc32($type.$donnees));

        return "\x89PNG\r\n\x1A\n"
            .$bloc('IHDR', pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 0))
            .$bloc('tEXt', 'Location'."\0".'GPS 50.62N 3.05E')
            .$bloc('IDAT', (string) gzcompress("\0\xC9\x8F\x8F"))
            .$bloc('IEND', '');
    }
}
