<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Client;
use App\Enum\StatutReservation;
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
     * Cliente déjà venue au salon (au moins un rendez-vous honoré) dont la fiche ou le compte porte cet email.
     */
    public function findVenueParEmail(string $email): ?Client
    {
        /** @var list<Client> $clientes */
        $clientes = $this->createQueryBuilder('c')
            ->leftJoin('c.user', 'u')
            ->andWhere('c.email = :email OR u.email = :email')
            ->andWhere('c.anonymiseAt IS NULL')
            ->andWhere('EXISTS (SELECT 1 FROM App\Entity\Reservation r WHERE r.client = c AND r.statut = :honoree)')
            ->setParameter('email', mb_strtolower(trim($email)))
            ->setParameter('honoree', StatutReservation::HONOREE)
            ->orderBy('c.derniereVisiteAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        return $clientes[0] ?? null;
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

    /**
     * Clientes VIP dont l'anniversaire de révision tombe avant cette date.
     *
     * @return list<Client>
     */
    public function findPrivilegesARevoirAvant(\DateTimeImmutable $avant): array
    {
        /** @var list<Client> */
        return $this->createQueryBuilder('c')
            ->andWhere('c.privilegePourcentage > 0')
            ->andWhere('c.privilegeRevisionAt <= :avant')
            ->andWhere('c.anonymiseAt IS NULL')
            ->setParameter('avant', $avant)
            ->getQuery()
            ->getResult();
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
