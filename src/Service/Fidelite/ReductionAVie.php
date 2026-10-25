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
 * Réduction à vie des clientes les plus fidèles (réglages « Réduction à vie » de l'admin) :
 *
 * - obtenue en gagnant le seuil de points (340) grâce aux rendez-vous des 12 derniers mois : 10 % ;
 * - à chaque anniversaire, au moins 8 rendez-vous honorés dans l'année : +5 % (plafond 20 %) ;
 *   sinon la réduction est perdue (elle peut être regagnée en atteignant à nouveau le seuil) ;
 * - un email prévient 30 jours avant l'anniversaire s'il manque des rendez-vous.
 *
 * S'applique d'office aux réservations de la cliente (Tarification::remise).
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

    /** Points gagnés en rendez-vous sur les 12 derniers mois (depuis la perte de la réduction, le cas échéant). */
    public function pointsSurUnAn(Client $client): int
    {
        return $this->mouvements->pointsVisitesParClientDepuis($this->debutComptage($client), $client)[$client->getId()] ?? 0;
    }

    private function debutComptage(Client $client): \DateTimeImmutable
    {
        return max($this->clock->now()->modify('-12 months'), $client->getPrivilegePerduAt() ?? new \DateTimeImmutable('@0'));
    }

    /**
     * Pour l'espace fidélité et la fiche admin : réduction en cours, ou progression vers le seuil.
     *
     * @return array{active: bool, pourcentage: int, seuil: int, points: int, progression: int, visites: int, visites_requises: int, revision: ?\DateTimeImmutable, depuis: ?\DateTimeImmutable, depart: int, pas: int, plafond: int}
     */
    public function etat(Client $client): array
    {
        $seuil = $this->parametres->valeur(Parametre::PRIVILEGE_SEUIL_POINTS);
        $points = $this->estActive() ? $this->pointsSurUnAn($client) : 0;

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

    /** Rendez-vous honorés depuis le début de l'année de fidélité en cours. */
    public function visitesAnneeEnCours(Client $client): int
    {
        $revision = $client->getPrivilegeRevisionAt();

        return null === $revision ? 0 : $this->reservations->countHonoreesEntre($client, $revision->modify('-1 year'), $revision);
    }

    /**
     * Après un rendez-vous honoré : accorde la réduction si le seuil vient d'être atteint.
     * Ne fait rien si la cliente l'a déjà. Retourne vrai si elle vient de l'obtenir.
     */
    public function verifierSeuil(Client $client): bool
    {
        if (!$this->estActive() || $client->getPrivilegePourcentage() > 0 || $client->estAnonymise()) {
            return false;
        }
        if ($this->pointsSurUnAn($client) < $this->parametres->valeur(Parametre::PRIVILEGE_SEUIL_POINTS)) {
            return false;
        }

        $pourcentage = min($this->parametres->valeur(Parametre::PRIVILEGE_REDUCTION_DEPART), $this->parametres->valeur(Parametre::PRIVILEGE_REDUCTION_MAX));
        $client->accorderPrivilege($pourcentage, $this->clock->now());
        $this->entityManager->flush();
        $this->notifications->reductionAVie($client, 'accordee', $pourcentage);
        $this->logger->info('Réduction à vie accordée.', ['client' => $client->getId(), 'pourcentage' => $pourcentage]);

        return true;
    }

    /**
     * Tâche planifiée : nouvelles clientes au seuil, avertissements, et révisions annuelles échues.
     *
     * @return array{accordees: int, renouvelees: int, perdues: int, averties: int}
     */
    public function traiter(): array
    {
        $stats = ['accordees' => 0, 'renouvelees' => 0, 'perdues' => 0, 'averties' => 0];
        if (!$this->estActive()) {
            return $stats;
        }
        $maintenant = $this->clock->now();
        $seuil = $this->parametres->valeur(Parametre::PRIVILEGE_SEUIL_POINTS);

        // Rattrapage (ex. : seuil abaissé dans les réglages) : le contrôle immédiat a lieu à chaque rendez-vous honoré.
        foreach ($this->mouvements->pointsVisitesParClientDepuis($maintenant->modify('-12 months')) as $idClient => $points) {
            $client = $points >= $seuil ? $this->clientes->find($idClient) : null;
            if ($client instanceof Client && $this->verifierSeuil($client)) {
                ++$stats['accordees'];
            }
        }

        $requises = $this->parametres->valeur(Parametre::PRIVILEGE_VISITES_ANNUELLES);
        foreach ($this->clientes->findPrivilegesARevoirAvant($maintenant->modify(\sprintf('+%d days', self::AVERTISSEMENT_JOURS))) as $client) {
            $revision = $client->getPrivilegeRevisionAt();
            if (null === $revision) {
                continue;
            }
            $visites = $this->visitesAnneeEnCours($client);

            if ($revision > $maintenant) {
                // Moins de 30 jours avant l'anniversaire : prévenir une fois s'il manque des rendez-vous.
                if ($visites < $requises && null === $client->getPrivilegeAvertiAt()) {
                    $client->marquerPrivilegeAverti($maintenant);
                    $this->entityManager->flush();
                    $this->notifications->reductionAVie($client, 'rappel', $client->getPrivilegePourcentage(), $requises - $visites, $revision);
                    ++$stats['averties'];
                }
                continue;
            }

            if ($visites >= $requises) {
                $pourcentage = min(
                    $client->getPrivilegePourcentage() + $this->parametres->valeur(Parametre::PRIVILEGE_REDUCTION_PAS),
                    $this->parametres->valeur(Parametre::PRIVILEGE_REDUCTION_MAX),
                );
                $client->renouvelerPrivilege($pourcentage);
                $this->entityManager->flush();
                $this->notifications->reductionAVie($client, 'renouvelee', $pourcentage);
                ++$stats['renouvelees'];
            } else {
                $client->retirerPrivilege($maintenant);
                $this->entityManager->flush();
                $this->notifications->reductionAVie($client, 'perdue', 0);
                ++$stats['perdues'];
            }
            $this->logger->info('Réduction à vie révisée.', ['client' => $client->getId(), 'visites' => $visites, 'pourcentage' => $client->getPrivilegePourcentage()]);
        }

        return $stats;
    }
}
