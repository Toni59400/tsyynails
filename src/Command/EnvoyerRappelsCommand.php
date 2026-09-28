<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ReservationRepository;
use App\Service\Reservation\NotificationsReservation;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Email de rappel pour les rendez-vous confirmés des prochaines 24 heures, une seule fois.
 * Lancée chaque heure : chaque cliente reçoit son rappel environ un jour avant.
 */
#[AsCommand(name: 'app:rappels:envoyer', description: 'Envoie le rappel de la veille des rendez-vous confirmés')]
final class EnvoyerRappelsCommand extends Command
{
    public const HEURES_AVANT = 24;

    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly NotificationsReservation $notifications,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $maintenant = $this->clock->now();
        $envoyes = 0;

        foreach ($this->reservations->findRappelsAEnvoyer($maintenant, $maintenant->modify(\sprintf('+%d hours', self::HEURES_AVANT))) as $reservation) {
            // Marqué avant l'envoi : une erreur d'email ne provoquera jamais de doublon.
            $reservation->marquerRappelEnvoye($maintenant);
            $this->entityManager->flush();
            $this->notifications->rappel($reservation);
            ++$envoyes;
        }

        $this->logger->info('Rappels envoyés.', ['nombre' => $envoyes]);
        (new SymfonyStyle($input, $output))->success(\sprintf('%d rappel(s) envoyé(s).', $envoyes));

        return Command::SUCCESS;
    }
}
