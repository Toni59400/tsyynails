<?php

declare(strict_types=1);

namespace App\Service\Reservation;

use App\Entity\Client;
use App\Entity\MouvementPoints;
use App\Entity\Parametre;
use App\Entity\Prestation;
use App\Entity\RecompenseFidelite;
use App\Entity\Reservation;
use App\Enum\MotifMouvementPoints;
use App\Enum\StatutReservation;
use App\Repository\ClientRepository;
use App\Repository\ParametreRepository;
use App\Service\Fidelite\Parrainage;
use App\Service\Fidelite\ProgrammeFidelite;
use App\Service\Paiement\PaiementGateway;
use App\Service\Planning\CalculateurCreneaux;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Seul point d'entrée pour créer une réservation et changer son statut :
 * il garde la cohérence entre l'agenda, l'empreinte Stripe, les points et les emails.
 */
final class ReservationWorkflow
{
    /** Verrou MySQL nommé : deux demandes simultanées ne peuvent pas prendre le même créneau. */
    private const VERROU = 'tsyynails_reservation';
    private const ATTENTE_VERROU_SECONDES = 10;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClientRepository $clientes,
        private readonly CalculateurCreneaux $calculateur,
        private readonly Tarification $tarification,
        private readonly PaiementGateway $paiement,
        private readonly NotificationsReservation $notifications,
        private readonly ProgrammeFidelite $fidelite,
        private readonly ParametreRepository $parametres,
        private readonly Parrainage $parrainage,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Crée la demande (statut « paiement en cours », créneau bloqué) et son empreinte bancaire.
     *
     * @param Client|null             $clienteConnectee fiche de la cliente connectée (sinon retrouvée ou créée depuis les coordonnées)
     * @param RecompenseFidelite|null $recompense       réduction fidélité choisie : réservée pour une cliente connectée uniquement
     *
     * @throws CreneauIndisponibleException
     * @throws \LogicException              récompense non utilisable sur ce rendez-vous
     */
    public function demander(
        Prestation $prestation,
        \DateTimeImmutable $debut,
        CoordonneesCliente $coordonnees,
        ?Client $clienteConnectee = null,
        ?RecompenseFidelite $recompense = null,
    ): Reservation {
        if (null !== $recompense && null === $clienteConnectee) {
            throw new \LogicException('Les points ne s\'utilisent qu\'avec un compte.');
        }
        $telephone = null === $clienteConnectee
            ? (CoordonneesCliente::normaliserTelephone($coordonnees->telephone) ?? throw new \InvalidArgumentException('Téléphone invalide.'))
            : null;
        $connexion = $this->entityManager->getConnection();

        if (1 !== (int) $connexion->fetchOne('SELECT GET_LOCK(?, ?)', [self::VERROU, self::ATTENTE_VERROU_SECONDES])) {
            throw new CreneauIndisponibleException();
        }

        try {
            // Revérifié sous verrou : le créneau a pu être pris depuis l'affichage.
            if (!$prestation->isActive() || !$this->calculateur->estDisponible($prestation, $debut)) {
                throw new CreneauIndisponibleException();
            }

            $cliente = $clienteConnectee ?? $this->cliente($coordonnees, (string) $telephone);

            // Revérifiée sous verrou : le solde a pu changer depuis l'affichage.
            $reduction = 0;
            if (null !== $recompense) {
                if (!\in_array($recompense, $this->fidelite->utilisablesEnLigne($cliente, $prestation), true)) {
                    throw new \LogicException('Cette récompense n\'est pas utilisable sur ce rendez-vous.');
                }
                $reduction = $recompense->getValeurCentimes();
            }

            $reservation = new Reservation(
                $cliente,
                $prestation,
                $debut,
                $this->tarification->acompteCentimes($prestation, $reduction),
                $reduction,
                $recompense?->getSeuilPoints() ?? 0,
            );
            $reservation->setEmailContact($coordonnees->email);
            $reservation->accepterConditions($this->clock->now());
            $this->entityManager->persist($reservation);
            if (null !== $recompense) {
                $this->entityManager->persist(new MouvementPoints($cliente, -$recompense->getSeuilPoints(), MotifMouvementPoints::UTILISATION, $reservation, null, $recompense->getNom()));
            }
            $this->entityManager->flush();
        } finally {
            $connexion->fetchOne('SELECT RELEASE_LOCK(?)', [self::VERROU]);
        }

        try {
            $empreinte = $this->paiement->creerEmpreinte(
                $reservation->getAcompteCentimes(),
                \sprintf('Acompte %s du %s', $prestation->getNom(), $debut->format('d/m/Y H:i')),
                ['reservation' => (string) $reservation->getId()],
            );
        } catch (\Throwable $erreur) {
            // Sans empreinte, la demande n'a pas lieu d'être : le créneau est libéré tout de suite.
            $reservation->changerStatut(StatutReservation::ANNULEE, $this->clock->now());
            $this->entityManager->flush();
            $this->logger->error('Création de l\'empreinte impossible.', ['reservation' => $reservation->getId(), 'erreur' => $erreur->getMessage()]);

            throw $erreur;
        }

        $reservation->setStripePaymentIntentId($empreinte->identifiant);
        $this->entityManager->flush();

        return $reservation;
    }

