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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Le lendemain d'un rendez-vous honoré, invite la cliente à laisser un avis Google (lien AVIS_GOOGLE_URL).
 * Au plus une demande par cliente et par an, jamais après un refus, envoyée en journée. Idempotente.
 */
#[AsCommand(name: 'app:avis:demander', description: 'Invite les clientes venues la veille à laisser un avis Google')]
final class DemanderAvisCommand extends Command
{
    public const HEURES_APRES = 18;
    public const JOURS_MAX = 7;
    public const MOIS_ENTRE_DEUX_DEMANDES = 12;
    /** Heures d'envoi (heure de Paris) : pas d'email la nuit. */
    public const HEURE_DEBUT = 10;
    public const HEURE_FIN = 20;

    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly NotificationsReservation $notifications,
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly UriSigner $signataire,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'AVIS_GOOGLE_URL')] private readonly string $lienAvis,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('toute-heure', null, InputOption::VALUE_NONE, 'Envoie même en dehors de la journée (tests, envoi manuel)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!str_starts_with($this->lienAvis, 'https://')) {
            $io->note('Lien d\'avis Google non configuré (AVIS_GOOGLE_URL) : aucune demande envoyée.');

            return Command::SUCCESS;
        }

        $maintenant = $this->clock->now();
        $heure = (int) $maintenant->setTimezone(new \DateTimeZone('Europe/Paris'))->format('G');
        if (!$input->getOption('toute-heure') && ($heure < self::HEURE_DEBUT || $heure >= self::HEURE_FIN)) {
            $io->note('En dehors des heures d\'envoi : les demandes partiront dans la journée.');

            return Command::SUCCESS;
        }

        $envoyees = 0;
        $clientes = [];
        $candidates = $this->reservations->findDemandesAvisAEnvoyer(
            $maintenant->modify(\sprintf('-%d days', self::JOURS_MAX)),
            $maintenant->modify(\sprintf('-%d hours', self::HEURES_APRES)),
            $maintenant->modify(\sprintf('-%d months', self::MOIS_ENTRE_DEUX_DEMANDES)),
        );
        foreach ($candidates as $reservation) {
            $client = $reservation->getClient();
            if (isset($clientes[$client->getId()]) || null === $reservation->getEmailNotification()) {
                continue;
            }
            $clientes[$client->getId()] = true;

            // Marqué avant l'envoi : une erreur d'email ne provoquera jamais de doublon.
            $client->marquerDemandeAvis($maintenant);
            $this->entityManager->flush();

            $lienRefus = $this->signataire->sign($this->urlGenerator->generate('app_avis_refus', ['client' => $client->getId()], UrlGeneratorInterface::ABSOLUTE_URL));
            $this->notifications->demandeAvis($reservation, $this->lienAvis, $lienRefus);
            ++$envoyees;
        }

        $this->logger->info('Demandes d\'avis envoyées.', ['nombre' => $envoyees]);
        $io->success(\sprintf('%d demande(s) d\'avis envoyée(s).', $envoyees));

        return Command::SUCCESS;
    }
}
