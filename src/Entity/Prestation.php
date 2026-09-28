<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PrestationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PrestationRepository::class)]
class Prestation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $nom;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $prixCentimes;

    #[ORM\Column]
    #[Assert\Positive]
    #[Assert\DivisibleBy(value: 15, message: 'La durée doit être un multiple de 15 minutes.')]
    private int $dureeMinutes;

    /** Points de fidélité gagnés quand la prestation est honorée. */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $points = 0;

    #[ORM\Column]
    private bool $active = true;

    /** Ordre d'affichage sur le site. */
    #[ORM\Column]
    private int $ordre = 0;

    public function __construct(string $nom, int $prixCentimes, int $dureeMinutes)
    {
        $this->nom = $nom;
        $this->prixCentimes = $prixCentimes;
        $this->dureeMinutes = $dureeMinutes;
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
        $this->nom = $nom;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

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

    public function getPoints(): int
    {
        return $this->points;
    }

    public function setPoints(int $points): static
    {
        $this->points = $points;

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

    public function __toString(): string
    {
        return $this->nom;
    }
}
