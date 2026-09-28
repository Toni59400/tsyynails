<?php

declare(strict_types=1);

namespace App\Enum;

enum MotifMouvementPoints: string
{
    /** Points gagnés pour une prestation réalisée. */
    case VISITE = 'visite';
    /** Points échangés contre une récompense (en ligne ou au salon). */
    case UTILISATION = 'utilisation';
    /** Points rendus quand une réservation utilisant des points est annulée ou refusée. */
    case RESTITUTION = 'restitution';
    /** Ajustement manuel par l'admin. */
    case CORRECTION = 'correction';
    /** Bonus de bienvenue, une seule fois par cliente. */
    case INSCRIPTION = 'inscription';
    /** Points perdus après la période d'inactivité. */
    case EXPIRATION = 'expiration';
    /** Parrainage : marraine et filleule, au premier rendez-vous honoré de la filleule. */
    case PARRAINAGE = 'parrainage';

    public function libelle(): string
    {
        return match ($this) {
            self::VISITE => 'Visite',
            self::UTILISATION => 'Récompense',
            self::RESTITUTION => 'Restitution',
            self::CORRECTION => 'Correction',
            self::INSCRIPTION => 'Bienvenue',
            self::EXPIRATION => 'Expiration',
            self::PARRAINAGE => 'Parrainage',
        };
    }
}
