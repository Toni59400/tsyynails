<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CarteFidelite;
use App\Entity\Client;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CarteFidelite>
 */
class CarteFideliteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CarteFidelite::class);
    }

    public function findActiveParJeton(string $jeton): ?CarteFidelite
    {
        return $this->findOneBy(['jeton' => $jeton, 'active' => true]);
    }

    public function findActivePour(Client $client): ?CarteFidelite
    {
        return $this->findOneBy(['client' => $client, 'active' => true]);
    }
}
