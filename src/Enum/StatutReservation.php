<?php

declare(strict_types=1);

namespace App\Enum;

enum StatutReservation: string
{
    /** Créneau bloqué pendant que la cliente saisit sa carte ; expire au bout de 30 minutes sans empreinte. */
    case PAIEMENT_EN_COURS = 'paiement_en_cours';
    /** Empreinte autorisée : la demande attend la décision de la prothésiste. */
    case EN_ATTENTE = 'en_attente';
    case CONFIRMEE = 'confirmee';
    case REFUSEE = 'refusee';
    case EXPIREE = 'expiree';
    case ANNULEE = 'annulee';
    case HONOREE = 'honoree';
    case NON_HONOREE = 'non_honoree';

    public function peutPasserA(self $cible): bool
    {
        return \in_array($cible, $this->transitionsPossibles(), true);
    }

    /**
     * @return list<self>
     */
    public function transitionsPossibles(): array
    {
        return match ($this) {
            self::PAIEMENT_EN_COURS => [self::EN_ATTENTE, self::EXPIREE, self::ANNULEE],
            self::EN_ATTENTE => [self::CONFIRMEE, self::REFUSEE, self::EXPIREE, self::ANNULEE],
            self::CONFIRMEE => [self::ANNULEE, self::HONOREE, self::NON_HONOREE],
            self::REFUSEE, self::EXPIREE, self::ANNULEE, self::HONOREE, self::NON_HONOREE => [],
        };
    }

    /**
     * Statuts pour lesquels le créneau est occupé dans l'agenda.
     *
     * @return list<self>
     */
    public static function bloquantLeCreneau(): array
    {
        return [self::PAIEMENT_EN_COURS, self::EN_ATTENTE, self::CONFIRMEE];
    }

    public function libelle(): string
    {
        return match ($this) {
            self::PAIEMENT_EN_COURS => 'Paiement en cours',
            self::EN_ATTENTE => 'En attente de validation',
            self::CONFIRMEE => 'Confirmée',
            self::REFUSEE => 'Refusée',
            self::EXPIREE => 'Expirée',
            self::ANNULEE => 'Annulée',
            self::HONOREE => 'Honorée',
            self::NON_HONOREE => 'Non honorée',
        };
    }
}
