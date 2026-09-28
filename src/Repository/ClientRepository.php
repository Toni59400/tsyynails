<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Client;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Client>
 */
class ClientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Client::class);
    }

    /**
     * Filleules d'une marraine, les plus récentes d'abord.
     *
     * @return list<Client>
     */
    public function findFilleules(Client $marraine): array
    {
        /** @var list<Client> */
        return $this->createQueryBuilder('c')
            ->andWhere('c.marraine = :marraine')
            ->setParameter('marraine', $marraine)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** Filleules récompensées depuis une date (plafond annuel par marraine). */
    public function countParrainagesRecompensesDepuis(Client $marraine, \DateTimeImmutable $depuis): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.marraine = :marraine')
            ->andWhere('c.parrainageRecompenseAt >= :depuis')
            ->setParameter('marraine', $marraine)
            ->setParameter('depuis', $depuis)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countCreeesEntre(\DateTimeImmutable $debut, \DateTimeImmutable $fin): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.createdAt >= :debut')
            ->andWhere('c.createdAt < :fin')
            ->andWhere('c.anonymiseAt IS NULL')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
