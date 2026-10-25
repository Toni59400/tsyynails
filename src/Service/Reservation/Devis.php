<?php

declare(strict_types=1);

namespace App\Service\Reservation;

/**
 * Détail du prix d'un rendez-vous, calculé côté serveur (Tarification::devis).
 *
 * prix total = prestations + supplément + majoration du soir ;
 * la remise (duo, réduction à vie ou fixée par l'admin) porte sur les prestations et le supplément seulement.
 */
final class Devis
{
    public function __construct(
        /** Prestation(s) et supplément. */
        public readonly int $baseCentimes,
        public readonly int $majorationCentimes,
        public readonly int $remisePourcentage,
        public readonly ?string $remiseMotif,
        public readonly int $dureeMinutes,
    ) {
    }

    public function remiseCentimes(): int
    {
        return \App\Entity\Reservation::calculerRemise($this->baseCentimes, $this->remisePourcentage);
    }

    public function prixCentimes(): int
    {
        return $this->baseCentimes + $this->majorationCentimes;
    }

    /** À payer avant une éventuelle récompense de points. */
    public function aPayerCentimes(): int
    {
        return $this->prixCentimes() - $this->remiseCentimes();
    }
}
