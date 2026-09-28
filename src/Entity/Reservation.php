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
    private StatutReservation $statut = StatutReservation::EN_ATTENTE;

    #[ORM\Column]
    private int $prixCentimes;

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

    public function __construct(
        Client $client,
        Prestation $prestation,
        \DateTimeImmutable $debut,
        int $acompteCentimes,
        int $reductionCentimes = 0,
        int $pointsUtilises = 0,
    ) {
        if ($reductionCentimes < 0 || $reductionCentimes > $prestation->getPrixCentimes()) {
            throw new \InvalidArgumentException('Réduction invalide.');
        }

        $this->client = $client;
        $this->prestation = $prestation;
        $this->debut = $debut;
        $this->fin = $debut->modify(\sprintf('+%d minutes', $prestation->getDureeMinutes()));
        $this->prixCentimes = $prestation->getPrixCentimes();
        $this->acompteCentimes = $acompteCentimes;
        $this->reductionCentimes = $reductionCentimes;
        $this->pointsUtilises = $pointsUtilises;
        $this->createdAt = new \DateTimeImmutable();
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
        $this->decisionAt = $at;
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
        return max(0, $this->prixCentimes - $this->reductionCentimes - $this->acompteCentimes);
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
}
