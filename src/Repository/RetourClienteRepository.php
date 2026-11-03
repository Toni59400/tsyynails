<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RetourCliente;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RetourCliente>
 */
class RetourClienteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RetourCliente::class);
    }

    /** Durée de conservation : réponses reçues avant cette date supprimées. Retourne le nombre supprimé. */
    public function supprimerAvant(\DateTimeImmutable $date): int
    {
        return (int) $this->createQueryBuilder('r')
            ->delete()
            ->andWhere('r.createdAt < :date')
            ->setParameter('date', $date)
            ->getQuery()
            ->execute();
    }

    /**
     * Note moyenne et nombre de réponses (tableau de bord).
     *
     * @return array{moyenne: ?float, nombre: int}
     */
    public function synthese(): array
    {
        /** @var array{moyenne: string|float|null, nombre: string|int} $ligne */
        $ligne = $this->createQueryBuilder('r')
            ->select('AVG(r.note) AS moyenne', 'COUNT(r.id) AS nombre')
            ->getQuery()
            ->getSingleResult();

        return [
            'moyenne' => null === $ligne['moyenne'] ? null : round((float) $ligne['moyenne'], 1),
            'nombre' => (int) $ligne['nombre'],
        ];
    }
}
