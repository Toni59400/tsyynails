<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\StatutReservation;
use App\Repository\ReservationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Les montants sont figés à la création : une modification de tarif
 * ne change pas une réservation existante.
 */
#[ORM\Entity(repositoryClass: ReservationRepository::class)]
#[ORM\Index(name: 'idx_reservation_debut', fields: ['debut'])]
#[ORM\Index(name: 'idx_reservation_statut', fields: ['statut'])]
#[ORM\Index(name: 'idx_reservation_created', fields: ['createdAt'])]
class Reservation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Client $client;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Prestation $prestation;

    #[ORM\Column]
    private \DateTimeImmutable $debut;

    #[ORM\Column]
    private \DateTimeImmutable $fin;

    #[ORM\Column(length: 20, enumType: StatutReservation::class)]
    private StatutReservation $statut = StatutReservation::PAIEMENT_EN_COURS;

    /** Prix total : prestation + supplément éventuel. */
    #[ORM\Column]
    private int $prixCentimes;

    /** Supplément choisi (nail art…) ; nom et prix copiés pour que l'historique ne change pas. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Supplement $supplement = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $supplementNom = null;

    #[ORM\Column]
    private int $supplementPrixCentimes = 0;

    /** RDV duo : prestation de la deuxième personne, enchaînée après la première. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Prestation $prestationDuo = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $prestationDuoNom = null;

    #[ORM\Column]
    private int $prestationDuoPrixCentimes = 0;

    /** Majoration du soir (rendez-vous commençant tard), comprise dans le prix total, jamais remisée. */
    #[ORM\Column]
    private int $majorationCentimes = 0;

    public const MODE_CARTE = 'carte';
    public const MODE_ESPECES = 'especes';
    public const MODES_PAIEMENT = [self::MODE_CARTE, self::MODE_ESPECES];

    /** Acompte : par carte (empreinte Stripe) ou rien en ligne, tout en espèces au rendez-vous ; null = pris par le salon. */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $modeAcompte = null;

    /** Acompte réellement débité par carte (validation). Sert à savoir si la réservation peut être supprimée. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $acompteDebiteAt = null;

    /** Reste à payer au rendez-vous : mode, montant encaissé, date. */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $soldeMode = null;

    #[ORM\Column]
    private int $soldeCentimes = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $soldePayeAt = null;

    /** Paiement Stripe du reste (lien envoyé à la cliente), en capture immédiate. */
    #[ORM\Column(length: 255, unique: true, nullable: true)]
    private ?string $soldePaymentIntentId = null;

    /** Remise en % sur les prestations et suppléments (duo, réduction à vie, ou fixée par l'admin). */
    #[ORM\Column]
    private int $remisePourcentage = 0;

    #[ORM\Column]
    private int $remiseCentimes = 0;

    /** Origine de la remise : duo, privilege ou manuelle. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $remiseMotif = null;

    #[ORM\Column]
    private int $acompteCentimes;

    /** Réduction obtenue avec des points de fidélité. */
    #[ORM\Column]
    private int $reductionCentimes = 0;

    #[ORM\Column]
    private int $pointsUtilises = 0;

    #[ORM\Column(length: 255, unique: true, nullable: true)]
    private ?string $stripePaymentIntentId = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Date de validation, de refus, d'expiration ou d'annulation. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $decisionAt = null;

    /**
     * Jeton aléatoire (128 bits) de la page de suivi envoyée à la cliente, qui n'a pas forcément de compte.
     * Ne contient aucune donnée personnelle.
     */
    #[ORM\Column(length: 32, unique: true)]
    private string $jetonSuivi;

    /**
     * Email saisi pour cette demande : les confirmations y sont envoyées.
     * Il ne modifie jamais la fiche cliente (une saisie anonyme ne prouve pas l'identité).
     */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $emailContact = null;

    /** Email de rappel envoyé la veille (une seule fois). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $rappelEnvoyeAt = null;

    /** Acompte déjà débité puis remboursé (annulation dans les délais ou à l'initiative du salon). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $acompteRembourseAt = null;

    /** Acceptation des conditions de réservation (acompte, annulation) au moment de la demande. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $conditionsAccepteesAt = null;

    public function __construct(
        Client $client,
        Prestation $prestation,
        \DateTimeImmutable $debut,
        int $acompteCentimes,
        int $reductionCentimes = 0,
        int $pointsUtilises = 0,
        ?Supplement $supplement = null,
        ?Prestation $prestationDuo = null,
        int $majorationCentimes = 0,
        int $remisePourcentage = 0,
        ?string $remiseMotif = null,
    ) {
        $this->client = $client;
        $this->acompteCentimes = $acompteCentimes;
        $this->reductionCentimes = $reductionCentimes;
        $this->pointsUtilises = $pointsUtilises;
        $this->appliquerTarif($prestation, $debut, $supplement, $prestationDuo, $majorationCentimes, $remisePourcentage, $remiseMotif);
        $this->createdAt = new \DateTimeImmutable();
        $this->jetonSuivi = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    /**
     * Prestations, horaire et prix (création, ou modification par la prothésiste).
     * Prix total = prestations + supplément + majoration ; la remise ne porte pas sur la majoration.
     * L'acompte et la récompense de points déjà enregistrés sont conservés.
     */
    public function appliquerTarif(
        Prestation $prestation,
        \DateTimeImmutable $debut,
        ?Supplement $supplement = null,
        ?Prestation $prestationDuo = null,
        int $majorationCentimes = 0,
        int $remisePourcentage = 0,
        ?string $remiseMotif = null,
    ): void {
        if ($majorationCentimes < 0 || $remisePourcentage < 0 || $remisePourcentage > 100) {
            throw new \InvalidArgumentException('Majoration ou remise invalide.');
        }
        $base = $prestation->getPrixCentimes() + ($prestationDuo?->getPrixCentimes() ?? 0) + ($supplement?->getPrixCentimes() ?? 0);
        $remise = self::calculerRemise($base, $remisePourcentage);
        $prix = $base + $majorationCentimes;
        if ($this->reductionCentimes < 0 || $this->reductionCentimes > $prix - $remise) {
            throw new \InvalidArgumentException('Réduction invalide.');
        }
        if ($this->acompteCentimes > $prix - $remise - $this->reductionCentimes) {
            throw new \InvalidArgumentException('Le nouveau prix serait inférieur à l\'acompte déjà versé.');
        }

        $this->prestation = $prestation;
        $this->debut = $debut;
        $this->fin = $debut->modify(\sprintf('+%d minutes', $prestation->getDureeMinutes() + ($prestationDuo?->getDureeMinutes() ?? 0) + ($supplement?->getDureeMinutes() ?? 0)));
        $this->prixCentimes = $prix;
        $this->supplement = $supplement;
        $this->supplementNom = $supplement?->getNom();
        $this->supplementPrixCentimes = $supplement?->getPrixCentimes() ?? 0;
        $this->prestationDuo = $prestationDuo;
        $this->prestationDuoNom = $prestationDuo?->getNom();
        $this->prestationDuoPrixCentimes = $prestationDuo?->getPrixCentimes() ?? 0;
        $this->majorationCentimes = $majorationCentimes;
        $this->remisePourcentage = $remisePourcentage;
        $this->remiseCentimes = $remise;
        $this->remiseMotif = $remise > 0 ? $remiseMotif : null;
    }

    /** L'horaire a changé : le rappel de la veille devra repartir. */
    public function reinitialiserRappel(): void
    {
        $this->rappelEnvoyeAt = null;
    }

    public function getModeAcompte(): ?string
    {
        return $this->modeAcompte;
    }

    public function setModeAcompte(?string $mode): static
    {
        if (null !== $mode && !\in_array($mode, self::MODES_PAIEMENT, true)) {
            throw new \InvalidArgumentException('Mode de paiement inconnu.');
        }
        $this->modeAcompte = $mode;

        return $this;
    }

    public function getAcompteDebiteAt(): ?\DateTimeImmutable
    {
        return $this->acompteDebiteAt;
    }

    public function marquerAcompteDebite(\DateTimeImmutable $at): void
    {
        $this->acompteDebiteAt ??= $at;
    }

    /** Un paiement par carte a eu lieu (acompte débité, même remboursé ensuite, ou reste réglé par carte). */
    public function aUnPaiementParCarte(): bool
    {
        return null !== $this->acompteDebiteAt || (self::MODE_CARTE === $this->soldeMode && null !== $this->soldePayeAt);
    }

    /** Empreinte ou acompte par carte : la prestation est alors figée (l'acompte a été calculé pour elle). */
    public function aUnAcompteCarte(): bool
    {
        return null !== $this->stripePaymentIntentId && $this->acompteCentimes > 0;
    }

    public function getSoldeMode(): ?string
    {
        return $this->soldeMode;
    }

    public function getSoldeCentimes(): int
    {
        return $this->soldeCentimes;
    }

    public function getSoldePayeAt(): ?\DateTimeImmutable
    {
        return $this->soldePayeAt;
    }

    public function getSoldePaymentIntentId(): ?string
    {
        return $this->soldePaymentIntentId;
    }

    public function soldeEstRegle(): bool
    {
        return null !== $this->soldePayeAt;
    }

    /** Lien de paiement par carte du reste créé (en attente du paiement de la cliente). */
    public function preparerSoldeParCarte(string $paymentIntentId): void
    {
        if ($this->soldeEstRegle()) {
            throw new \LogicException('Le reste est déjà réglé.');
        }
        $this->soldeMode = self::MODE_CARTE;
        $this->soldePaymentIntentId = $paymentIntentId;
    }

    /** Lien de paiement par carte abandonné (la cliente paie autrement). */
    public function abandonnerSoldeParCarte(): void
    {
        if (!$this->soldeEstRegle()) {
            $this->soldeMode = null;
            $this->soldePaymentIntentId = null;
        }
    }

    /** Reste à payer encaissé (espèces au salon, ou carte confirmée par Stripe). Idempotent. */
    public function marquerSoldeRegle(string $mode, int $montantCentimes, \DateTimeImmutable $at): void
    {
        if ($this->soldeEstRegle()) {
            return;
        }
        if (!\in_array($mode, self::MODES_PAIEMENT, true)) {
            throw new \InvalidArgumentException('Mode de paiement inconnu.');
        }
        $this->soldeMode = $mode;
        $this->soldeCentimes = $montantCentimes;
        $this->soldePayeAt = $at;
    }

    /** Remise arrondie au centime le plus proche. */
    public static function calculerRemise(int $baseCentimes, int $pourcentage): int
    {
        return (int) round($baseCentimes * $pourcentage / 100);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    public function getPrestation(): Prestation
    {
        return $this->prestation;
    }

    public function getDebut(): \DateTimeImmutable
    {
        return $this->debut;
    }

    public function getPrestationDuo(): ?Prestation
    {
        return $this->prestationDuo;
    }

    public function getPrestationDuoNom(): ?string
    {
        return $this->prestationDuoNom;
    }

    public function getPrestationDuoPrixCentimes(): int
    {
        return $this->prestationDuoPrixCentimes;
    }

    /** Prix de la prestation principale au moment de la réservation. */
    public function getPrestationPrixCentimes(): int
    {
        return $this->prixCentimes - $this->majorationCentimes - $this->supplementPrixCentimes - $this->prestationDuoPrixCentimes;
    }

    public function estDuo(): bool
    {
        return null !== $this->prestationDuoNom;
    }

    public function getMajorationCentimes(): int
    {
        return $this->majorationCentimes;
    }

    public function getRemisePourcentage(): int
    {
        return $this->remisePourcentage;
    }

    public function getRemiseCentimes(): int
    {
        return $this->remiseCentimes;
    }

    public function getRemiseMotif(): ?string
    {
        return $this->remiseMotif;
    }

    /** Montant réellement payé par la cliente : prix total moins remise et récompense de points. */
    public function getMontantDuCentimes(): int
    {
        return max(0, $this->prixCentimes - $this->remiseCentimes - $this->reductionCentimes);
    }

    public function getSupplement(): ?Supplement
    {
        return $this->supplement;
    }

    public function getSupplementNom(): ?string
    {
        return $this->supplementNom;
    }

    public function getSupplementPrixCentimes(): int
    {
        return $this->supplementPrixCentimes;
    }

    public function getFin(): \DateTimeImmutable
    {
        return $this->fin;
    }

    public function getStatut(): StatutReservation
    {
        return $this->statut;
    }

    /**
     * À appeler uniquement depuis le service ReservationWorkflow,
     * qui gère aussi Stripe, les emails et les points.
     */
    public function changerStatut(StatutReservation $nouveau, \DateTimeImmutable $at): void
    {
        if (!$this->statut->peutPasserA($nouveau)) {
            throw new \LogicException(\sprintf('Transition interdite : %s vers %s.', $this->statut->value, $nouveau->value));
        }

        $this->statut = $nouveau;
        // L'autorisation de l'empreinte n'est pas une décision de la prothésiste.
        if (StatutReservation::EN_ATTENTE !== $nouveau) {
            $this->decisionAt = $at;
        }
    }

    public function getPrixCentimes(): int
    {
        return $this->prixCentimes;
    }

    public function getAcompteCentimes(): int
    {
        return $this->acompteCentimes;
    }

    public function getReductionCentimes(): int
    {
        return $this->reductionCentimes;
    }

    public function getPointsUtilises(): int
    {
        return $this->pointsUtilises;
    }

    public function getResteAPayerCentimes(): int
    {
        return max(0, $this->getMontantDuCentimes() - $this->acompteCentimes);
    }

    public function getStripePaymentIntentId(): ?string
    {
        return $this->stripePaymentIntentId;
    }

    public function setStripePaymentIntentId(string $id): static
    {
        $this->stripePaymentIntentId = $id;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDecisionAt(): ?\DateTimeImmutable
    {
        return $this->decisionAt;
    }

    public function getJetonSuivi(): string
    {
        return $this->jetonSuivi;
    }

    public function getConditionsAccepteesAt(): ?\DateTimeImmutable
    {
        return $this->conditionsAccepteesAt;
    }

    public function accepterConditions(\DateTimeImmutable $at): static
    {
        $this->conditionsAccepteesAt = $at;

        return $this;
    }

    public function getEmailContact(): ?string
    {
        return $this->emailContact;
    }

    public function setEmailContact(?string $email): static
    {
        $this->emailContact = null === $email || '' === trim($email) ? null : mb_strtolower(trim($email));

        return $this;
    }

    /** Adresse des emails de cette réservation : celle saisie, sinon celle de la fiche. */
    public function getEmailNotification(): ?string
    {
        return $this->emailContact ?? $this->client->getEmail();
    }

    public function getRappelEnvoyeAt(): ?\DateTimeImmutable
    {
        return $this->rappelEnvoyeAt;
    }

    public function marquerRappelEnvoye(\DateTimeImmutable $at): void
    {
        $this->rappelEnvoyeAt = $at;
    }

    public function getAcompteRembourseAt(): ?\DateTimeImmutable
    {
        return $this->acompteRembourseAt;
    }

    public function marquerAcompteRembourse(\DateTimeImmutable $at): void
    {
        $this->acompteRembourseAt = $at;
    }
}
