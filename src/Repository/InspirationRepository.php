<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Inspiration;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Inspiration>
 */
class InspirationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Inspiration::class);
    }

    /**
     * Thèmes publiés qui contiennent au moins une photo publiée.
     *
     * @return list<Inspiration>
     */
    public function findPublieesAvecPhotos(): array
    {
        /** @var list<Inspiration> */
        return $this->createQueryBuilder('i')
            ->innerJoin('i.photos', 'p', 'WITH', 'p.publiee = true')
            ->andWhere('i.publiee = true')
            ->groupBy('i.id')
            ->orderBy('i.ordre', 'ASC')
            ->addOrderBy('i.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findPublieeParSlug(string $slug): ?Inspiration
    {
        return $this->findOneBy(['slug' => $slug, 'publiee' => true]);
    }
}
