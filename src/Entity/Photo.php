<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PhotoRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Photo de la galerie : réalisation liée à une prestation, photo d'inspiration classée par thèmes, ou les deux.
 */
#[ORM\Entity(repositoryClass: PhotoRepository::class)]
#[ORM\Index(name: 'idx_photo_publiee_ordre', fields: ['publiee', 'ordre'])]
class Photo
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Nom du fichier stocké, régénéré à l'upload. */
    #[ORM\Column(length: 255)]
    private string $fichier;

    /** Sert aussi de texte alternatif (accessibilité). */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Décrivez la réalisation (texte lu par les lecteurs d\'écran).')]
    #[Assert\Length(max: 255)]
    private string $legende;

    #[ORM\Column]
    private int $ordre = 0;

    #[ORM\Column]
    private bool $publiee = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Prestation illustrée par cette photo (réalisation). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Prestation $prestation = null;

    /** @var Collection<int, Inspiration> */
    #[ORM\ManyToMany(targetEntity: Inspiration::class, inversedBy: 'photos')]
    #[ORM\JoinTable(name: 'photo_inspiration')]
    private Collection $inspirations;

    public function __construct(string $fichier, string $legende)
    {
        $this->fichier = $fichier;
        $this->legende = $legende;
        $this->createdAt = new \DateTimeImmutable();
        $this->inspirations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFichier(): string
    {
        return $this->fichier;
    }

    public function setFichier(string $fichier): static
    {
        $this->fichier = $fichier;

        return $this;
    }

    public function getLegende(): string
    {
        return $this->legende;
    }

    public function setLegende(string $legende): static
    {
        $this->legende = $legende;

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

    public function isPubliee(): bool
    {
        return $this->publiee;
    }

    public function setPubliee(bool $publiee): static
    {
        $this->publiee = $publiee;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPrestation(): ?Prestation
    {
        return $this->prestation;
    }

    public function setPrestation(?Prestation $prestation): static
    {
        $this->prestation = $prestation;

        return $this;
    }

    /**
     * @return Collection<int, Inspiration>
     */
    public function getInspirations(): Collection
    {
        return $this->inspirations;
    }

    public function addInspiration(Inspiration $inspiration): static
    {
        if (!$this->inspirations->contains($inspiration)) {
            $this->inspirations->add($inspiration);
        }

        return $this;
    }

    public function removeInspiration(Inspiration $inspiration): static
    {
        $this->inspirations->removeElement($inspiration);

        return $this;
    }

    public function __toString(): string
    {
        return $this->legende;
    }
}
