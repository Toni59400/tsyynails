<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Client;
use App\Entity\MouvementPoints;
use App\Enum\MotifMouvementPoints;
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

    /**
     * Date du dernier gain de points (visite, bienvenue, correction positive) :
     * point de départ de l'expiration.
     */
    public function dernierGainPour(Client $client): ?\DateTimeImmutable
    {
        return $this->derniersGains($client)[$client->getId()] ?? null;
    }

    /**
     * Dernier gain de points par cliente (toutes, ou une seule).
     *
     * @return array<int, \DateTimeImmutable>
     */
    public function derniersGains(?Client $client = null): array
    {
        $requete = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.client) AS client', 'MAX(m.createdAt) AS dernier')
            ->andWhere('m.delta > 0')
            ->andWhere('m.motif IN (:gains)')
            ->setParameter('gains', [MotifMouvementPoints::VISITE, MotifMouvementPoints::INSCRIPTION, MotifMouvementPoints::CORRECTION])
            ->groupBy('m.client');
        if (null !== $client) {
            $requete->andWhere('m.client = :client')->setParameter('client', $client);
        }

        /** @var list<array{client: int|string, dernier: string}> $lignes */
        $lignes = $requete->getQuery()->getArrayResult();
        $gains = [];
        foreach ($lignes as $ligne) {
            $gains[(int) $ligne['client']] = new \DateTimeImmutable($ligne['dernier']);
        }

        return $gains;
    }

    public function existePour(Client $client, MotifMouvementPoints $motif): bool
    {
        return null !== $this->findOneBy(['client' => $client, 'motif' => $motif]);
    }
}
