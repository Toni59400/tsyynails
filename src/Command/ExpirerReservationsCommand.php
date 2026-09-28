<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\StatutReservation;
use App\Repository\ReservationRepository;
use App\Service\Reservation\ReservationWorkflow;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Libère les créneaux bloqués sans suite. À lancer chaque heure par la tâche planifiée OVH :
 *   php bin/console app:reservations:expirer --env=prod
 * Idempotente : relancée, elle ne traite que ce qui reste à expirer.
 */
#[AsCommand(name: 'app:reservations:expirer', description: 'Expire les paiements abandonnés et les demandes restées sans réponse')]
final class ExpirerReservationsCommand extends Command
{
    /** Paiement abandonné : la cliente a fermé la page sans saisir sa carte. */
    public const DELAI_PAIEMENT_MINUTES = 30;

    /** Stripe annule l'empreinte au bout de 7 jours : on expire avant, avec une marge pour la tâche horaire. */
    public const DELAI_DECISION_HEURES = 6 * 24 + 12;

    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly ReservationWorkflow $workflow,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $maintenant = $this->clock->now();
        $aExpirer = [
            ...$this->reservations->findParStatutCreeesAvant(StatutReservation::PAIEMENT_EN_COURS, $maintenant->modify(\sprintf('-%d minutes', self::DELAI_PAIEMENT_MINUTES))),
            ...$this->reservations->findParStatutCreeesAvant(StatutReservation::EN_ATTENTE, $maintenant->modify(\sprintf('-%d hours', self::DELAI_DECISION_HEURES))),
        ];

        $erreurs = 0;
        foreach ($aExpirer as $reservation) {
            try {
                $this->workflow->expirer($reservation);
            } catch (\Throwable $erreur) {
                ++$erreurs;
                $this->logger->error('Expiration impossible.', ['reservation' => $reservation->getId(), 'erreur' => $erreur->getMessage()]);
            }
        }

        $this->logger->info('Expiration des réservations terminée.', ['expirees' => \count($aExpirer) - $erreurs, 'erreurs' => $erreurs]);
        (new SymfonyStyle($input, $output))->success(\sprintf('%d réservation(s) expirée(s), %d erreur(s).', \count($aExpirer) - $erreurs, $erreurs));

        return 0 === $erreurs ? Command::SUCCESS : Command::FAILURE;
    }
}
