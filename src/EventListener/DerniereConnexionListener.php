<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Dernière connexion : une cliente qui se connecte garde son compte (durée de conservation RGPD).
 */
#[AsEventListener]
final class DerniereConnexionListener
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(LoginSuccessEvent $evenement): void
    {
        $user = $evenement->getUser();
        if ($user instanceof User) {
            $user->enregistrerConnexion($this->clock->now());
            $this->entityManager->flush();
        }
    }
}
