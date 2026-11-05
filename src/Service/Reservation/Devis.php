<?php

declare(strict_types=1);

namespace App\Service\Reservation;

/**
 * Détail du prix d'un rendez-vous, calculé côté serveur (Tarification::devis).
 *
 * prix total = prestations + supplément + reconstruction + majoration du soir ;
 * la remise (duo, réduction VIP ou fixée par l'admin) porte sur tout sauf la majoration.
 */
final class Devis
{
    public function __construct(
        /** Prestation(s), supplément et reconstruction. */
        public readonly int $baseCentimes,
        public readonly int $majorationCentimes,
        public readonly int $remisePourcentage,
        public readonly ?string $remiseMotif,
        public readonly int $dureeMinutes,
        public readonly ?Reconstruction $reconstruction = null,
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