    /**
     * La carte est bloquée : la demande part en validation. Idempotent (retour de Stripe et webhook).
     */
    public function empreinteAutorisee(Reservation $reservation): void
    {
        if (StatutReservation::PAIEMENT_EN_COURS !== $reservation->getStatut()) {
            return;
        }

        $reservation->changerStatut(StatutReservation::EN_ATTENTE, $this->clock->now());
        $this->entityManager->flush();
        $this->logger->info('Demande de réservation reçue.', ['reservation' => $reservation->getId()]);

        $this->notifications->demandeRecue($reservation);
    }

    public function valider(Reservation $reservation, string $auteur): void
    {
        $this->exigerStatut($reservation, StatutReservation::EN_ATTENTE);

        // Débit d'abord : si Stripe refuse, la réservation reste en attente.
        if (null !== $reservation->getStripePaymentIntentId()) {
            $this->paiement->capturer($reservation->getStripePaymentIntentId());
        }
        $this->changer($reservation, StatutReservation::CONFIRMEE, $auteur);

        $this->notifications->confirmee($reservation);
    }

    public function refuser(Reservation $reservation, string $auteur): void
    {
        $this->exigerStatut($reservation, StatutReservation::EN_ATTENTE);

        $this->libererEmpreinte($reservation);
        $this->changer($reservation, StatutReservation::REFUSEE, $auteur);
        $this->restituerPoints($reservation, $auteur);

        $this->notifications->refusee($reservation);
    }

    /**
     * Annulation (la cliente prévient le salon, ou empêchement du salon).
     * - Demande pas encore validée : l'empreinte est libérée, rien n'est débité.
     * - Rendez-vous confirmé (acompte débité) : remboursé si l'annulation a lieu au moins
     *   48 h avant (réglable), ou toujours si c'est le salon qui annule ; sinon l'acompte est conservé.
     */
    public function annuler(Reservation $reservation, string $auteur, bool $initiativeSalon = false): void
    {
        $etaitConfirmee = StatutReservation::CONFIRMEE === $reservation->getStatut();
        $rembourser = $etaitConfirmee && ($initiativeSalon || $this->annulationGratuite($reservation));

        if (!$etaitConfirmee) {
            $this->libererEmpreinte($reservation);
        } elseif ($rembourser && null !== $reservation->getStripePaymentIntentId() && $reservation->getAcompteCentimes() > 0) {
            // Remboursement d'abord : si Stripe refuse, la réservation reste confirmée.
            $this->paiement->rembourser($reservation->getStripePaymentIntentId());
            $reservation->marquerAcompteRembourse($this->clock->now());
        }
        $this->changer($reservation, StatutReservation::ANNULEE, $auteur);
        $this->restituerPoints($reservation, $auteur);
        $this->logger->notice('Réservation annulée.', [
            'reservation' => $reservation->getId(),
            'initiative' => $initiativeSalon ? 'salon' : 'cliente',
            'acompte_rembourse' => null !== $reservation->getAcompteRembourseAt(),
        ]);

        $this->notifications->annulee($reservation, $etaitConfirmee && !$rembourser);
    }

    /** Vrai tant que l'annulation est gratuite (acompte remboursé) : au moins N heures avant le rendez-vous. */
    public function annulationGratuite(Reservation $reservation): bool
    {
        return $this->clock->now() <= $this->limiteAnnulationGratuite($reservation);
    }

    public function limiteAnnulationGratuite(Reservation $reservation): \DateTimeImmutable
    {
        return $reservation->getDebut()->modify(\sprintf('-%d hours', $this->parametres->valeur(Parametre::ANNULATION_GRATUITE_HEURES)));
    }

