<?php

declare(strict_types=1);

namespace App\Service\Reservation;

use App\Entity\Parametre;
use App\Entity\Prestation;
use App\Repository\ParametreRepository;

/**
 * Montants d'une réservation, toujours calculés côté serveur.
 */
final class Tarification
{
    public function __construct(private readonly ParametreRepository $parametres)
    {
    }

    public function acomptePourcentage(): int
    {
        return $this->parametres->valeur(Parametre::ACOMPTE_POURCENTAGE);
    }

    /** Acompte calculé sur le prix restant après la réduction fidélité. */
    public function acompteCentimes(Prestation $prestation, int $reductionCentimes = 0): int
    {
        return self::calculerAcompte($prestation->getPrixCentimes() - $reductionCentimes, $this->acomptePourcentage());
    }

    /** Arrondi à l'euro supérieur, sans dépasser le prix. */
    public static function calculerAcompte(int $prixCentimes, int $pourcentage): int
    {
        $acompte = (int) ceil($prixCentimes * $pourcentage / 100 / 100) * 100;

        return min($prixCentimes, max(0, $acompte));
    }
}
