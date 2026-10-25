<?php

declare(strict_types=1);

namespace App\Service\Reservation;

use App\Entity\Prestation;
use App\Entity\Reservation;
use App\Entity\Supplement;

/**
 * Lignes du détail de prix affichées à la cliente (récapitulatif, paiement, suivi, emails) :
 * prestations, supplément, majoration du soir, remise, récompense de points.
 * Les montants négatifs sont des réductions.
 */
final class DetailPrix
{
    public const LIBELLES_REMISE = [
        'duo' => 'Remise RDV duo',
        'privilege' => 'Réduction fidélité à vie',
        'manuelle' => 'Remise',
    ];

    /**
     * @return list<array{libelle: string, montant: int}>
     */
    public static function pourReservation(Reservation $reservation): array
    {
        // Montants copiés à la réservation : un changement de tarif ne modifie pas l'historique.
        $lignes = [['libelle' => $reservation->getPrestation()->getNom(), 'montant' => $reservation->getPrestationPrixCentimes()]];
        if (null !== $reservation->getPrestationDuoNom()) {
            $lignes[] = ['libelle' => 'Duo, 2e personne : '.$reservation->getPrestationDuoNom(), 'montant' => $reservation->getPrestationDuoPrixCentimes()];
        }

        return self::completer($lignes, $reservation->getSupplementNom(), $reservation->getSupplementPrixCentimes(), $reservation->getMajorationCentimes(),
            $reservation->getRemiseMotif(), $reservation->getRemisePourcentage(), $reservation->getRemiseCentimes(), $reservation->getReductionCentimes());
    }

    /**
     * @return list<array{libelle: string, montant: int}>
     */
    public static function pourDevis(Devis $devis, Prestation $prestation, ?Supplement $supplement, ?Prestation $prestationDuo, int $reductionCentimes = 0): array
    {
        $lignes = [['libelle' => $prestation->getNom(), 'montant' => $prestation->getPrixCentimes()]];
        if (null !== $prestationDuo) {
            $lignes[] = ['libelle' => 'Duo, 2e personne : '.$prestationDuo->getNom(), 'montant' => $prestationDuo->getPrixCentimes()];
        }

        return self::completer($lignes, $supplement?->getNom(), $supplement?->getPrixCentimes() ?? 0, $devis->majorationCentimes,
            $devis->remiseMotif, $devis->remisePourcentage, $devis->remiseCentimes(), $reductionCentimes);
    }

    /**
     * @param list<array{libelle: string, montant: int}> $lignes
     *
     * @return list<array{libelle: string, montant: int}>
     */
    private static function completer(array $lignes, ?string $supplement, int $prixSupplement, int $majoration, ?string $motifRemise, int $pourcentage, int $remise, int $reduction): array
    {
        if (null !== $supplement) {
            $lignes[] = ['libelle' => 'Supplément : '.$supplement, 'montant' => $prixSupplement];
        }
        if ($remise > 0) {
            $lignes[] = ['libelle' => \sprintf('%s (−%d %%)', self::LIBELLES_REMISE[$motifRemise ?? 'manuelle'] ?? 'Remise', $pourcentage), 'montant' => -$remise];
        }
        if ($majoration > 0) {
            $lignes[] = ['libelle' => 'Majoration du soir', 'montant' => $majoration];
        }
        if ($reduction > 0) {
            $lignes[] = ['libelle' => 'Récompense fidélité (points)', 'montant' => -$reduction];
        }

        return $lignes;
    }
}
