<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Inspiration;
use App\Entity\Photo;
use App\Entity\Prestation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Photo>
 */
class PhotoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Photo::class);
    }

    /**
     * Photos publiées, éventuellement filtrées par thème d'inspiration ou par prestation.
     *
     * @return list<Photo>
     */
    public function findPubliees(?int $limite = null, ?Inspiration $theme = null, ?Prestation $prestation = null): array
    {
        $requete = $this->createQueryBuilder('p')
            ->andWhere('p.publiee = true')
            ->orderBy('p.ordre', 'ASC')
            ->addOrderBy('p.createdAt', 'DESC')
            ->setMaxResults($limite);

        if (null !== $theme) {
            $requete->andWhere(':theme MEMBER OF p.inspirations')->setParameter('theme', $theme);
        }
        if (null !== $prestation) {
            $requete->andWhere('p.prestation = :prestation')->setParameter('prestation', $prestation);
        }

        /** @var list<Photo> */
        return $requete->getQuery()->getResult();
    }

    /**
     * Photos publiées rangées par prestation (page des tarifs).
     *
     * @return array<int, list<Photo>> photos par identifiant de prestation
     */
    public function findPublieesParPrestation(): array
    {
        /** @var list<Photo> $photos */
        $photos = $this->createQueryBuilder('p')
            ->addSelect('pr')
            ->innerJoin('p.prestation', 'pr')
            ->andWhere('p.publiee = true')
            ->orderBy('p.ordre', 'ASC')
            ->addOrderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $parPrestation = [];
        foreach ($photos as $photo) {
            $parPrestation[(int) $photo->getPrestation()?->getId()][] = $photo;
        }

        return $parPrestation;
    }
}
