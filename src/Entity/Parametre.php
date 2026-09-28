<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ParametreRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Réglage modifiable depuis l'admin (clé / valeur).
 */
#[ORM\Entity(repositoryClass: ParametreRepository::class)]
class Parametre
{
    /** Valeur d'un point de fidélité en centimes. */
    public const VALEUR_POINT_CENTIMES = 'fidelite.valeur_point_centimes';
    /** Acompte demandé à la réservation, en pourcentage du prix. */
    public const ACOMPTE_POURCENTAGE = 'reservation.acompte_pourcentage';
    /** Délai minimum avant un rendez-vous pour réserver, en heures. */
    public const DELAI_MIN_RESERVATION_HEURES = 'reservation.delai_min_heures';

    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $cle;

    #[ORM\Column(length: 255)]
    private string $valeur;

    public function __construct(string $cle, string $valeur)
    {
        $this->cle = $cle;
        $this->valeur = $valeur;
    }

    public function getCle(): string
    {
        return $this->cle;
    }

    public function getValeur(): string
    {
        return $this->valeur;
    }

    public function setValeur(string $valeur): static
    {
        $this->valeur = $valeur;

        return $this;
    }
}
