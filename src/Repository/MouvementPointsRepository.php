<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Client;
use App\Entity\MouvementPoints;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MouvementPoints>
 */
class MouvementPointsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MouvementPoints::class);
    }

    public function soldePour(Client $client): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COALESCE(SUM(m.delta), 0)')
            ->andWhere('m.client = :client')
            ->setParameter('client', $client)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<MouvementPoints>
     */
    public function historiquePour(Client $client): array
    {
        /** @var list<MouvementPoints> */
        return $this->createQueryBuilder('m')
            ->andWhere('m.client = :client')
            ->setParameter('client', $client)
            ->orderBy('m.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Soldes de toutes les clientes en une requête (liste de l'admin).
     *
     * @return array<int, int> solde par identifiant de cliente
     */
    public function soldesParClient(): array
    {
        /** @var list<array{client: int|string, solde: int|string}> $lignes */
        $lignes = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.client) AS client', 'SUM(m.delta) AS solde')
            ->groupBy('m.client')
            ->getQuery()
            ->getArrayResult();

        $soldes = [];
        foreach ($lignes as $ligne) {
            $soldes[(int) $ligne['client']] = (int) $ligne['solde'];
        }

        return $soldes;
    }
}
