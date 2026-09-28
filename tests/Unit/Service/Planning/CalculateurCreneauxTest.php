<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Planning;

use App\Entity\HoraireOuverture;
use App\Service\Planning\CalculateurCreneaux;
use PHPUnit\Framework\TestCase;

final class CalculateurCreneauxTest extends TestCase
{
    /** Mardi. */
    private const JOUR = '2026-10-06';

    public function testLesCreneauxRespectentLaDureeEtLePasDe15Minutes(): void
    {
        $creneaux = $this->calculer([$this->plage(2, '09:00', '10:30')], [], 60);

        self::assertSame(['09:00', '09:15', '09:30'], $this->heures($creneaux[self::JOUR]));
    }

    public function testUnePauseCoupeLaJournee(): void
    {
        $horaires = [$this->plage(2, '09:00', '10:00'), $this->plage(2, '14:00', '15:00')];

        $creneaux = $this->calculer($horaires, [], 60);

        self::assertSame(['09:00', '14:00'], $this->heures($creneaux[self::JOUR]));
    }

    public function testUnJourSansHoraireNaAucunCreneau(): void
    {
        $creneaux = $this->calculer([$this->plage(3, '09:00', '18:00')], [], 60);

        self::assertSame([], $creneaux[self::JOUR]);
    }

    public function testUneReservationBloqueLesCreneauxQuiLaChevauchent(): void
    {
        $occupation = [new \DateTimeImmutable(self::JOUR.' 10:00'), new \DateTimeImmutable(self::JOUR.' 11:00')];

        $creneaux = $this->calculer([$this->plage(2, '09:00', '12:00')], [$occupation], 60);

        self::assertSame(['09:00', '11:00'], $this->heures($creneaux[self::JOUR]));
    }

    public function testUneIndisponibiliteSurPlusieursJoursVideLaJournee(): void
    {
        $conges = [new \DateTimeImmutable('2026-10-05 00:00'), new \DateTimeImmutable('2026-10-10 00:00')];

        $creneaux = $this->calculer([$this->plage(2, '09:00', '18:00')], [$conges], 60);

        self::assertSame([], $creneaux[self::JOUR]);
    }

    public function testAucunCreneauAvantLeDelaiMinimum(): void
    {
        $creneaux = CalculateurCreneaux::calculer(
            [$this->plage(2, '09:00', '12:00')],
            [],
            60,
            new \DateTimeImmutable(self::JOUR),
            1,
            new \DateTimeImmutable(self::JOUR.' 10:10'),
        );

        self::assertSame(['10:15', '10:30', '10:45', '11:00'], $this->heures($creneaux[self::JOUR]));
    }

    public function testChaqueJourDeLaPeriodeEstPresent(): void
    {
        $creneaux = CalculateurCreneaux::calculer([], [], 60, new \DateTimeImmutable(self::JOUR), 7, new \DateTimeImmutable('2026-01-01'));

        self::assertCount(7, $creneaux);
        self::assertArrayHasKey('2026-10-12', $creneaux);
    }

    /**
     * @param list<HoraireOuverture>                                    $horaires
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $occupations
     *
     * @return array<string, list<\DateTimeImmutable>>
     */
    private function calculer(array $horaires, array $occupations, int $duree): array
    {
        return CalculateurCreneaux::calculer($horaires, $occupations, $duree, new \DateTimeImmutable(self::JOUR), 1, new \DateTimeImmutable('2026-01-01'));
    }

    private function plage(int $jour, string $debut, string $fin): HoraireOuverture
    {
        return new HoraireOuverture($jour, new \DateTimeImmutable($debut), new \DateTimeImmutable($fin));
    }

    /**
     * @param list<\DateTimeImmutable> $creneaux
     *
     * @return list<string>
     */
    private function heures(array $creneaux): array
    {
        return array_map(static fn (\DateTimeImmutable $d): string => $d->format('H:i'), $creneaux);
    }
}
