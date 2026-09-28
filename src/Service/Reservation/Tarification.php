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
    public const ACOMPTE_POURCENTAGE_PAR_DEFAUT = 30;

    public function __construct(private readonly ParametreRepository $parametres)
    {
    }

    public function acomptePourcentage(): int
    {
        return $this->parametres->entier(Parametre::ACOMPTE_POURCENTAGE, self::ACOMPTE_POURCENTAGE_PAR_DEFAUT);
    }

    public function acompteCentimes(Prestation $prestation): int
    {
        return self::calculerAcompte($prestation->getPrixCentimes(), $this->acomptePourcentage());
    }

    /** Arrondi à l'euro supérieur, sans dépasser le prix. */
    public static function calculerAcompte(int $prixCentimes, int $pourcentage): int
    {
        $acompte = (int) ceil($prixCentimes * $pourcentage / 100 / 100) * 100;

        return min($prixCentimes, max(0, $acompte));
    }
}
