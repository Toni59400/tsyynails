<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TypeRecompense;
use App\Repository\RecompenseFideliteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Palier du programme de fidélité : une récompense contre un nombre de points.
 */
#[ORM\Entity(repositoryClass: RecompenseFideliteRepository::class)]
class RecompenseFidelite
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    private string $nom;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 300)]
    private ?string $description = null;

    #[ORM\Column]
    #[Assert\Positive]
    private int $seuilPoints;

    #[ORM\Column(length: 20, enumType: TypeRecompense::class)]
    private TypeRecompense $type;

    /** Réduction appliquée (type réduction) ou valeur affichée de l'avantage (type au salon). */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $valeurCentimes;

    #[ORM\Column]
    private bool $active = true;

    public function __construct(string $nom, int $seuilPoints, TypeRecompense $type, int $valeurCentimes)
    {
        $this->nom = $nom;
        $this->seuilPoints = $seuilPoints;
        $this->type = $type;
        $this->valeurCentimes = $valeurCentimes;
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

    public function getSeuilPoints(): int
    {
        return $this->seuilPoints;
    }

    public function setSeuilPoints(int $seuilPoints): static
    {
        $this->seuilPoints = $seuilPoints;

        return $this;
    }

    public function getType(): TypeRecompense
    {
        return $this->type;
    }

    public function setType(TypeRecompense $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getValeurCentimes(): int
    {
        return $this->valeurCentimes;
    }

    public function setValeurCentimes(int $valeurCentimes): static
    {
        $this->valeurCentimes = $valeurCentimes;

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

    public function estUtilisableEnLigne(): bool
    {
        return TypeRecompense::REDUCTION === $this->type;
    }

    public function __toString(): string
    {
        return \sprintf('%s (%d points)', $this->nom, $this->seuilPoints);
    }
}
