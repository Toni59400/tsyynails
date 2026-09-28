<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MotifMouvementPoints;
use App\Repository\MouvementPointsRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Journal des points en ajout seul : le solde est la somme des mouvements.
 * Une erreur se corrige par un mouvement inverse, jamais en modifiant une ligne.
 */
#[ORM\Entity(repositoryClass: MouvementPointsRepository::class, readOnly: true)]
#[ORM\Index(name: 'idx_mouvement_client', fields: ['client'])]
#[ORM\UniqueConstraint(name: 'uniq_mouvement_reservation_motif', fields: ['reservation', 'motif'])]
class MouvementPoints
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Client $client;

    /** Positif = points gagnés, négatif = points utilisés. */
    #[ORM\Column]
    private int $delta;

    #[ORM\Column(length: 20, enumType: MotifMouvementPoints::class)]
    private MotifMouvementPoints $motif;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Reservation $reservation;

    /** Email de l'admin à l'origine du mouvement, null si automatique. */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $auteur;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $commentaire;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Client $client,
        int $delta,
        MotifMouvementPoints $motif,
        ?Reservation $reservation = null,
        ?string $auteur = null,
        ?string $commentaire = null,
    ) {
        if (0 === $delta) {
            throw new \InvalidArgumentException('Un mouvement de points ne peut pas être nul.');
        }

        $this->client = $client;
        $this->delta = $delta;
        $this->motif = $motif;
        $this->reservation = $reservation;
        $this->auteur = $auteur;
        $this->commentaire = $commentaire;
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

    public function getDelta(): int
    {
        return $this->delta;
    }

    public function getMotif(): MotifMouvementPoints
    {
        return $this->motif;
    }

    public function getReservation(): ?Reservation
    {
        return $this->reservation;
    }

    public function getAuteur(): ?string
    {
        return $this->auteur;
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
