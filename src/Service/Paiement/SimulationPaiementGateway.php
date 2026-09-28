<?php

declare(strict_types=1);

namespace App\Service\Paiement;

/**
 * Paiement simulé pour le développement et les tests, quand aucune clé Stripe n'est configurée.
 * Jamais utilisé en production (voir FabriquePaiementGateway).
 */
final class SimulationPaiementGateway implements PaiementGateway
{
    public const PREFIXE = 'sim_';

    /** @var list<string> opérations effectuées, pour les tests */
    public array $journal = [];

    public function creerEmpreinte(int $montantCentimes, string $description, array $metadonnees): Empreinte
    {
        $identifiant = self::PREFIXE.bin2hex(random_bytes(8));
        $this->journal[] = 'creer:'.$identifiant.':'.$montantCentimes;

        return new Empreinte($identifiant, $identifiant.'_secret');
    }

    public function statut(string $identifiant): string
    {
        return self::STATUT_AUTORISEE;
    }

    public function secretClient(string $identifiant): string
    {
        return $identifiant.'_secret';
    }

    public function capturer(string $identifiant): void
    {
        $this->journal[] = 'capturer:'.$identifiant;
    }

    public function rembourser(string $identifiant): void
    {
        $this->journal[] = 'rembourser:'.$identifiant;
    }

    public function annuler(string $identifiant): void
    {
        $this->journal[] = 'annuler:'.$identifiant;
    }

    public function estSimulation(): bool
    {
        return true;
    }

    public function clePublique(): ?string
    {
        return null;
    }
}
