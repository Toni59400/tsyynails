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

    /** Code personnel à partager (lien /parrainage/CODE). Aucune donnée personnelle. */
    #[ORM\Column(length: 12, unique: true, nullable: true)]
    private ?string $codeParrainage = null;

    /** Cliente qui l'a parrainée (nouvelle cliente uniquement). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Client $marraine = null;

    /** Points de parrainage versés (premier rendez-vous honoré) : une seule fois. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $parrainageRecompenseAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Sert au calcul de la durée de conservation RGPD. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $derniereVisiteAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $anonymiseAt = null;

    /** Dernier email « vos points vont expirer », pour ne l'envoyer qu'une fois. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $avertissementPointsAt = null;

    /** Dernier email « votre avis compte », au plus un par an. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $demandeAvisAt = null;

    /** La cliente ne souhaite plus recevoir de demande d'avis (lien de l'email). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $refusDemandesAvisAt = null;

    /** Réduction à vie en cours (10, 15, 20 %…), 0 = aucune. */
    #[ORM\Column]
    private int $privilegePourcentage = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $privilegeDepuis = null;

    /** Anniversaire où l'on vérifie le nombre de rendez-vous de l'année écoulée. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $privilegeRevisionAt = null;

    /** Réduction perdue à cette date : seuls les points gagnés depuis comptent pour la regagner. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $privilegePerduAt = null;

    /** Email « il vous manque des rendez-vous » déjà envoyé pour la révision en cours. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $privilegeAvertiAt = null;

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

    public function __toString(): string
    {
        return $this->getNomComplet();
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
        $this->email = null === $email || '' === trim($email) ? null : mb_strtolower(trim($email));

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
        $this->codeParrainage = null;
        $this->retirerPrivilege($at);
        $this->anonymiseAt = $at;

        return $this;
    }

    public function getAvertissementPointsAt(): ?\DateTimeImmutable
    {
        return $this->avertissementPointsAt;
    }

    public function marquerAvertissementPoints(\DateTimeImmutable $at): void
    {
        $this->avertissementPointsAt = $at;
    }

    public function getPrivilegePourcentage(): int
    {
        return $this->privilegePourcentage;
    }

    public function getPrivilegeDepuis(): ?\DateTimeImmutable
    {
        return $this->privilegeDepuis;
    }

    public function getPrivilegeRevisionAt(): ?\DateTimeImmutable
    {
        return $this->privilegeRevisionAt;
    }

    public function getPrivilegeAvertiAt(): ?\DateTimeImmutable
    {
        return $this->privilegeAvertiAt;
    }

    public function accorderPrivilege(int $pourcentage, \DateTimeImmutable $at): void
    {
        $this->privilegePourcentage = $pourcentage;
        $this->privilegeDepuis = $at;
        $this->privilegeRevisionAt = $at->modify('+1 year');
        $this->privilegeAvertiAt = null;
    }

    /** Année confirmée : nouveau pourcentage, prochaine révision dans un an. */
    public function renouvelerPrivilege(int $pourcentage): void
    {
        $this->privilegePourcentage = $pourcentage;
        $this->privilegeRevisionAt = $this->privilegeRevisionAt?->modify('+1 year');
        $this->privilegeAvertiAt = null;
    }

    public function getPrivilegePerduAt(): ?\DateTimeImmutable
    {
        return $this->privilegePerduAt;
    }

    public function retirerPrivilege(\DateTimeImmutable $at): void
    {
        $this->privilegePerduAt = $this->privilegePourcentage > 0 ? $at : $this->privilegePerduAt;
        $this->privilegePourcentage = 0;
        $this->privilegeDepuis = null;
        $this->privilegeRevisionAt = null;
        $this->privilegeAvertiAt = null;
    }

    public function marquerPrivilegeAverti(\DateTimeImmutable $at): void
    {
        $this->privilegeAvertiAt = $at;
    }

    public function getDemandeAvisAt(): ?\DateTimeImmutable
    {
        return $this->demandeAvisAt;
    }

    public function marquerDemandeAvis(\DateTimeImmutable $at): void
    {
        $this->demandeAvisAt = $at;
    }

    public function refuseDemandesAvis(): bool
    {
        return null !== $this->refusDemandesAvisAt;
    }

    public function refuserDemandesAvis(\DateTimeImmutable $at): void
    {
        $this->refusDemandesAvisAt ??= $at;
    }

    public function getCodeParrainage(): ?string
    {
        return $this->codeParrainage;
    }

    public function attribuerCodeParrainage(string $code): void
    {
        $this->codeParrainage ??= $code;
    }

    public function getMarraine(): ?Client
    {
        return $this->marraine;
    }

    /**
     * Seule une nouvelle cliente (jamais venue, pas encore parrainée) peut être parrainée, et pas par elle-même.
     */
    public function peutEtreParraineePar(Client $marraine): bool
    {
        return null === $this->marraine
            && null === $this->derniereVisiteAt
            && $marraine !== $this
            && !$marraine->estAnonymise()
            && (null === $this->telephone || $this->telephone !== $marraine->getTelephone())
            && (null === $this->email || $this->email !== $marraine->getEmail());
    }

    public function definirMarraine(Client $marraine): void
    {
        if (!$this->peutEtreParraineePar($marraine)) {
            throw new \LogicException('Parrainage impossible pour cette cliente.');
        }

        $this->marraine = $marraine;
    }

    public function getParrainageRecompenseAt(): ?\DateTimeImmutable
    {
        return $this->parrainageRecompenseAt;
    }

    public function marquerParrainageRecompense(\DateTimeImmutable $at): void
    {
        $this->parrainageRecompenseAt = $at;
    }
}
