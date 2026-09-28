<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

trait CreationUtilisateurTrait
{
    private const MOT_DE_PASSE = 'mot-de-passe-de-test';
    private const SECRET_TOTP = 'JBSWY3DPEHPK3PXP';

    /**
     * @param list<string> $roles
     */
    private function creerUtilisateur(string $email, array $roles = [], bool $totpActive = false): User
    {
        $container = static::getContainer();
        $user = (new User($email))->setRoles($roles);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::MOT_DE_PASSE));
        if ($totpActive) {
            $user->activerTotp(self::SECRET_TOTP, new \DateTimeImmutable());
        }

        $entityManager = $container->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }
}
