<?php

declare(strict_types=1);

namespace App\Service\Fidelite;

use App\Entity\MouvementPoints;
use App\Entity\Parametre;
use App\Entity\RetourCliente;
use App\Enum\MotifMouvementPoints;
use App\Repository\ClientRepository;
use App\Repository\MouvementPointsRepository;
use App\Repository\ParametreRepository;
use App\Service\Compte\NotificationsCompte;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Questionnaire de satisfaction (affiche QR code du salon). Les points récompensent la réponse au questionnaire,
 * jamais un avis Google : Google interdit toute contrepartie à un avis.
 *
 * Points versés une seule fois par cliente, et seulement à une cliente déjà venue (rendez-vous honoré) : un email
 * inconnu ne crédite rien. La page affiche le même message dans tous les cas (on ne révèle pas qui est cliente) ;
 * la cliente reçoit un email quand ses points sont ajoutés.
 */
final class QuestionnaireSatisfaction
{
    public function __construct(
        private readonly ClientRepository $clientes,
        private readonly MouvementPointsRepository $mouvements,
        private readonly ParametreRepository $parametres,
        private readonly NotificationsCompte $notifications,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function points(): int
    {
        return $this->parametres->valeur(Parametre::QUESTIONNAIRE_POINTS);
    }

    /** Enregistre la réponse ; retourne les points crédités (0 si email inconnu, déjà récompensée ou réglage à 0). */
    public function repondre(string $email, int $note, string $recommande, ?string $commentaire): int
    {
        $client = $this->clientes->findVenueParEmail($email);
        $points = $this->points();
        if (null === $client || $points <= 0 || $this->mouvements->existePour($client, MotifMouvementPoints::QUESTIONNAIRE)) {
            $points = 0;
        }

        $this->entityManager->persist(new RetourCliente($client, $note, $recommande, $commentaire, $points, $this->clock->now()));
        if (null !== $client && $points > 0) {
            $this->entityManager->persist(new MouvementPoints($client, $points, MotifMouvementPoints::QUESTIONNAIRE, null, null, 'Questionnaire de satisfaction'));
        }
        $this->entityManager->flush();

        if (null !== $client && $points > 0) {
            $this->notifications->pointsQuestionnaire($client, $points);
            $this->logger->info('Questionnaire de satisfaction : points crédités.', ['client' => $client->getId(), 'points' => $points]);
        }

        return $points;
    }
}
