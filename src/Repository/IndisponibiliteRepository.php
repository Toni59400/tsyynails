<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Indisponibilite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Indisponibilite>
 */
class IndisponibiliteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Indisponibilite::class);
    }

    /**
     * Indisponibilités qui chevauchent la période [debut, fin[.
     *
     * @return list<Indisponibilite>
     */
    public function findChevauchant(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        /** @var list<Indisponibilite> */
        return $this->createQueryBuilder('i')
            ->andWhere('i.debut < :fin')
            ->andWhere('i.fin > :debut')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->orderBy('i.debut', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
