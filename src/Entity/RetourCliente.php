<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RetourClienteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Réponse au questionnaire de satisfaction (affiche QR code du salon). Lue seulement par la prothésiste.
 * L'email saisi n'est pas conservé : il sert uniquement à retrouver la fiche cliente.
 */
#[ORM\Entity(repositoryClass: RetourClienteRepository::class)]
#[ORM\Index(columns: ['created_at'])]
class RetourCliente
{
    public const RECOMMANDE_OUI = 'oui';
    public const RECOMMANDE_PEUT_ETRE = 'peut_etre';
    public const RECOMMANDE_NON = 'non';

    public const RECOMMANDATIONS = [
        self::RECOMMANDE_OUI => 'Oui, sans hésiter',
        self::RECOMMANDE_PEUT_ETRE => 'Peut-être',
        self::RECOMMANDE_NON => 'Non',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Fiche retrouvée grâce à l'email ; null si aucune cliente ne correspond (réponse anonyme). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Client $client;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $note;

    #[ORM\Column(length: 12)]
    private string $recommande;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $commentaire;

    #[ORM\Column]
    private int $pointsCredites;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(?Client $client, int $note, string $recommande, ?string $commentaire, int $pointsCredites, \DateTimeImmutable $createdAt)
    {
        $this->client = $client;
        $this->note = max(1, min(5, $note));
        $this->recommande = $recommande;
        $commentaire = null === $commentaire ? '' : trim($commentaire);
        $this->commentaire = '' === $commentaire ? null : $commentaire;
        $this->pointsCredites = $pointsCredites;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function getNote(): int
    {
        return $this->note;
    }

    public function getRecommande(): string
    {
        return $this->recommande;
    }

    public function getRecommandeLibelle(): string
    {
        return self::RECOMMANDATIONS[$this->recommande] ?? $this->recommande;
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    public function getPointsCredites(): int
    {
        return $this->pointsCredites;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
