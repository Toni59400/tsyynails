<?php

declare(strict_types=1);

namespace App\Service\Reservation;

use App\Entity\Client;
use App\Entity\Parametre;
use App\Entity\Prestation;
use App\Entity\Supplement;
use App\Repository\ParametreRepository;

/**
 * Montants d'une réservation, toujours calculés côté serveur.
 */
final class Tarification
{
    public function __construct(private readonly ParametreRepository $parametres)
    {
    }

    public function acomptePourcentage(): int
    {
        return $this->parametres->valeur(Parametre::ACOMPTE_POURCENTAGE);
    }

    /**
     * Prix, majoration et remise d'un rendez-vous.
     *
     * @param int|null $remiseForcee pourcentage fixé par l'admin (remplace duo et réduction à vie)
     */
    public function devis(
        Prestation $prestation,
        ?Supplement $supplement,
        ?Prestation $prestationDuo,
        \DateTimeImmutable $debut,
        ?Client $cliente,
        ?int $remiseForcee = null,
    ): Devis {
        [$pourcentage, $motif] = null !== $remiseForcee
            ? [max(0, min(100, $remiseForcee)), 'manuelle']
            : $this->remise($cliente, null !== $prestationDuo);

        return new Devis(
            $prestation->getPrixCentimes() + ($prestationDuo?->getPrixCentimes() ?? 0) + ($supplement?->getPrixCentimes() ?? 0),
            $this->majorationCentimes($debut),
            $pourcentage,
            $pourcentage > 0 ? $motif : null,
            $prestation->getDureeMinutes() + ($prestationDuo?->getDureeMinutes() ?? 0) + ($supplement?->getDureeMinutes() ?? 0),
        );
    }

    /**
     * Remise automatique : la plus avantageuse entre le duo et la réduction à vie de la cliente (pas de cumul des deux).
     *
     * @return array{0: int, 1: ?string} pourcentage et motif (duo, privilege)
     */
    public function remise(?Client $cliente, bool $duo): array
    {
        $duoPourcentage = $duo ? $this->parametres->valeur(Parametre::DUO_REDUCTION_POURCENTAGE) : 0;
        $privilege = $cliente?->getPrivilegePourcentage() ?? 0;
        if (0 === $duoPourcentage && 0 === $privilege) {
            return [0, null];
        }

        return $duoPourcentage >= $privilege ? [$duoPourcentage, 'duo'] : [$privilege, 'privilege'];
    }

    /** Majoration du soir si le rendez-vous COMMENCE à l'heure réglée ou plus tard. */
    public function majorationCentimes(\DateTimeImmutable $debut): int
    {
        $heure = $this->heureMajoration();

        return null !== $heure && (int) $debut->format('G') >= $heure ? $this->parametres->valeur(Parametre::MAJORATION_SOIR_EUROS) * 100 : 0;
    }

    /** Heure de début de la majoration du soir, ou null si aucune majoration n'est réglée. */
    public function heureMajoration(): ?int
    {
        return $this->parametres->valeur(Parametre::MAJORATION_SOIR_EUROS) > 0 ? $this->parametres->valeur(Parametre::MAJORATION_SOIR_HEURE) : null;
    }

    /**
     * Modes de paiement proposés dans la réservation en ligne (réglages de l'admin), carte en premier.
     *
     * @return list<string> Reservation::MODE_CARTE et/ou Reservation::MODE_ESPECES ; vide = réservation en ligne fermée
     */
    public function modesPaiementEnLigne(): array
    {
        return array_values(array_filter([
            1 === $this->parametres->valeur(Parametre::PAIEMENT_CARTE_ACTIF) ? \App\Entity\Reservation::MODE_CARTE : null,
            1 === $this->parametres->valeur(Parametre::PAIEMENT_ESPECES_ACTIF) ? \App\Entity\Reservation::MODE_ESPECES : null,
        ]));
    }

    public function duoActif(): bool
    {
        return 1 === $this->parametres->valeur(Parametre::DUO_ACTIF);
    }

    /** Acompte sur le montant à payer après remise et récompense de points. */
    public function acompte(Devis $devis, int $reductionCentimes = 0): int
    {
        return self::calculerAcompte($devis->aPayerCentimes() - $reductionCentimes, $this->acomptePourcentage());
    }

    /** Arrondi à l'euro supérieur, sans dépasser le prix. */
    public static function calculerAcompte(int $prixCentimes, int $pourcentage): int
    {
        $acompte = (int) ceil($prixCentimes * $pourcentage / 100 / 100) * 100;

        return min($prixCentimes, max(0, $acompte));
    }
}
