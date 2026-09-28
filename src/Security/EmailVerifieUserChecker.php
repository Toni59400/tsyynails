<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Une cliente ne se connecte qu'après avoir confirmé son email : un compte créé avec
 * l'adresse de quelqu'un d'autre reste inutilisable. Les comptes admin (créés en ligne
 * de commande, protégés par la double authentification) ne sont pas concernés.
 */
final class EmailVerifieUserChecker implements UserCheckerInterface
{
    public const MESSAGE = 'Confirmez d\'abord votre adresse email avec le lien reçu.';

    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof User && !$user->isAdmin() && !$user->isEmailVerifie()) {
            throw new CustomUserMessageAccountStatusException(self::MESSAGE);
        }
    }

    public function checkPostAuth(UserInterface $user, ?\Symfony\Component\Security\Core\Authentication\Token\TokenInterface $token = null): void
    {
    }
}
