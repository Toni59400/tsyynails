<?php

declare(strict_types=1);

namespace App\Service\Paiement;

use Stripe\StripeClient;

final class StripePaiementGateway implements PaiementGateway
{
    private readonly StripeClient $stripe;

    public function __construct(string $cleSecrete, private readonly string $clePublique)
    {
        $this->stripe = new StripeClient($cleSecrete);
    }

    public function creerEmpreinte(int $montantCentimes, string $description, array $metadonnees): Empreinte
    {
        $intention = $this->stripe->paymentIntents->create([
            'amount' => $montantCentimes,
            'currency' => 'eur',
            'capture_method' => 'manual',
            'payment_method_types' => ['card'],
            'description' => $description,
            'metadata' => $metadonnees,
        ]);

        return new Empreinte($intention->id, (string) $intention->client_secret);
    }

    public function statut(string $identifiant): string
    {
        return $this->stripe->paymentIntents->retrieve($identifiant)->status;
    }

    public function secretClient(string $identifiant): string
    {
        return (string) $this->stripe->paymentIntents->retrieve($identifiant)->client_secret;
    }

    public function capturer(string $identifiant): void
    {
        $this->stripe->paymentIntents->capture($identifiant);
    }

    public function rembourser(string $identifiant): void
    {
        // Clé d'idempotence : un double clic ou une relance ne rembourse jamais deux fois.
        $this->stripe->refunds->create(['payment_intent' => $identifiant], ['idempotency_key' => 'remboursement-'.$identifiant]);
    }

    public function annuler(string $identifiant): void
    {
        $intention = $this->stripe->paymentIntents->retrieve($identifiant);
        // Déjà libérée ou jamais autorisée : rien à faire (idempotent).
        if (\in_array($intention->status, ['canceled', 'succeeded'], true)) {
            return;
        }

        $this->stripe->paymentIntents->cancel($identifiant);
    }

    public function estSimulation(): bool
    {
        return false;
    }

    public function clePublique(): string
    {
        return $this->clePublique;
    }
}