    /** Empêchement du salon : l'acompte est toujours remboursé. */
    public function annulerParLeSalon(Reservation $reservation, string $auteur): void
    {
        $this->annuler($reservation, $auteur, initiativeSalon: true);
    }

    /** La cliente est venue : ses points de fidélité sont crédités (une seule fois par réservation). */
    public function honorer(Reservation $reservation, string $auteur): void
    {
        $this->exigerRendezVousPasse($reservation);
        $this->changer($reservation, StatutReservation::HONOREE, $auteur, flush: false);

        $reservation->getClient()->enregistrerVisite($reservation->getFin());
        $this->fidelite->crediterVisite($reservation, $auteur);
        $this->entityManager->flush();
        $this->parrainage->recompenser($reservation);
    }

    public function nonHonorer(Reservation $reservation, string $auteur): void
    {
        $this->exigerRendezVousPasse($reservation);
        $this->changer($reservation, StatutReservation::NON_HONOREE, $auteur);
    }

    /**
     * Demande abandonnée pendant le paiement ou restée sans réponse : le créneau est libéré.
     */
    public function expirer(Reservation $reservation): void
    {
        $etaitEnAttente = StatutReservation::EN_ATTENTE === $reservation->getStatut();

        $this->libererEmpreinte($reservation);
        $this->changer($reservation, StatutReservation::EXPIREE, null);
        $this->restituerPoints($reservation, null);

        if ($etaitEnAttente) {
            $this->notifications->expiree($reservation);
        }
    }

    /**
     * Une cliente existante est retrouvée par son téléphone. Sa fiche n'est jamais modifiée
     * par une saisie anonyme (pas même un email manquant) : sinon, en tapant le numéro d'une autre
     * cliente, on pourrait rattacher sa fiche et ses points à son propre compte. L'email saisi
     * est gardé sur la réservation pour les confirmations.
     */
    private function cliente(CoordonneesCliente $coordonnees, string $telephone): Client
    {
        $cliente = $this->clientes->findOneBy(['telephone' => $telephone]);
        if ($cliente instanceof Client) {
            return $cliente;
        }

        $cliente = (new Client($coordonnees->prenom, $coordonnees->nom, $telephone))->setEmail($coordonnees->email);
        $this->entityManager->persist($cliente);

        return $cliente;
    }

    private function changer(Reservation $reservation, StatutReservation $statut, ?string $auteur, bool $flush = true): void
    {
        $avant = $reservation->getStatut();
        $reservation->changerStatut($statut, $this->clock->now());
        if ($flush) {
            $this->entityManager->flush();
        }

        $this->logger->notice('Statut de réservation modifié.', [
            'reservation' => $reservation->getId(),
            'de' => $avant->value,
            'vers' => $statut->value,
            'par' => $auteur ?? 'automatique',
        ]);
    }

    private function exigerStatut(Reservation $reservation, StatutReservation $attendu): void
    {
        if ($attendu !== $reservation->getStatut()) {
            throw new \LogicException(\sprintf('Action impossible : la réservation est « %s ».', mb_strtolower($reservation->getStatut()->libelle())));
        }
    }

    private function exigerRendezVousPasse(Reservation $reservation): void
    {
        $this->exigerStatut($reservation, StatutReservation::CONFIRMEE);
        if ($reservation->getDebut() > $this->clock->now()) {
            throw new \LogicException('Le rendez-vous n\'a pas encore eu lieu.');
        }
    }

    private function libererEmpreinte(Reservation $reservation): void
    {
        if (null === $reservation->getStripePaymentIntentId()) {
            return;
        }

        try {
            $this->paiement->annuler($reservation->getStripePaymentIntentId());
        } catch (\Throwable $erreur) {
            // L'empreinte expirera d'elle-même chez Stripe au bout de 7 jours : on ne bloque pas le changement de statut.
            $this->logger->error('Libération de l\'empreinte impossible.', ['reservation' => $reservation->getId(), 'erreur' => $erreur->getMessage()]);
        }
    }

    private function restituerPoints(Reservation $reservation, ?string $auteur): void
    {
        if ($reservation->getPointsUtilises() <= 0) {
            return;
        }

        $this->entityManager->persist(new MouvementPoints($reservation->getClient(), $reservation->getPointsUtilises(), MotifMouvementPoints::RESTITUTION, $reservation, $auteur));
        $this->entityManager->flush();
    }
}
