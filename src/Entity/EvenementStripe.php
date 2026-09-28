<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EvenementStripeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Événement de webhook Stripe déjà traité : garantit un traitement unique
 * même si Stripe renvoie le même événement plusieurs fois.
 */
#[ORM\Entity(repositoryClass: EvenementStripeRepository::class, readOnly: true)]
class EvenementStripe
{
    #[ORM\Id]
    #[ORM\Column(length: 255)]
    private string $id;

    #[ORM\Column(length: 100)]
    private string $type;

    #[ORM\Column]
    private \DateTimeImmutable $recuAt;

    public function __construct(string $id, string $type)
    {
        $this->id = $id;
        $this->type = $type;
        $this->recuAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getRecuAt(): \DateTimeImmutable
    {
        return $this->recuAt;
    }
}
