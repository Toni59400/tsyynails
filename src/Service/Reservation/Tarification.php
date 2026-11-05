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
     * @param int|null $remiseForcee pourcentage fixé par l'admin (remplace duo et réduction VIP)
     */
    public function devis(
        Prestation $prestation,
        ?Supplement $supplement,
        ?Prestation $prestationDuo,
        \DateTimeImmutable $debut,
        ?Client $cliente,
        ?int $remiseForcee = null,
        ?Reconstruction $reconstruction = null,
    ): Devis {
        [$pourcentage, $motif] = null !== $remiseForcee
            ? [max(0, min(100, $remiseForcee)), 'manuelle']
            : $this->remise($cliente, null !== $prestationDuo);

        return new Devis(
            $prestation->getPrixCentimes() + ($prestationDuo?->getPrixCentimes() ?? 0) + ($supplement?->getPrixCentimes() ?? 0) + ($reconstruction->prixCentimes ?? 0),
            $this->majorationCentimes($debut),
            $pourcentage,
            $pourcentage > 0 ? $motif : null,
            $prestation->getDureeMinutes() + ($prestationDuo?->getDureeMinutes() ?? 0) + ($supplement?->getDureeMinutes() ?? 0) + ($reconstruction->minutes ?? 0),
            $reconstruction,
        );
    }

    /** Option « Reconstruction d'ongles » proposée en ligne (réglages). */
    public function reconstructionActive(): bool
    {
        return 1 === $this->parametres->valeur(Parametre::RECONSTRUCTION_ACTIVE);
    }

    /**
     * Reconstruction de ce nombre d'ongles au tarif en vigueur ; null si l'option est désactivée ou le nombre hors limites.
     */
    public function reconstruction(int $ongles): ?Reconstruction
    {
        if (!$this->reconstructionActive() || $ongles < 1 || $ongles > $this->parametres->valeur(Parametre::RECONSTRUCTION_MAX_ONGLES)) {
            return null;
        }

        // Tarif dégressif par tranches : chaque ongle est payé au prix de la tranche où il tombe
        // (ex. 5 € les 3 premiers, 4 € du 4e au 6e, 3 € ensuite) : le total augmente toujours, le prix moyen baisse.
        $prix = 0;
        $grille = $this->grilleReconstruction();
        for ($numero = 1; $numero <= $ongles; ++$numero) {
            $prixOngle = 0;
            foreach ($grille as $tranche) {
                if ($numero >= $tranche['des']) {
                    $prixOngle = $tranche['prix_centimes'];
                }
            }
            $prix += $prixOngle;
        }

        return new Reconstruction(
            $ongles,
            $prix,
            $ongles * $this->parametres->valeur(Parametre::RECONSTRUCTION_MINUTES_ONGLE),
        );
    }

    /**
     * Tranches du tarif de reconstruction, dans l'ordre : à partir de quel ongle, et à quel prix.
     * Un palier à 0 (ou pas après le précédent) est ignoré.
     *
     * @return list<array{des: int, prix_centimes: int}>
     */
    public function grilleReconstruction(): array
    {
        $grille = [['des' => 1, 'prix_centimes' => $this->parametres->valeur(Parametre::RECONSTRUCTION_PRIX_ONGLE) * 100]];
        foreach ([[Parametre::RECONSTRUCTION_PALIER2_DES, Parametre::RECONSTRUCTION_PALIER2_PRIX], [Parametre::RECONSTRUCTION_PALIER3_DES, Parametre::RECONSTRUCTION_PALIER3_PRIX]] as [$cleDes, $clePrix]) {
            $des = $this->parametres->valeur($cleDes);
            if ($des > end($grille)['des']) {
                $grille[] = ['des' => $des, 'prix_centimes' => $this->parametres->valeur($clePrix) * 100];
            }
        }

        return $grille;
    }

    /**
     * Choix proposés en ligne : 1 à N ongles, avec prix et durée (vide si l'option est désactivée).
     *
     * @return list<Reconstruction>
     */
    public function choixReconstruction(): array
    {
        $choix = [];
        for ($ongles = 1; $ongles <= $this->parametres->valeur(Parametre::RECONSTRUCTION_MAX_ONGLES); ++$ongles) {
            $reconstruction = $this->reconstruction($ongles);
            if (null !== $reconstruction) {
                $choix[] = $reconstruction;
            }
        }

        return $choix;
    }

    /**
     * Remise automatique : la plus avantageuse entre le duo et la réduction VIP de la cliente (pas de cumul des deux).
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
