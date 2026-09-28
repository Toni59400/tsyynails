<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RecompenseFidelite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RecompenseFidelite>
 */
class RecompenseFideliteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecompenseFidelite::class);
    }

    /**
     * Paliers actifs, du moins cher au plus cher.
     *
     * @return list<RecompenseFidelite>
     */
    public function findActives(): array
    {
        /** @var list<RecompenseFidelite> */
        return $this->createQueryBuilder('r')
            ->andWhere('r.active = true')
            ->orderBy('r.seuilPoints', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
