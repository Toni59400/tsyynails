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

    /** Supplément offert (type « supplément offert ») : gratuit quand la cliente le réserve avec cette récompense. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Supplement $supplement = null;

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

    public function getSupplement(): ?Supplement
    {
        return $this->supplement;
    }

    public function setSupplement(?Supplement $supplement): static
    {
        $this->supplement = $supplement;

        return $this;
    }

    public function estUtilisableEnLigne(): bool
    {
        return TypeRecompense::REDUCTION === $this->type
            || (TypeRecompense::SUPPLEMENT_OFFERT === $this->type && null !== $this->supplement);
    }

    /** Montant déduit : la réduction, ou le prix du supplément offert. */
    public function valeurDeduite(): int
    {
        return TypeRecompense::SUPPLEMENT_OFFERT === $this->type ? ($this->supplement?->getPrixCentimes() ?? 0) : $this->valeurCentimes;
    }

    #[Assert\Callback]
    public function validerSupplement(\Symfony\Component\Validator\Context\ExecutionContextInterface $contexte): void
    {
        if (TypeRecompense::SUPPLEMENT_OFFERT === $this->type && null === $this->supplement) {
            $contexte->buildViolation('Choisissez le supplément offert.')->atPath('supplement')->addViolation();
        }
    }

    public function __toString(): string
    {
        return \sprintf('%s (%d points)', $this->nom, $this->seuilPoints);
    }
}
