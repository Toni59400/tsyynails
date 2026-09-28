<?php

declare(strict_types=1);

namespace App\Service\Paiement;

/**
 * Empreinte bancaire de l'acompte (PaymentIntent Stripe en capture manuelle).
 * Aucune donnée de carte ne passe par le serveur : la cliente la saisit dans Stripe Elements.
 */
interface PaiementGateway
{
    /** L'empreinte est autorisée et attend d'être débitée ou libérée. */
    public const STATUT_AUTORISEE = 'requires_capture';

    /**
     * @param array<string, string> $metadonnees identifiants techniques uniquement, jamais de donnée personnelle
     */
    public function creerEmpreinte(int $montantCentimes, string $description, array $metadonnees): Empreinte;

    /** Statut Stripe du PaymentIntent (requires_payment_method, requires_capture, canceled…). */
    public function statut(string $identifiant): string;

    /** Secret à transmettre à Stripe Elements (page de paiement rechargée). */
    public function secretClient(string $identifiant): string;

    /** Débite l'acompte bloqué (validation de la demande). */
    public function capturer(string $identifiant): void;

    /** Libère l'empreinte sans rien débiter (refus, expiration, annulation). */
    public function annuler(string $identifiant): void;

    /** Vrai en développement sans clés Stripe : aucune carte n'est demandée. */
    public function estSimulation(): bool;

    public function clePublique(): ?string;
}
