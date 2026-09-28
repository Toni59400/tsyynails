<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Prestation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Prestation>
 */
class PrestationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Prestation::class);
    }

    /**
     * @return list<Prestation>
     */
    public function findActives(?int $limite = null): array
    {
        /** @var list<Prestation> */
        return $this->createQueryBuilder('p')
            ->andWhere('p.active = true')
            ->orderBy('p.ordre', 'ASC')
            ->addOrderBy('p.nom', 'ASC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }
}
