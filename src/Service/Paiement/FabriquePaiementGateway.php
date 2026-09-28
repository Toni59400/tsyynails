<?php

declare(strict_types=1);

namespace App\Service\Paiement;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Stripe dès que les clés sont configurées ; sinon simulation, sauf en production
 * où l'absence de clés est une erreur (jamais de réservation sans acompte).
 */
final class FabriquePaiementGateway
{
    public function __construct(
        #[Autowire(env: 'STRIPE_SECRET_KEY')] private readonly string $cleSecrete,
        #[Autowire(env: 'STRIPE_PUBLIC_KEY')] private readonly string $clePublique,
        #[Autowire('%kernel.environment%')] private readonly string $environnement,
    ) {
    }

    public function creer(): PaiementGateway
    {
        if ('' !== $this->cleSecrete) {
            return new StripePaiementGateway($this->cleSecrete, $this->clePublique);
        }

        if ('prod' === $this->environnement) {
            throw new \LogicException('Clés Stripe manquantes : renseignez STRIPE_SECRET_KEY et STRIPE_PUBLIC_KEY.');
        }

        return new SimulationPaiementGateway();
    }
}
