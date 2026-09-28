<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Client;
use App\Enum\StatutReservation;
use App\Repository\ClientRepository;
use App\Repository\ReservationRepository;
use App\Repository\UserRepository;
use App\Service\Compte\NotificationsCompte;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Durées de conservation (RGPD), comme annoncé dans la politique de confidentialité :
 * - fiche cliente sans activité depuis 3 ans (rendez-vous, réservation, connexion) : anonymisée,
 *   compte supprimé ; les réservations restent pour la comptabilité, sans identité ;
 * - compte en ligne : email d'avertissement 30 jours avant ;
 * - compte jamais confirmé : supprimé après 30 jours.
 * Idempotente ; jamais de suppression si un rendez-vous est encore à venir.
 */
#[AsCommand(name: 'app:rgpd:purger', description: 'Anonymise les fiches et supprime les comptes inactifs (durées de conservation RGPD)')]
final class PurgerDonneesCommand extends Command
{
    public const INACTIVITE = '-3 years';
    public const PREAVIS_JOURS = 30;
    public const COMPTE_NON_CONFIRME = '-30 days';

    public function __construct(
        private readonly ClientRepository $clientes,
        private readonly UserRepository $users,
        private readonly ReservationRepository $reservations,
        private readonly NotificationsCompte $notifications,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $maintenant = $this->clock->now();
        $limite = $maintenant->modify(self::INACTIVITE);
        $limiteAvertissement = $limite->modify(\sprintf('+%d days', self::PREAVIS_JOURS));
        $dernieresReservations = $this->reservations->dernieresDemandesParClient();
        $compteurs = ['anonymisees' => 0, 'avertissements' => 0, 'comptes_non_confirmes' => 0, 'comptes_sans_fiche' => 0];

        // 1. Fiches (et leur compte) sans activité depuis 3 ans
        foreach ($this->clientes->findBy(['anonymiseAt' => null]) as $client) {
            $user = $client->getUser();
            $activite = max(array_filter([
                $client->getCreatedAt(),
                $client->getDerniereVisiteAt(),
                $dernieresReservations[(int) $client->getId()] ?? null,
                $user?->getDerniereConnexionAt(),
            ]));

            if ($activite < $limite && !$this->aUnRendezVousAVenir($client)) {
                $client->anonymiser($maintenant);
                if (null !== $user && !$user->isAdmin()) {
                    $this->entityManager->remove($user);
                }
                $this->entityManager->flush();
                ++$compteurs['anonymisees'];
            } elseif ($activite < $limiteAvertissement && null !== $user && !$user->isAdmin()
                && (null === $user->getAvertissementSuppressionAt() || $user->getAvertissementSuppressionAt() < $activite)) {
                $this->notifications->suppressionProchaine($user, $activite->modify('+3 years'));
                $user->marquerAvertissementSuppression($maintenant);
                $this->entityManager->flush();
                ++$compteurs['avertissements'];
            }
        }

        // 2. Comptes clientes jamais confirmés, ou sans fiche et inactifs depuis 3 ans
        foreach ($this->users->findAll() as $user) {
            if ($user->isAdmin() || null !== $this->clientes->findOneBy(['user' => $user])) {
                continue;
            }
            if (!$user->isEmailVerifie() && $user->getCreatedAt() < $maintenant->modify(self::COMPTE_NON_CONFIRME)) {
                $this->entityManager->remove($user);
                ++$compteurs['comptes_non_confirmes'];
            } elseif (max($user->getCreatedAt(), $user->getDerniereConnexionAt() ?? $user->getCreatedAt()) < $limite) {
                $this->entityManager->remove($user);
                ++$compteurs['comptes_sans_fiche'];
            }
        }
        $this->entityManager->flush();

        // Aucune donnée personnelle dans les journaux : uniquement des nombres.
        $this->logger->notice('Purge RGPD terminée.', $compteurs);
        (new SymfonyStyle($input, $output))->success(\sprintf(
            '%d fiche(s) anonymisée(s), %d avertissement(s), %d compte(s) non confirmé(s) et %d compte(s) sans fiche supprimé(s).',
            ...array_values($compteurs),
        ));

        return Command::SUCCESS;
    }

    private function aUnRendezVousAVenir(Client $client): bool
    {
        foreach ($this->reservations->findPourClient($client, 5) as $reservation) {
            if ($reservation->getFin() > $this->clock->now() && \in_array($reservation->getStatut(), StatutReservation::bloquantLeCreneau(), true)) {
                return true;
            }
        }

        return false;
    }
}
