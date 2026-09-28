<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PhotoRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Réalisation affichée dans la galerie du site vitrine.
 */
#[ORM\Entity(repositoryClass: PhotoRepository::class)]
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

    public function __construct(string $fichier, string $legende)
    {
        $this->fichier = $fichier;
        $this->legende = $legende;
        $this->createdAt = new \DateTimeImmutable();
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
}
