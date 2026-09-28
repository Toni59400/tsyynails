<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[UniqueEntity(fields: ['email'], message: 'Un compte existe déjà avec cette adresse email.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, TwoFactorInterface
{
    public const ROLE_CLIENT = 'ROLE_CLIENT';
    public const ROLE_ADMIN = 'ROLE_ADMIN';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private string $email;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    #[ORM\Column]
    private string $password = '';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Secret TOTP (base32) de l'application d'authentification. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $totpSecret = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $totpActiveAt = null;

    /** Adresse email confirmée par le lien envoyé à l'inscription. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailVerifieAt = null;

    public function __construct(string $email)
    {
        $this->email = mb_strtolower(trim($email));
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = mb_strtolower(trim($email));

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = self::ROLE_CLIENT;

        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function isAdmin(): bool
    {
        return \in_array(self::ROLE_ADMIN, $this->getRoles(), true);
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    /**
     * Reçoit uniquement un mot de passe déjà haché (UserPasswordHasherInterface).
     */
    public function setPassword(string $motDePasseHache): static
    {
        $this->password = $motDePasseHache;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public static function configurationTotp(string $secret): TotpConfiguration
    {
        return new TotpConfiguration($secret, TotpConfiguration::ALGORITHM_SHA1, 30, 6);
    }

    public function isTotpAuthenticationEnabled(): bool
    {
        return null !== $this->totpSecret && null !== $this->totpActiveAt;
    }

    public function getTotpAuthenticationUsername(): string
    {
        return $this->email;
    }

    public function getTotpAuthenticationConfiguration(): ?TotpConfigurationInterface
    {
        return null === $this->totpSecret ? null : self::configurationTotp($this->totpSecret);
    }

    /**
     * Le secret n'est enregistré qu'après vérification d'un premier code.
     */
    public function activerTotp(string $secret, \DateTimeImmutable $at): void
    {
        $this->totpSecret = $secret;
        $this->totpActiveAt = $at;
    }

    public function desactiverTotp(): void
    {
        $this->totpSecret = null;
        $this->totpActiveAt = null;
    }

    /**
     * Évite de stocker le vrai hash dans la session (Symfony 7.3+).
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0password"] = hash('crc32c', $this->password);

        return $data;
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
        // Aucun mot de passe en clair n'est stocké sur l'entité.
    }

    public function getEmailVerifieAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifieAt;
    }

    public function isEmailVerifie(): bool
    {
        return null !== $this->emailVerifieAt;
    }

    public function verifierEmail(\DateTimeImmutable $at): void
    {
        $this->emailVerifieAt ??= $at;
    }

    public function __toString(): string
    {
        return $this->email;
    }
}
