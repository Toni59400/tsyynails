<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InspirationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Thème de photos d'inspiration (« Inspiration été », « Mariage »…) affiché dans la galerie.
 */
#[ORM\Entity(repositoryClass: InspirationRepository::class)]
#[UniqueEntity(fields: ['slug'], message: 'Un thème porte déjà ce nom.')]
class Inspiration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    private string $nom;

    /** Identifiant lisible dans l'URL de la galerie (?theme=inspiration-ete). */
    #[ORM\Column(length: 100, unique: true)]
    private string $slug;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $description = null;

    #[ORM\Column]
    private int $ordre = 0;

    #[ORM\Column]
    private bool $publiee = true;

    /** @var Collection<int, Photo> */
    #[ORM\ManyToMany(targetEntity: Photo::class, mappedBy: 'inspirations')]
    #[ORM\OrderBy(['ordre' => 'ASC'])]
    private Collection $photos;

    public function __construct(string $nom)
    {
        $this->setNom($nom);
        $this->photos = new ArrayCollection();
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
        $this->slug = (new AsciiSlugger('fr'))->slug($this->nom)->lower()->toString();

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
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

    /**
     * @return Collection<int, Photo>
     */
    public function getPhotos(): Collection
    {
        return $this->photos;
    }

    public function __toString(): string
    {
        return $this->nom;
    }
}
