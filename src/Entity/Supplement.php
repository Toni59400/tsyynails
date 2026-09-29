<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SupplementRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Option ajoutée à une prestation lors de la réservation (nail art niveau 1, niveau 2…).
 * Son prix s'ajoute à celui de la prestation, sa durée allonge le créneau réservé.
 */
#[ORM\Entity(repositoryClass: SupplementRepository::class)]
class Supplement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $nom;

    /** Ce que comprend le supplément, affiché dans le tunnel de réservation. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $description = null;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $prixCentimes;

    /** Temps ajouté au rendez-vous. */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    #[Assert\DivisibleBy(value: 15, message: 'La durée doit être un multiple de 15 minutes.')]
    private int $dureeMinutes;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private int $ordre = 0;

    /**
     * Prestations auxquelles le supplément peut s'ajouter ; aucune = toutes.
     *
     * @var Collection<int, Prestation>
     */
    #[ORM\ManyToMany(targetEntity: Prestation::class)]
    #[ORM\JoinTable(name: 'supplement_prestation')]
    #[ORM\OrderBy(['ordre' => 'ASC'])]
    private Collection $prestations;

    public function __construct(string $nom = '', int $prixCentimes = 0, int $dureeMinutes = 0)
    {
        $this->nom = trim($nom);
        $this->prixCentimes = $prixCentimes;
        $this->dureeMinutes = $dureeMinutes;
        $this->prestations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = trim($nom);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = null === $description || '' === trim($description) ? null : trim($description);

        return $this;
    }

    public function getPrixCentimes(): int
    {
        return $this->prixCentimes;
    }

    public function setPrixCentimes(int $prixCentimes): static
    {
        $this->prixCentimes = $prixCentimes;

        return $this;
    }

    public function getDureeMinutes(): int
    {
        return $this->dureeMinutes;
    }

    public function setDureeMinutes(int $dureeMinutes): static
    {
        $this->dureeMinutes = $dureeMinutes;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getOrdre(): int
    {
        return $this->ordre;
    }

    public function setOrdre(int $ordre): static
    {
        $this->ordre = $ordre;

        return $this;
    }

    /**
     * @return Collection<int, Prestation>
     */
    public function getPrestations(): Collection
    {
        return $this->prestations;
    }

    public function addPrestation(Prestation $prestation): static
    {
        if (!$this->prestations->contains($prestation)) {
            $this->prestations->add($prestation);
        }

        return $this;
    }

    public function removePrestation(Prestation $prestation): static
    {
        $this->prestations->removeElement($prestation);

        return $this;
    }

    /** Proposé pour cette prestation (actif, et prestation compatible ou aucune restriction). */
    public function estProposePour(Prestation $prestation): bool
    {
        return $this->active && ($this->prestations->isEmpty() || $this->prestations->contains($prestation));
    }

    public function __toString(): string
    {
        return $this->nom;
    }
}
