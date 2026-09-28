<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IndisponibiliteRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Congés, fermeture exceptionnelle, rendez-vous personnel : aucun créneau proposé sur la période.
 */
#[ORM\Entity(repositoryClass: IndisponibiliteRepository::class)]
#[ORM\Index(name: 'idx_indisponibilite_periode', fields: ['debut', 'fin'])]
class Indisponibilite
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $debut;

    #[ORM\Column]
    #[Assert\GreaterThan(propertyPath: 'debut', message: 'La fin doit être après le début.')]
    private \DateTimeImmutable $fin;

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $motif = null;

    public function __construct(\DateTimeImmutable $debut, \DateTimeImmutable $fin, ?string $motif = null)
    {
        $this->debut = $debut;
        $this->fin = $fin;
        $this->motif = $motif;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDebut(): \DateTimeImmutable
    {
        return $this->debut;
    }

    public function setDebut(\DateTimeImmutable $debut): static
    {
        $this->debut = $debut;

        return $this;
    }

    public function getFin(): \DateTimeImmutable
    {
        return $this->fin;
    }

    public function setFin(\DateTimeImmutable $fin): static
    {
        $this->fin = $fin;

        return $this;
    }

    public function getMotif(): ?string
    {
        return $this->motif;
    }

    public function setMotif(?string $motif): static
    {
        $this->motif = $motif;

        return $this;
    }
}
