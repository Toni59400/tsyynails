<?php

declare(strict_types=1);

namespace App\Service\Compte;

use App\Entity\CarteFidelite;
use App\Entity\Client;
use App\Entity\MouvementPoints;
use App\Enum\StatutReservation;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Suppression d'une fiche cliente depuis l'admin (demande de la cliente, doublon, saisie erronée) :
 * - sans aucun rendez-vous : fiche, points et compte en ligne supprimés définitivement ;
 * - avec un historique : anonymisation (comme la purge RGPD), car les rendez-vous passés restent dans
 *   le chiffre d'affaires (obligation comptable), sans nom ni coordonnées. Le compte en ligne est supprimé.
 * Refusée tant qu'un rendez-vous est à venir : il faut d'abord l'annuler (la cliente est prévenue).
 */
final class SuppressionCliente
{
    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return bool vrai si la fiche est supprimée, faux si elle est anonymisée
     *
     * @throws \LogicException rendez-vous à venir, ou compte administrateur
     */
    public function supprimer(Client $client, string $auteur): bool
    {
        $maintenant = $this->clock->now();
        $reservations = $this->reservations->findPourClient($client, 1000);
        foreach ($reservations as $reservation) {
            if ($reservation->getFin() > $maintenant && \in_array($reservation->getStatut(), StatutReservation::bloquantLeCreneau(), true)) {
                throw new \LogicException('Cette cliente a un rendez-vous à venir : annulez-le d\'abord (elle sera prévenue).');
            }
        }
        $user = $client->getUser();
        if (null !== $user && $user->isAdmin()) {
            throw new \LogicException('Cette fiche est liée à un compte administrateur.');
        }

        // Cartes fidélité : détachées et désactivées (le jeton du QR code ne doit plus rien désigner).
        foreach ($this->entityManager->getRepository(CarteFidelite::class)->findBy(['client' => $client]) as $carte) {
            $carte->desactiver();
        }
        $this->entityManager->createQuery('UPDATE App\Entity\CarteFidelite c SET c.client = NULL WHERE c.client = :c')->setParameter('c', $client)->execute();

        $id = $client->getId();
        $definitive = [] === $reservations;
        if ($definitive) {
            $this->entityManager->createQuery('DELETE FROM '.MouvementPoints::class.' m WHERE m.client = :c')->setParameter('c', $client)->execute();
            $this->entityManager->remove($client);
        } else {
            $client->anonymiser($maintenant);
        }
        if (null !== $user) {
            $this->entityManager->remove($user);
        }
        $this->entityManager->flush();
        $this->logger->notice($definitive ? 'Fiche cliente supprimée.' : 'Fiche cliente anonymisée.', ['fiche' => $id, 'par' => $auteur]);

        return $definitive;
    }
}
