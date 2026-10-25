<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Reservation;
use App\Service\Reservation\DetailPrix;
use App\Service\Reservation\Tarification;
use Twig\Attribute\AsTwigFunction;

final class PrixExtension
{
    public function __construct(private readonly Tarification $tarification)
    {
    }

    /**
     * Lignes du détail de prix d'une réservation (prestations, supplément, remise, majoration, points).
     *
     * @return list<array{libelle: string, montant: int}>
     */
    #[AsTwigFunction('detail_prix')]
    public function detailPrix(Reservation $reservation): array
    {
        return DetailPrix::pourReservation($reservation);
    }

    /** Remise du RDV duo en %, ou null si l'offre est désactivée dans les réglages. */
    #[AsTwigFunction('offre_duo')]
    public function offreDuo(): ?int
    {
        return $this->tarification->duoActif() ? $this->tarification->remise(null, true)[0] : null;
    }
}
