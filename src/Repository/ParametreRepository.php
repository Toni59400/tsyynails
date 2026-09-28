<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Parametre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Parametre>
 */
class ParametreRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Parametre::class);
    }

    public function entier(string $cle, int $parDefaut): int
    {
        $parametre = $this->find($cle);

        return null === $parametre ? $parDefaut : (int) $parametre->getValeur();
    }

    /**
     * Valeur d'un réglage connu (Parametre::DEFINITIONS), ou sa valeur par défaut.
     */
    public function valeur(string $cle): int
    {
        if (!isset(Parametre::DEFINITIONS[$cle])) {
            throw new \InvalidArgumentException(\sprintf('Réglage inconnu : %s', $cle));
        }

        return $this->entier($cle, Parametre::DEFINITIONS[$cle][2]);
    }
}
