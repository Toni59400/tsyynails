<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Prestation;
use App\Entity\Supplement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Supplement>
 */
class SupplementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Supplement::class);
    }

    /**
     * Suppléments actifs proposés pour cette prestation, dans l'ordre d'affichage.
     *
     * @return list<Supplement>
     */
    public function findProposesPour(Prestation $prestation): array
    {
        /** @var list<Supplement> $actifs */
        $actifs = $this->createQueryBuilder('s')
            ->leftJoin('s.prestations', 'p')
            ->addSelect('p')
            ->andWhere('s.active = true')
            ->orderBy('s.ordre', 'ASC')
            ->addOrderBy('s.prixCentimes', 'ASC')
            ->getQuery()
            ->getResult();

        return array_values(array_filter($actifs, static fn (Supplement $s): bool => $s->estProposePour($prestation)));
    }

    /** Supplément choisi dans le tunnel (identifiant de l'URL), s'il est bien proposé pour cette prestation. */
    public function findProposePour(Prestation $prestation, string $identifiant): ?Supplement
    {
        if (!ctype_digit($identifiant)) {
            return null;
        }
        $supplement = $this->find((int) $identifiant);

        return $supplement instanceof Supplement && $supplement->estProposePour($prestation) ? $supplement : null;
    }
}
