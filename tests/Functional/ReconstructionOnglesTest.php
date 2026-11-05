<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Parametre;
use App\Entity\Prestation;
use App\Entity\Reservation;
use App\Enum\StatutReservation;
use App\Repository\PrestationRepository;
use App\Repository\ReservationRepository;
use App\Service\Reservation\ReservationWorkflow;
use App\Service\Reservation\Tarification;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * Option « Reconstruction d'ongles » du tunnel de réservation : nombre d'ongles, prix et durée par ongle réglables.
 */
final class ReconstructionOnglesTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        (new CommandTester((new Application(self::$kernel))->find('app:demo:charger')))->execute(['--purger' => true]);
    }

    public function testDesactiveeElleNEstNiProposeeNiAcceptee(): void
    {
        $semi = $this->prestation('Semi-permanent mains');

        $this->client->request('GET', '/reservation/'.$semi->getId());
        self::assertSelectorNotExists('select[name="ongles"]');

        $this->client->request('GET', '/reservation/'.$semi->getId().'?ongles=2');
        self::assertResponseRedirects('/reservation/'.$semi->getId());
    }

    public function testLaClienteChoisitLeNombreDOnglesEtLePrixEtLaDureeSAjustent(): void
    {
        $this->regler([Parametre::RECONSTRUCTION_ACTIVE => 1, Parametre::RECONSTRUCTION_PRIX_ONGLE => 5, Parametre::RECONSTRUCTION_MINUTES_ONGLE => 10, Parametre::RECONSTRUCTION_MAX_ONGLES => 6]);
        $semi = $this->prestation('Semi-permanent mains');

        $crawler = $this->client->request('GET', '/reservation/'.$semi->getId());
        self::assertCount(7, $crawler->filter('select[name="ongles"] option'), 'Aucune, puis 1 à 6 ongles.');
        self::assertSelectorTextContains('select[name="ongles"]', '3 ongles · + 15');

        $crawler = $this->client->request('GET', '/reservation/'.$semi->getId().'?ongles=3');
        self::assertSelectorTextContains('.recap-court', 'reconstruction de 3 ongles');
        self::assertSelectorExists('select[name="ongles"] option[value="3"][selected]');
        $crawler = $this->client->click($crawler->filter('a.creneau')->first()->link());
        self::assertStringContainsString('ongles=3', (string) $this->client->getRequest()->getUri());
        self::assertSelectorTextContains('.recap', 'Reconstruction d\'ongles (3 ongles)');

        $formulaire = $crawler->selectButton('Continuer vers l\'acompte')->form();
        $formulaire['coordonnees[prenom]'] = 'Zoé';
        $formulaire['coordonnees[nom]'] = 'Test';
        $formulaire['coordonnees[telephone]'] = '06 39 98 33 33';
        $formulaire['coordonnees[email]'] = 'zoe.test@example.com';
        $conditions = $formulaire['coordonnees[accepteConditions]'];
        self::assertInstanceOf(ChoiceFormField::class, $conditions);
        $conditions->tick();
        $this->client->submit($formulaire);
        self::assertResponseRedirects();

        $reservation = $this->derniereReservation();
        self::assertSame(3, $reservation->getReconstructionOngles());
        self::assertSame(1500, $reservation->getReconstructionPrixCentimes());
        self::assertSame(3000 + 1500, $reservation->getPrixCentimes(), '30 € + 3 × 5 €.');
        self::assertSame(60 + 30, $this->dureeMinutes($reservation), '60 min + 3 × 10 min.');

        // Un changement de tarif ne touche pas la réservation ; une modification par la prothésiste garde la reconstruction.
        $this->regler([Parametre::RECONSTRUCTION_PRIX_ONGLE => 8]);
        $reservation->changerStatut(StatutReservation::EN_ATTENTE, new \DateTimeImmutable());
        $nouveauDebut = $reservation->getDebut()->modify('+30 minutes');
        static::getContainer()->get(ReservationWorkflow::class)->modifier($reservation, $reservation->getPrestation(), $nouveauDebut, null, null, 0, 'test', false);
        self::assertSame(3, $reservation->getReconstructionOngles());
        self::assertSame(4500, $reservation->getPrixCentimes());
        self::assertSame(90, $this->dureeMinutes($reservation));
    }

    public function testLeTarifEstDegressifParTranches(): void
    {
        $this->regler([
            Parametre::RECONSTRUCTION_ACTIVE => 1, Parametre::RECONSTRUCTION_PRIX_ONGLE => 5, Parametre::RECONSTRUCTION_MAX_ONGLES => 10,
            Parametre::RECONSTRUCTION_PALIER2_DES => 4, Parametre::RECONSTRUCTION_PALIER2_PRIX => 4,
            Parametre::RECONSTRUCTION_PALIER3_DES => 7, Parametre::RECONSTRUCTION_PALIER3_PRIX => 3,
        ]);
        $tarification = static::getContainer()->get(Tarification::class);
        $prix = static fn (int $ongles): ?int => $tarification->reconstruction($ongles)?->prixCentimes;

        // 5 € les 3 premiers, 4 € du 4e au 6e, 3 € à partir du 7e.
        self::assertSame([500, 1500, 1900, 2700, 3000, 3900], [$prix(1), $prix(3), $prix(4), $prix(6), $prix(7), $prix(10)]);

        $this->client->request('GET', '/reservation/'.$this->prestation('Semi-permanent mains')->getId());
        self::assertSelectorTextContains('.options-resa__degressif', 'Tarif dégressif');
        self::assertSelectorTextContains('.options-resa__degressif', 'dès le 7');

        // 3e palier pas après le 2e : ignoré. Sans 2e palier : prix unique.
        $this->regler([Parametre::RECONSTRUCTION_PALIER3_DES => 3]);
        self::assertCount(2, $tarification->grilleReconstruction());
        $this->regler([Parametre::RECONSTRUCTION_PALIER2_DES => 0, Parametre::RECONSTRUCTION_PALIER3_DES => 0]);
        self::assertSame(5000, $prix(10));
    }

    public function testUnNombreDOnglesHorsLimitesEstRefuse(): void
    {
        $this->regler([Parametre::RECONSTRUCTION_ACTIVE => 1, Parametre::RECONSTRUCTION_MAX_ONGLES => 4]);
        $semi = $this->prestation('Semi-permanent mains');

        foreach (['5', 'abc', '-1'] as $valeur) {
            $this->client->request('GET', '/reservation/'.$semi->getId().'?ongles='.$valeur);
            self::assertResponseRedirects('/reservation/'.$semi->getId(), 0, 'ongles='.$valeur);
        }
        $this->client->request('GET', '/reservation/'.$semi->getId().'?ongles=0');
        self::assertResponseIsSuccessful();
    }

    /**
     * @param array<string, int> $valeurs
     */
    private function regler(array $valeurs): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ($valeurs as $cle => $valeur) {
            $em->persist(($em->find(Parametre::class, $cle) ?? new Parametre($cle, '0'))->setValeur((string) $valeur));
        }
        $em->flush();
    }

    private function dureeMinutes(Reservation $reservation): int
    {
        return (int) (($reservation->getFin()->getTimestamp() - $reservation->getDebut()->getTimestamp()) / 60);
    }

    private function derniereReservation(): Reservation
    {
        $reservation = static::getContainer()->get(ReservationRepository::class)->findOneBy([], ['id' => 'DESC']);
        self::assertInstanceOf(Reservation::class, $reservation);

        return $reservation;
    }

    private function prestation(string $nom): Prestation
    {
        $prestation = static::getContainer()->get(PrestationRepository::class)->findOneBy(['nom' => $nom]);
        self::assertInstanceOf(Prestation::class, $prestation);

        return $prestation;
    }
}
