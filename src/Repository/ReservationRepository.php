<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Client;
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
     * Rendez-vous à afficher dans l'agenda de l'admin sur la période [debut, fin[,
     * avec cliente et prestation chargées en une seule requête.
     *
     * @param list<StatutReservation> $statuts
     *
     * @return list<Reservation>
     */
    public function findPourAgenda(\DateTimeImmutable $debut, \DateTimeImmutable $fin, array $statuts): array
    {
        /** @var list<Reservation> */
        return $this->createQueryBuilder('r')
            ->addSelect('c', 'p')
            ->innerJoin('r.client', 'c')
            ->innerJoin('r.prestation', 'p')
            ->andWhere('r.debut < :fin')
            ->andWhere('r.fin > :debut')
            ->andWhere('r.statut IN (:statuts)')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->setParameter('statuts', $statuts)
            ->orderBy('r.debut', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Chiffres d'une période [debut, fin[ (date du rendez-vous) :
     * CA réalisé = prestations honorées au prix payé (prix − réduction fidélité),
     * CA prévu = rendez-vous confirmés pas encore passés.
     *
     * @return array{ca_realise: int, honorees: int, non_honorees: int, ca_prevu: int, confirmees: int}
     */
    public function statistiquesEntre(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        /** @var array<string, string|int|null> $ligne */
        $ligne = $this->createQueryBuilder('r')
            ->select(
                'SUM(CASE WHEN r.statut = :honoree THEN r.prixCentimes - r.reductionCentimes ELSE 0 END) AS ca_realise',
                'SUM(CASE WHEN r.statut = :honoree THEN 1 ELSE 0 END) AS honorees',
                'SUM(CASE WHEN r.statut = :non_honoree THEN 1 ELSE 0 END) AS non_honorees',
                'SUM(CASE WHEN r.statut = :confirmee THEN r.prixCentimes - r.reductionCentimes ELSE 0 END) AS ca_prevu',
                'SUM(CASE WHEN r.statut = :confirmee THEN 1 ELSE 0 END) AS confirmees',
            )
            ->andWhere('r.debut >= :debut')
            ->andWhere('r.debut < :fin')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->setParameter('honoree', StatutReservation::HONOREE)
            ->setParameter('non_honoree', StatutReservation::NON_HONOREE)
            ->setParameter('confirmee', StatutReservation::CONFIRMEE)
            ->getQuery()
            ->getSingleResult();

        return [
            'ca_realise' => (int) $ligne['ca_realise'],
            'honorees' => (int) $ligne['honorees'],
            'non_honorees' => (int) $ligne['non_honorees'],
            'ca_prevu' => (int) $ligne['ca_prevu'],
            'confirmees' => (int) $ligne['confirmees'],
        ];
    }

    /**
     * Prestations les plus réalisées (honorées) sur la période.
     *
     * @return list<array{nom: string, nombre: int, ca: int}>
     */
    public function meilleuresPrestationsEntre(\DateTimeImmutable $debut, \DateTimeImmutable $fin, int $limite = 5): array
    {
        /** @var list<array{nom: string, nombre: string|int, ca: string|int}> $lignes */
        $lignes = $this->createQueryBuilder('r')
            ->select('p.nom AS nom', 'COUNT(r.id) AS nombre', 'SUM(r.prixCentimes - r.reductionCentimes) AS ca')
            ->innerJoin('r.prestation', 'p')
            ->andWhere('r.statut = :honoree')
            ->andWhere('r.debut >= :debut')
            ->andWhere('r.debut < :fin')
            ->setParameter('honoree', StatutReservation::HONOREE)
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->groupBy('p.id')
            ->orderBy('ca', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $l): array => ['nom' => $l['nom'], 'nombre' => (int) $l['nombre'], 'ca' => (int) $l['ca']], $lignes);
    }

    /**
     * Prochains rendez-vous (confirmés ou en attente) à partir de maintenant.
     *
     * @return list<Reservation>
     */
    public function findProchains(\DateTimeImmutable $apres, int $limite): array
    {
        /** @var list<Reservation> */
        return $this->createQueryBuilder('r')
            ->addSelect('c', 'p')
            ->innerJoin('r.client', 'c')
            ->innerJoin('r.prestation', 'p')
            ->andWhere('r.fin > :apres')
            ->andWhere('r.statut IN (:statuts)')
            ->setParameter('apres', $apres)
            ->setParameter('statuts', StatutReservation::bloquantLeCreneau())
            ->orderBy('r.debut', 'ASC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /**
     * Toutes les demandes en attente, les plus anciennes d'abord (l'empreinte Stripe expire après 7 jours).
     *
     * @return list<Reservation>
     */
    public function findEnAttente(): array
    {
        /** @var list<Reservation> */
        return $this->createQueryBuilder('r')
            ->addSelect('c', 'p')
            ->innerJoin('r.client', 'c')
            ->innerJoin('r.prestation', 'p')
            ->andWhere('r.statut = :statut')
            ->setParameter('statut', StatutReservation::EN_ATTENTE)
            ->orderBy('r.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Historique d'une cliente, du plus récent au plus ancien.
     *
     * @return list<Reservation>
     */
    public function findPourClient(Client $client, int $limite = 20): array
    {
        /** @var list<Reservation> */
        return $this->createQueryBuilder('r')
            ->addSelect('p')
            ->innerJoin('r.prestation', 'p')
            ->andWhere('r.client = :client')
            ->setParameter('client', $client)
            ->orderBy('r.debut', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /**
     * Réservations d'un statut créées avant la date donnée (paiements abandonnés, demandes sans réponse).
     *
     * @return list<Reservation>
     */
    public function findParStatutCreeesAvant(StatutReservation $statut, \DateTimeImmutable $limite): array
    {
        /** @var list<Reservation> */
        return $this->createQueryBuilder('r')
            ->andWhere('r.statut = :statut')
            ->andWhere('r.createdAt < :limite')
            ->setParameter('statut', $statut)
            ->setParameter('limite', $limite)
            ->getQuery()
            ->getResult();
    }
}
