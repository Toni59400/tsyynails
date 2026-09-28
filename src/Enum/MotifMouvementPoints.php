<?php

declare(strict_types=1);

namespace App\Enum;

enum MotifMouvementPoints: string
{
    /** Points gagnés pour une prestation réalisée. */
    case VISITE = 'visite';
    /** Points utilisés comme réduction sur une réservation. */
    case UTILISATION = 'utilisation';
    /** Points rendus quand une réservation utilisant des points est annulée ou refusée. */
    case RESTITUTION = 'restitution';
    /** Ajustement manuel par l'admin. */
    case CORRECTION = 'correction';

    public function libelle(): string
    {
        return match ($this) {
            self::VISITE => 'Visite',
            self::UTILISATION => 'Utilisation',
            self::RESTITUTION => 'Restitution',
            self::CORRECTION => 'Correction',
        };
    }
}
