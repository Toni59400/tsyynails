<?php

declare(strict_types=1);

namespace App\Service\Reservation;

use App\Entity\Client;
use App\Entity\MouvementPoints;
use App\Entity\Parametre;
use App\Entity\Prestation;
use App\Entity\RecompenseFidelite;
use App\Entity\Reservation;
use App\Entity\Supplement;
use App\Enum\MotifMouvementPoints;
use App\Enum\StatutReservation;
use App\Repository\ClientRepository;
use App\Repository\ParametreRepository;
use App\Service\Fidelite\Parrainage;
use App\Service\Fidelite\ProgrammeFidelite;
use App\Service\Fidelite\ReductionAVie;
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
        private readonly ReductionAVie $reductionAVie,
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
        ?Supplement $supplement = null,
        ?Prestation $prestationDuo = null,
        string $modePaiement = Reservation::MODE_CARTE,
    ): Reservation {
        if (null !== $recompense && null === $clienteConnectee) {
            throw new \LogicException('Les points ne s\'utilisent qu\'avec un compte.');
        }
        if (!\in_array($modePaiement, $this->tarification->modesPaiementEnLigne(), true)) {
            throw new \LogicException('Ce mode de paiement n\'est plus proposé : choisissez-en un autre.');
        }
        $especes = Reservation::MODE_ESPECES === $modePaiement;
        $telephone = null === $clienteConnectee
            ? (CoordonneesCliente::normaliserTelephone($coordonnees->telephone) ?? throw new \InvalidArgumentException('Téléphone invalide.'))
            : null;
        $connexion = $this->entityManager->getConnection();

        if (1 !== (int) $connexion->fetchOne('SELECT GET_LOCK(?, ?)', [self::VERROU, self::ATTENTE_VERROU_SECONDES])) {
            throw new CreneauIndisponibleException();
        }

        try {
            // Revérifié sous verrou : le créneau a pu être pris depuis l'affichage.
            if (null !== $supplement && !$supplement->estProposePour($prestation)) {
                throw new \LogicException('Ce supplément n\'est plus proposé : choisissez à nouveau votre créneau.');
            }
            if (null !== $prestationDuo && (!$this->tarification->duoActif() || !$prestationDuo->isActive())) {
                throw new \LogicException('Le RDV duo n\'est plus proposé pour cette prestation : choisissez à nouveau.');
            }
            // Réduction à vie : seulement pour une cliente connectée (le prix affiché avant l'envoi en tient compte).
            $devis = $this->tarification->devis($prestation, $supplement, $prestationDuo, $debut, $clienteConnectee);
            $minutesEnPlus = $devis->dureeMinutes - $prestation->getDureeMinutes();
            if (!$prestation->isActive() || !$this->calculateur->estDisponible($prestation, $debut, $minutesEnPlus)) {
                throw new CreneauIndisponibleException();
            }

            $cliente = $clienteConnectee ?? $this->cliente($coordonnees, (string) $telephone);

            // Revérifiée sous verrou : le solde a pu changer depuis l'affichage.
            $reduction = 0;
            if (null !== $recompense) {
                if (!\in_array($recompense, $this->fidelite->utilisablesEnLigne($cliente, $devis->aPayerCentimes()), true)) {
                    throw new \LogicException('Cette récompense n\'est pas utilisable sur ce rendez-vous.');
                }
                $reduction = $recompense->getValeurCentimes();
            }

            $reservation = new Reservation(
                $cliente,
                $prestation,
                $debut,
                // Espèces : aucun acompte en ligne, tout est réglé au rendez-vous.
                $especes ? 0 : $this->tarification->acompte($devis, $reduction),
                $reduction,
                $recompense?->getSeuilPoints() ?? 0,
                $supplement,
                $prestationDuo,
                $devis->majorationCentimes,
                $devis->remisePourcentage,
                $devis->remiseMotif,
            );
            $reservation->setEmailContact($coordonnees->email);
            $reservation->setModeAcompte($modePaiement);
            $reservation->accepterConditions($this->clock->now());
            if ($especes) {
                // Pas d'empreinte à attendre : la demande part directement en validation.
                $reservation->changerStatut(StatutReservation::EN_ATTENTE, $this->clock->now());
            }
            $this->entityManager->persist($reservation);
            if (null !== $recompense) {
                $this->entityManager->persist(new MouvementPoints($cliente, -$recompense->getSeuilPoints(), MotifMouvementPoints::UTILISATION, $reservation, null, $recompense->getNom()));
            }
            $this->entityManager->flush();
        } finally {
            $connexion->fetchOne('SELECT RELEASE_LOCK(?)', [self::VERROU]);
        }

        if ($especes) {
            $this->logger->info('Demande de réservation reçue (paiement en espèces).', ['reservation' => $reservation->getId()]);
            $this->notifications->demandeRecue($reservation);

            return $reservation;
        }

        try {
            $empreinte = $this->paiement->creerEmpreinte(
                $reservation->getAcompteCentimes(),
                \sprintf('Acompte %s%s%s du %s', $prestation->getNom(), null !== $prestationDuo ? ' + '.$prestationDuo->getNom().' (duo)' : '', null !== $supplement ? ' + '.$supplement->getNom() : '', $debut->format('d/m/Y H:i')),
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
     * Rendez-vous pris par la prothésiste (téléphone, au salon, RDV duo) : confirmé d'emblée, sans acompte en ligne.
     * Les horaires d'ouverture ne sont pas imposés (rendez-vous du soir), mais le créneau doit être libre.
     *
     * @param int|null $remiseForcee pourcentage choisi par la prothésiste ; null = automatique (duo ou réduction à vie)
     *
     * @throws CreneauIndisponibleException
     */
    public function reserverParLeSalon(
        Prestation $prestation,
        \DateTimeImmutable $debut,
        CoordonneesCliente $coordonnees,
        string $auteur,
        ?Supplement $supplement = null,
        ?Prestation $prestationDuo = null,
        ?int $remiseForcee = null,
    ): Reservation {
        $telephone = CoordonneesCliente::normaliserTelephone($coordonnees->telephone) ?? throw new \InvalidArgumentException('Téléphone invalide.');
        $connexion = $this->entityManager->getConnection();
        if (1 !== (int) $connexion->fetchOne('SELECT GET_LOCK(?, ?)', [self::VERROU, self::ATTENTE_VERROU_SECONDES])) {
            throw new CreneauIndisponibleException();
        }

        try {
            $cliente = $this->cliente($coordonnees, $telephone);
            $devis = $this->tarification->devis($prestation, $supplement, $prestationDuo, $debut, $cliente, $remiseForcee);
            if (!$this->calculateur->estLibre($debut, $debut->modify(\sprintf('+%d minutes', $devis->dureeMinutes)))) {
                throw new CreneauIndisponibleException();
            }

            $reservation = new Reservation(
                $cliente,
                $prestation,
                $debut,
                0,
                0,
                0,
                $supplement,
                $prestationDuo,
                $devis->majorationCentimes,
                $devis->remisePourcentage,
                $devis->remiseMotif,
            );
            $reservation->setEmailContact($coordonnees->email);
            $reservation->changerStatut(StatutReservation::EN_ATTENTE, $this->clock->now());
            $reservation->changerStatut(StatutReservation::CONFIRMEE, $this->clock->now());
            $this->entityManager->persist($reservation);
            $this->entityManager->flush();
        } finally {
            $connexion->fetchOne('SELECT RELEASE_LOCK(?)', [self::VERROU]);
        }

        $this->logger->notice('Rendez-vous pris par le salon.', ['reservation' => $reservation->getId(), 'par' => $auteur]);
        $this->notifications->confirmee($reservation);

        return $reservation;
    }

    // ---------- Reste à payer au rendez-vous ----------

    /** Reste encaissé en espèces au salon (un éventuel lien de paiement par carte est annulé). */
    public function reglerSoldeEnEspeces(Reservation $reservation, string $auteur): void
    {
        $this->exigerResteAPayer($reservation);
        $this->abandonnerLienCarte($reservation);
        $reservation->marquerSoldeRegle(Reservation::MODE_ESPECES, $reservation->getResteAPayerCentimes(), $this->clock->now());
        $this->entityManager->flush();
        $this->logger->notice('Reste réglé en espèces.', ['reservation' => $reservation->getId(), 'montant' => $reservation->getSoldeCentimes(), 'par' => $auteur]);
    }

    /**
     * Lien de paiement par carte du reste : paiement immédiat, réglé par la cliente sur /reservation/reglement/{jeton}
     * (son téléphone, ou celui du salon). Réutilise le lien déjà créé s'il est toujours en attente.
     */
    public function preparerSoldeParCarte(Reservation $reservation, string $auteur): void
    {
        $this->exigerResteAPayer($reservation);
        if (Reservation::MODE_CARTE === $reservation->getSoldeMode() && null !== $reservation->getSoldePaymentIntentId()) {
            return;
        }

        $paiement = $this->paiement->creerPaiement(
            $reservation->getResteAPayerCentimes(),
            \sprintf('Règlement %s du %s', $reservation->getPrestation()->getNom(), $reservation->getDebut()->format('d/m/Y H:i')),
            ['reservation' => (string) $reservation->getId(), 'type' => 'solde'],
        );
        $reservation->preparerSoldeParCarte($paiement->identifiant);
        $this->entityManager->flush();
        $this->logger->notice('Lien de paiement par carte créé.', ['reservation' => $reservation->getId(), 'par' => $auteur]);
    }

    /** La cliente règle finalement autrement : le lien de paiement par carte est annulé. */
    public function abandonnerSoldeParCarte(Reservation $reservation, string $auteur): void
    {
        $this->abandonnerLienCarte($reservation);
        $this->entityManager->flush();
        $this->logger->notice('Lien de paiement par carte abandonné.', ['reservation' => $reservation->getId(), 'par' => $auteur]);
    }

    /** Stripe confirme le paiement du reste par carte (retour de la page de paiement ou webhook). Idempotent. */
    public function soldePayeParCarte(Reservation $reservation): void
    {
        if ($reservation->soldeEstRegle() || null === $reservation->getSoldePaymentIntentId()) {
            return;
        }
        $reservation->marquerSoldeRegle(Reservation::MODE_CARTE, $reservation->getResteAPayerCentimes(), $this->clock->now());
        $this->entityManager->flush();
        $this->logger->notice('Reste réglé par carte.', ['reservation' => $reservation->getId(), 'montant' => $reservation->getSoldeCentimes()]);
    }

    /** Vérifie auprès de Stripe si le lien de paiement par carte a été réglé (page de suivi, fiche admin). */
    public function verifierSoldeParCarte(Reservation $reservation): void
    {
        $identifiant = $reservation->getSoldePaymentIntentId();
        if (null === $identifiant || $reservation->soldeEstRegle()) {
            return;
        }
        try {
            if (PaiementGateway::STATUT_PAYE === $this->paiement->statut($identifiant)) {
                $this->soldePayeParCarte($reservation);
            }
        } catch (\Throwable $erreur) {
            $this->logger->warning('Statut du paiement du reste indisponible.', ['reservation' => $reservation->getId(), 'erreur' => $erreur->getMessage()]);
        }
    }

    // ---------- Modification et suppression par la prothésiste ----------

    /**
     * Déplace ou modifie un rendez-vous à venir (en attente ou confirmé). Les prestations sont figées dès qu'un
     * acompte par carte existe (empreinte ou débit) ; horaire, supplément et remise restent modifiables.
     * Le prix est recalculé (majoration du soir selon le nouvel horaire), l'acompte et les points utilisés sont conservés.
     *
     * @return bool vrai si la cliente a été prévenue par email
     *
     * @throws CreneauIndisponibleException
     * @throws \LogicException
     */
    public function modifier(
        Reservation $reservation,
        Prestation $prestation,
        \DateTimeImmutable $debut,
        ?Supplement $supplement,
        ?Prestation $prestationDuo,
        int $remisePourcentage,
        string $auteur,
        bool $prevenirCliente = true,
    ): bool {
        if (!\in_array($reservation->getStatut(), [StatutReservation::EN_ATTENTE, StatutReservation::CONFIRMEE], true)) {
            throw new \LogicException(\sprintf('Modification impossible : la réservation est « %s ».', mb_strtolower($reservation->getStatut()->libelle())));
        }
        $prestationsChangees = $prestation !== $reservation->getPrestation() || $prestationDuo?->getNom() !== $reservation->getPrestationDuoNom();
        if ($prestationsChangees && $reservation->aUnAcompteCarte()) {
            throw new \LogicException('L\'acompte a été réglé par carte pour cette prestation : seuls l\'horaire, le supplément et la remise peuvent changer.');
        }

        $connexion = $this->entityManager->getConnection();
        if (1 !== (int) $connexion->fetchOne('SELECT GET_LOCK(?, ?)', [self::VERROU, self::ATTENTE_VERROU_SECONDES])) {
            throw new CreneauIndisponibleException();
        }
        try {
            $duree = $prestation->getDureeMinutes() + ($prestationDuo?->getDureeMinutes() ?? 0) + ($supplement?->getDureeMinutes() ?? 0);
            if (!$this->calculateur->estLibre($debut, $debut->modify(\sprintf('+%d minutes', $duree)), $reservation)) {
                throw new CreneauIndisponibleException();
            }

            $ancienDebut = $reservation->getDebut();
            // Même pourcentage : on garde l'origine de la remise (duo, réduction à vie) ; sinon elle devient « saisie ».
            $motif = $remisePourcentage === $reservation->getRemisePourcentage() ? ($reservation->getRemiseMotif() ?? 'manuelle') : 'manuelle';
            $reservation->appliquerTarif($prestation, $debut, $supplement, $prestationDuo, $this->tarification->majorationCentimes($debut), $remisePourcentage, $motif);
            if ($debut != $ancienDebut) {
                $reservation->reinitialiserRappel();
            }
            $this->entityManager->flush();
        } finally {
            $connexion->fetchOne('SELECT RELEASE_LOCK(?)', [self::VERROU]);
        }

        $this->logger->notice('Réservation modifiée.', ['reservation' => $reservation->getId(), 'par' => $auteur]);
        if ($prevenirCliente && null !== $reservation->getEmailNotification()) {
            $this->notifications->modifiee($reservation);

            return true;
        }

        return false;
    }

    /**
     * Supprime une réservation saisie par erreur. Interdit dès qu'un paiement par carte a eu lieu (acompte débité,
     * même remboursé, ou reste réglé par carte) et pour un rendez-vous honoré (chiffre d'affaires, points).
     * Une empreinte en attente est libérée et les points utilisés sont rendus.
     */
    public function supprimer(Reservation $reservation, string $auteur): void
    {
        if ($reservation->aUnPaiementParCarte()) {
            throw new \LogicException('Suppression impossible : un paiement par carte a eu lieu. Annulez plutôt le rendez-vous.');
        }
        if (StatutReservation::HONOREE === $reservation->getStatut()) {
            throw new \LogicException('Suppression impossible : ce rendez-vous honoré compte dans le chiffre d\'affaires et les points.');
        }

        $this->libererEmpreinte($reservation);
        $this->abandonnerLienCarte($reservation);
        // Points déjà rendus si la demande a été refusée, annulée ou a expiré.
        if (!\in_array($reservation->getStatut(), [StatutReservation::REFUSEE, StatutReservation::ANNULEE, StatutReservation::EXPIREE], true)) {
            $this->restituerPoints($reservation, $auteur);
        }
        // L'historique des points de la cliente est gardé, simplement détaché du rendez-vous supprimé.
        $this->entityManager->createQuery('UPDATE App\Entity\MouvementPoints m SET m.reservation = NULL WHERE m.reservation = :r')
            ->setParameter('r', $reservation)
            ->execute();

        $id = $reservation->getId();
        $this->entityManager->remove($reservation);
        $this->entityManager->flush();
        $this->logger->notice('Réservation supprimée.', ['reservation' => $id, 'par' => $auteur]);
    }

    private function exigerResteAPayer(Reservation $reservation): void
    {
        if (!\in_array($reservation->getStatut(), [StatutReservation::CONFIRMEE, StatutReservation::HONOREE], true)) {
            throw new \LogicException('Le reste se règle sur un rendez-vous confirmé ou honoré.');
        }
        if ($reservation->soldeEstRegle()) {
            throw new \LogicException('Le reste est déjà réglé.');
        }
        if ($reservation->getResteAPayerCentimes() <= 0) {
            throw new \LogicException('Il ne reste rien à payer.');
        }
    }

    private function abandonnerLienCarte(Reservation $reservation): void
    {
        $identifiant = $reservation->getSoldePaymentIntentId();
        if (null === $identifiant || $reservation->soldeEstRegle()) {
            return;
        }
        try {
            $this->paiement->annuler($identifiant);
        } catch (\Throwable $erreur) {
            $this->logger->error('Annulation du lien de paiement impossible.', ['reservation' => $reservation->getId(), 'erreur' => $erreur->getMessage()]);
        }
        $reservation->abandonnerSoldeParCarte();
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
        if (null !== $reservation->getStripePaymentIntentId() && $reservation->getAcompteCentimes() > 0) {
            $this->paiement->capturer($reservation->getStripePaymentIntentId());
            $reservation->marquerAcompteDebite($this->clock->now());
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
        // Seuil de la réduction à vie atteint grâce à ce rendez-vous ?
        $this->reductionAVie->verifierSeuil($reservation->getClient());
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
