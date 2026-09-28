<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\HoraireOuvertureRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une plage d'ouverture. Plusieurs plages le même jour = une pause entre elles.
 */
#[ORM\Entity(repositoryClass: HoraireOuvertureRepository::class)]
class HoraireOuverture
{
    public const JOURS = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Jour ISO-8601 : 1 = lundi … 7 = dimanche. */
    #[ORM\Column(type: Types::SMALLINT)]
    #[Assert\Range(min: 1, max: 7)]
    private int $jourSemaine;

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    private \DateTimeImmutable $heureDebut;

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    #[Assert\GreaterThan(propertyPath: 'heureDebut', message: "L'heure de fin doit être après l'heure de début.")]
    private \DateTimeImmutable $heureFin;

    public function __construct(int $jourSemaine, \DateTimeImmutable $heureDebut, \DateTimeImmutable $heureFin)
    {
        $this->jourSemaine = $jourSemaine;
        $this->heureDebut = $heureDebut;
        $this->heureFin = $heureFin;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJourSemaine(): int
    {
        return $this->jourSemaine;
    }

    public function setJourSemaine(int $jourSemaine): static
    {
        $this->jourSemaine = $jourSemaine;

        return $this;
    }

    public function getHeureDebut(): \DateTimeImmutable
    {
        return $this->heureDebut;
    }

    public function setHeureDebut(\DateTimeImmutable $heureDebut): static
    {
        $this->heureDebut = $heureDebut;

        return $this;
    }

    public function getHeureFin(): \DateTimeImmutable
    {
        return $this->heureFin;
    }

    public function setHeureFin(\DateTimeImmutable $heureFin): static
    {
        $this->heureFin = $heureFin;

        return $this;
    }
}
