<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\QuestionFrequente;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuestionFrequente>
 */
class QuestionFrequenteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuestionFrequente::class);
    }

    /**
     * @return list<QuestionFrequente>
     */
    public function findPubliees(): array
    {
        /** @var list<QuestionFrequente> */
        return $this->createQueryBuilder('q')
            ->andWhere('q.publiee = true')
            ->orderBy('q.ordre', 'ASC')
            ->addOrderBy('q.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
