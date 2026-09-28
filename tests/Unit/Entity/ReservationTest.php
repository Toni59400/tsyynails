<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Client;
use App\Entity\Prestation;
use App\Entity\Reservation;
use App\Enum\StatutReservation;
use PHPUnit\Framework\TestCase;

final class ReservationTest extends TestCase
{
    public function testLaFinEstCalculeeDepuisLaDureeEtLePrixEstFige(): void
    {
        $prestation = new Prestation('Pose gel', 4500, 90);
        $reservation = $this->reservation($prestation);

        $prestation->setPrixCentimes(5000);

        self::assertEquals(new \DateTimeImmutable('2026-10-05 11:30'), $reservation->getFin());
        self::assertSame(4500, $reservation->getPrixCentimes());
        self::assertSame(StatutReservation::EN_ATTENTE, $reservation->getStatut());
    }

    public function testLeResteAPayerDeduitReductionEtAcompte(): void
    {
        $reservation = new Reservation($this->client(), new Prestation('Pose gel', 4500, 90), new \DateTimeImmutable('2026-10-05 10:00'), 1000, 500, 50);

        self::assertSame(3000, $reservation->getResteAPayerCentimes());
    }

    public function testUneReductionSuperieureAuPrixEstRefusee(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Reservation($this->client(), new Prestation('Pose gel', 4500, 90), new \DateTimeImmutable('2026-10-05 10:00'), 1000, 5000);
    }

    public function testUneTransitionInterditeLeveUneException(): void
    {
        $reservation = $this->reservation(new Prestation('Pose gel', 4500, 90));

        $this->expectException(\LogicException::class);

        $reservation->changerStatut(StatutReservation::HONOREE, new \DateTimeImmutable());
    }

    public function testUneTransitionAutoriseeEnregistreLaDateDeDecision(): void
    {
        $reservation = $this->reservation(new Prestation('Pose gel', 4500, 90));
        $maintenant = new \DateTimeImmutable('2026-10-01 09:00');

        $reservation->changerStatut(StatutReservation::CONFIRMEE, $maintenant);

        self::assertSame(StatutReservation::CONFIRMEE, $reservation->getStatut());
        self::assertSame($maintenant, $reservation->getDecisionAt());
    }

    private function reservation(Prestation $prestation): Reservation
    {
        return new Reservation($this->client(), $prestation, new \DateTimeImmutable('2026-10-05 10:00'), 1000);
    }

    private function client(): Client
    {
        return new Client('Léa', 'Martin', '+33612345678');
    }
}
