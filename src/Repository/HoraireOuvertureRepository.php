<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\HoraireOuverture;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HoraireOuverture>
 */
class HoraireOuvertureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HoraireOuverture::class);
    }

    /**
     * @return list<HoraireOuverture>
     */
    public function findToutesOrdonnees(): array
    {
        /** @var list<HoraireOuverture> */
        return $this->createQueryBuilder('h')
            ->orderBy('h.jourSemaine', 'ASC')
            ->addOrderBy('h.heureDebut', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
