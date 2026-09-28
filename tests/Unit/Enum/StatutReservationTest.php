<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\StatutReservation;
use PHPUnit\Framework\TestCase;

final class StatutReservationTest extends TestCase
{
    public function testUnPaiementEnCoursDevientUneDemandeOuExpire(): void
    {
        $statut = StatutReservation::PAIEMENT_EN_COURS;

        self::assertTrue($statut->peutPasserA(StatutReservation::EN_ATTENTE));
        self::assertTrue($statut->peutPasserA(StatutReservation::EXPIREE));
        self::assertFalse($statut->peutPasserA(StatutReservation::CONFIRMEE), 'Pas de validation sans empreinte.');
    }

    public function testUneDemandeEnAttentePeutEtreValideeRefuseeExpireeOuAnnulee(): void
    {
        $statut = StatutReservation::EN_ATTENTE;

        self::assertTrue($statut->peutPasserA(StatutReservation::CONFIRMEE));
        self::assertTrue($statut->peutPasserA(StatutReservation::REFUSEE));
        self::assertTrue($statut->peutPasserA(StatutReservation::EXPIREE));
        self::assertTrue($statut->peutPasserA(StatutReservation::ANNULEE));
        self::assertFalse($statut->peutPasserA(StatutReservation::HONOREE));
    }

    public function testUneReservationConfirmeeSeTermineParVenueAbsenceOuAnnulation(): void
    {
        $statut = StatutReservation::CONFIRMEE;

        self::assertTrue($statut->peutPasserA(StatutReservation::HONOREE));
        self::assertTrue($statut->peutPasserA(StatutReservation::NON_HONOREE));
        self::assertTrue($statut->peutPasserA(StatutReservation::ANNULEE));
        self::assertFalse($statut->peutPasserA(StatutReservation::EN_ATTENTE));
    }

    public function testLesStatutsFinauxSontDefinitifs(): void
    {
        foreach ([StatutReservation::REFUSEE, StatutReservation::EXPIREE, StatutReservation::ANNULEE, StatutReservation::HONOREE, StatutReservation::NON_HONOREE] as $statut) {
            self::assertSame([], $statut->transitionsPossibles(), $statut->value);
        }
    }

    public function testSeulesLesDemandesEnCoursBloquentLeCreneau(): void
    {
        self::assertSame(
            [StatutReservation::PAIEMENT_EN_COURS, StatutReservation::EN_ATTENTE, StatutReservation::CONFIRMEE],
            StatutReservation::bloquantLeCreneau(),
        );
    }
}
