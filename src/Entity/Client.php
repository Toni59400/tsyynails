<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ClientRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Fiche cliente. Existe avec ou sans compte (User).
 *
 * Données personnelles : voir le skill tsyynails-security-rgpd
 * (notes santé chiffrées, anonymisation après 3 ans d'inactivité).
 */
#[ORM\Entity(repositoryClass: ClientRepository::class)]
#[ORM\Index(name: 'idx_client_derniere_visite', fields: ['derniereVisiteAt'])]
#[UniqueEntity(fields: ['telephone'], message: 'Une cliente existe déjà avec ce numéro.')]
class Client
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $prenom;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $nom;

    /** Format E.164 (+33612345678). Null une fois la fiche anonymisée. */
    #[ORM\Column(length: 20, unique: true, nullable: true)]
    #[Assert\Regex(pattern: '/^\+[1-9]\d{7,14}$/', message: 'Numéro de téléphone invalide.')]
    private ?string $telephone;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private ?string $email = null;

    /** Notes santé (allergies…), chiffrées par App\Service\Securite\ChiffrementDonnees. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notesSanteChiffrees = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $consentementSanteAt = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(unique: true, nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Sert au calcul de la durée de conservation RGPD. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $derniereVisiteAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $anonymiseAt = null;

    public function __construct(string $prenom, string $nom, string $telephone)
    {
        $this->prenom = trim($prenom);
        $this->nom = trim($nom);
        $this->telephone = $telephone;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPrenom(): string
    {
        return $this->prenom;
    }

    public function setPrenom(string $prenom): static
    {
        $this->prenom = trim($prenom);

        return $this;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = trim($nom);

        return $this;
    }

    public function getNomComplet(): string
    {
        return $this->prenom.' '.$this->nom;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(string $telephone): static
    {
        $this->telephone = $telephone;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = null === $email ? null : mb_strtolower(trim($email));

        return $this;
    }

    public function getNotesSanteChiffrees(): ?string
    {
        return $this->notesSanteChiffrees;
    }

    public function getConsentementSanteAt(): ?\DateTimeImmutable
    {
        return $this->consentementSanteAt;
    }

    /**
     * Les notes santé ne sont stockées qu'avec un consentement explicite.
     */
    public function enregistrerNotesSante(string $notesChiffrees, \DateTimeImmutable $consentementAt): static
    {
        $this->notesSanteChiffrees = $notesChiffrees;
        $this->consentementSanteAt = $consentementAt;

        return $this;
    }

    /**
     * Retrait du consentement : les notes sont supprimées.
     */
    public function supprimerNotesSante(): static
    {
        $this->notesSanteChiffrees = null;
        $this->consentementSanteAt = null;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDerniereVisiteAt(): ?\DateTimeImmutable
    {
        return $this->derniereVisiteAt;
    }

    public function enregistrerVisite(\DateTimeImmutable $at): static
    {
        if (null === $this->derniereVisiteAt || $at > $this->derniereVisiteAt) {
            $this->derniereVisiteAt = $at;
        }

        return $this;
    }

    public function getAnonymiseAt(): ?\DateTimeImmutable
    {
        return $this->anonymiseAt;
    }

    public function estAnonymise(): bool
    {
        return null !== $this->anonymiseAt;
    }

    /**
     * Efface l'identité de la cliente en gardant les réservations (obligation comptable).
     */
    public function anonymiser(\DateTimeImmutable $at): static
    {
        $this->prenom = 'Cliente';
        $this->nom = 'anonymisée';
        $this->telephone = null;
        $this->email = null;
        $this->supprimerNotesSante();
        $this->user = null;
        $this->anonymiseAt = $at;

        return $this;
    }
}
