<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CarteFideliteRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Carte physique imprimée avec un QR code contenant uniquement le jeton.
 *
 * Les cartes sont générées à l'avance, puis associées à une cliente
 * par la prothésiste en scannant le QR code depuis l'admin.
 * Une carte perdue est désactivée et remplacée par une nouvelle.
 */
#[ORM\Entity(repositoryClass: CarteFideliteRepository::class)]
class CarteFidelite
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Jeton aléatoire opaque (144 bits, base64url). Ne contient aucune donnée personnelle. */
    #[ORM\Column(length: 32, unique: true)]
    private string $jeton;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Client $client = null;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $associeeAt = null;

    private function __construct(string $jeton)
    {
        $this->jeton = $jeton;
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function generer(): self
    {
        return new self(rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '='));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJeton(): string
    {
        return $this->jeton;
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function associerA(Client $client, \DateTimeImmutable $at): void
    {
        if (null !== $this->client) {
            throw new \LogicException('Cette carte est déjà associée à une cliente.');
        }
        if (!$this->active) {
            throw new \LogicException('Cette carte est désactivée.');
        }

        $this->client = $client;
        $this->associeeAt = $at;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function desactiver(): void
    {
        $this->active = false;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getAssocieeAt(): ?\DateTimeImmutable
    {
        return $this->associeeAt;
    }
}
