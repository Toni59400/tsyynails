<?php

declare(strict_types=1);

namespace App\Service\Reservation;

use App\Entity\Reservation;

/**
 * Reconstruction d'ongles ajoutée à un rendez-vous : nombre d'ongles, prix et durée totaux
 * (calculés par Tarification::reconstruction, puis copiés sur la réservation).
 */
final class Reconstruction
{
    public function __construct(
        public readonly int $ongles,
        public readonly int $prixCentimes,
        public readonly int $minutes,
    ) {
        if ($ongles < 1 || $prixCentimes < 0 || $minutes < 0) {
            throw new \InvalidArgumentException('Reconstruction invalide.');
        }
    }

    /** Reconstruction déjà enregistrée sur une réservation (conservée quand la prothésiste la modifie). */
    public static function depuisReservation(Reservation $reservation): ?self
    {
        return $reservation->getReconstructionOngles() > 0
            ? new self($reservation->getReconstructionOngles(), $reservation->getReconstructionPrixCentimes(), $reservation->getReconstructionMinutes())
            : null;
    }

    public static function libelle(int $ongles): string
    {
        return \sprintf('Reconstruction d\'ongles (%d ongle%s)', $ongles, $ongles > 1 ? 's' : '');
    }
}
