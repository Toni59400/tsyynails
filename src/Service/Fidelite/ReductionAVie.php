<?php

declare(strict_types=1);

namespace App\Service\Fidelite;

use App\Entity\Client;
use App\Entity\Parametre;
use App\Repository\ClientRepository;
use App\Repository\MouvementPointsRepository;
use App\Repository\ParametreRepository;
use App\Repository\ReservationRepository;
use App\Service\Compte\NotificationsCompte;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Statut VIP (réglages « Statut VIP » de l'admin) — réduction à vie sur les prestations :
 *
 * - débloqué quand la cliente a gagné le seuil de points AU TOTAL depuis toujours (480), même si elle
 *   en a déjà utilisé : réduction de départ (5 %) ;
 * - à chaque anniversaire, avec au moins N rendez-vous honorés dans l'année (8) : +5 %, jusqu'au plafond (15 %) ;
 *   sinon la réduction reste au même niveau — le statut VIP n'est jamais retiré ;
 * - un email prévient 30 jours avant l'anniversaire s'il manque des rendez-vous pour monter de niveau.
 *
 * S'applique d'office aux réservations de la cliente connectée (Tarification::remise), sauf quand elle utilise
 * une récompense de points sur le rendez-vous (non cumulable).
 */
final class ReductionAVie
{
    public const AVERTISSEMENT_JOURS = 30;

    public function __construct(
        private readonly ParametreRepository $parametres,
        private readonly MouvementPointsRepository $mouvements,
        private readonly ReservationRepository $reservations,
        private readonly ClientRepository $clientes,
        private readonly NotificationsCompte $notifications,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function estActive(): bool
    {
        return $this->parametres->valeur(Parametre::PRIVILEGE_SEUIL_POINTS) > 0;
    }

    /** Points gagnés au total depuis toujours (progression vers le statut VIP). */
    public function pointsCumules(Client $client): int
    {
        return $this->mouvements->pointsCumulesParClient($client)[$client->getId()] ?? 0;
    }

    /**
     * Pour l'espace fidélité et la fiche admin : statut VIP en cours, ou progression vers le seuil.
     *
     * @return array{active: bool, pourcentage: int, seuil: int, points: int, progression: int, visites: int, visites_requises: int, revision: ?\DateTimeImmutable, depuis: ?\DateTimeImmutable, depart: int, pas: int, plafond: int}
     */
    public function etat(Client $client): array
    {
        $seuil = $this->parametres->valeur(Parametre::PRIVILEGE_SEUIL_POINTS);
        $points = $this->estActive() ? $this->pointsCumules($client) : 0;

        return [
            'active' => $this->estActive(),
            'pourcentage' => $client->getPrivilegePourcentage(),
            'seuil' => $seuil,
            'points' => $points,
            'progression' => $seuil > 0 ? min(100, (int) floor(100 * $points / $seuil)) : 0,
            'visites' => $this->visitesAnneeEnCours($client),
            'visites_requises' => $this->parametres->valeur(Parametre::PRIVILEGE_VISITES_ANNUELLES),
            'revision' => $client->getPrivilegeRevisionAt(),
            'depuis' => $client->getPrivilegeDepuis(),
            'depart' => $this->parametres->valeur(Parametre::PRIVILEGE_REDUCTION_DEPART),
            'pas' => $this->parametres->valeur(Parametre::PRIVILEGE_REDUCTION_PAS),
            'plafond' => $this->parametres->valeur(Parametre::PRIVILEGE_REDUCTION_MAX),
        ];
    }

    /** Rendez-vous honorés depuis le début de l'année VIP en cours. */
    public function visitesAnneeEnCours(Client $client): int
    {
        $revision = $client->getPrivilegeRevisionAt();

        return null === $revision ? 0 : $this->reservations->countHonoreesEntre($client, $revision->modify('-1 year'), $revision);
    }

    /**
     * Accorde le statut VIP si le seuil de points cumulés est atteint (après un rendez-vous honoré, et chaque heure
     * pour les autres gains : bienvenue, parrainage, correction). Retourne vrai si la cliente vient de l'obtenir.
     */
    public function verifierSeuil(Client $client): bool
    {
        if (!$this->estActive() || $client->getPrivilegePourcentage() > 0 || $client->estAnonymise()) {
            return false;
        }
        if ($this->pointsCumules($client) < $this->parametres->valeur(Parametre::PRIVILEGE_SEUIL_POINTS)) {
            return false;
        }

        $pourcentage = min($this->parametres->valeur(Parametre::PRIVILEGE_REDUCTION_DEPART), $this->parametres->valeur(Parametre::PRIVILEGE_REDUCTION_MAX));
        $client->accorderPrivilege($pourcentage, $this->clock->now());
        $this->entityManager->flush();
        $this->notifications->reductionAVie($client, 'accordee', $pourcentage);
        $this->logger->info('Statut VIP accordé.', ['client' => $client->getId(), 'pourcentage' => $pourcentage]);

        return true;
    }

    /**
     * Tâche planifiée : nouvelles VIP au seuil, rappels avant l'anniversaire, et bilans annuels échus.
     *
     * @return array{accordees: int, progressions: int, maintenues: int, averties: int}
     */
    public function traiter(): array
    {
        $stats = ['accordees' => 0, 'progressions' => 0, 'maintenues' => 0, 'averties' => 0];
        if (!$this->estActive()) {
            return $stats;
        }
        $maintenant = $this->clock->now();
        $seuil = $this->parametres->valeur(Parametre::PRIVILEGE_SEUIL_POINTS);

        // Seuil atteint par un gain hors rendez-vous (bienvenue, parrainage, correction) ou seuil abaissé dans les réglages.
        foreach ($this->mouvements->pointsCumulesParClient() as $idClient => $points) {
            $client = $points >= $seuil ? $this->clientes->find($idClient) : null;
            if ($client instanceof Client && $this->verifierSeuil($client)) {
                ++$stats['accordees'];
            }
        }

        foreach ($this->clientes->findPrivilegesARevoirAvant($maintenant->modify(\sprintf('+%d days', self::AVERTISSEMENT_JOURS))) as $client) {
            $revision = $client->getPrivilegeRevisionAt();
            if (null === $revision) {
                continue;
            }
            $resultat = $revision > $maintenant ? $this->avertir($client, $revision, $maintenant) : $this->faireLeBilan($client);
            if (null !== $resultat) {
                ++$stats[$resultat];
            }
        }

        return $stats;
    }

    /** Moins de 30 jours avant l'anniversaire : prévenir une fois s'il manque des rendez-vous pour monter de niveau. */
    private function avertir(Client $client, \DateTimeImmutable $revision, \DateTimeImmutable $maintenant): ?string
    {
        $pourcentage = $client->getPrivilegePourcentage();
        $manquantes = $this->parametres->valeur(Parametre::PRIVILEGE_VISITES_ANNUELLES) - $this->visitesAnneeEnCours($client);
        if (!$this->peutMonter($pourcentage) || $manquantes <= 0 || null !== $client->getPrivilegeAvertiAt()) {
            return null;
        }

        $client->marquerPrivilegeAverti($maintenant);
        $this->entityManager->flush();
        $this->notifications->reductionAVie($client, 'rappel', $this->niveauSuivant($pourcentage), $manquantes, $revision);

        return 'averties';
    }

    /** Bilan annuel : le statut est toujours gardé ; il monte d'un niveau si l'année est confirmée. */
    private function faireLeBilan(Client $client): string
    {
        $pourcentage = $client->getPrivilegePourcentage();
        $visites = $this->visitesAnneeEnCours($client);
        $monte = $this->peutMonter($pourcentage) && $visites >= $this->parametres->valeur(Parametre::PRIVILEGE_VISITES_ANNUELLES);
        if ($monte) {
            $pourcentage = $this->niveauSuivant($pourcentage);
        }
        $client->renouvelerPrivilege($pourcentage);
        $this->entityManager->flush();
        if ($monte) {
            $this->notifications->reductionAVie($client, 'renouvelee', $pourcentage);
        }
        $this->logger->info('Statut VIP : bilan annuel.', ['client' => $client->getId(), 'visites' => $visites, 'pourcentage' => $pourcentage]);

        return $monte ? 'progressions' : 'maintenues';
    }

    private function peutMonter(int $pourcentage): bool
    {
        return $pourcentage < $this->parametres->valeur(Parametre::PRIVILEGE_REDUCTION_MAX);
    }

    private function niveauSuivant(int $pourcentage): int
    {
        return min($pourcentage + $this->parametres->valeur(Parametre::PRIVILEGE_REDUCTION_PAS), $this->parametres->valeur(Parametre::PRIVILEGE_REDUCTION_MAX));
    }
}
