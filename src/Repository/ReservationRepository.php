<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Reservation;
use App\Enum\StatutReservation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Reservation>
 */
class ReservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reservation::class);
    }

    /**
     * Réservations qui occupent l'agenda sur la période [debut, fin[.
     *
     * @return list<Reservation>
     */
    public function findBloquantesEntre(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        /** @var list<Reservation> */
        return $this->createQueryBuilder('r')
            ->andWhere('r.debut < :fin')
            ->andWhere('r.fin > :debut')
            ->andWhere('r.statut IN (:statuts)')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->setParameter('statuts', StatutReservation::bloquantLeCreneau())
            ->orderBy('r.debut', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Demandes toujours en attente créées avant la date donnée (empreinte Stripe bientôt expirée).
     *
     * @return list<Reservation>
     */
    public function findEnAttenteCreeesAvant(\DateTimeImmutable $limite): array
    {
        /** @var list<Reservation> */
        return $this->createQueryBuilder('r')
            ->andWhere('r.statut = :statut')
            ->andWhere('r.createdAt < :limite')
            ->setParameter('statut', StatutReservation::EN_ATTENTE)
            ->setParameter('limite', $limite)
            ->getQuery()
            ->getResult();
    }
}
