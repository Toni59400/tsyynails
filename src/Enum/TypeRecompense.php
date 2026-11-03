<?php

declare(strict_types=1);

namespace App\Enum;

enum TypeRecompense: string
{
    /** Réduction en euros, utilisable en ligne au moment de réserver. */
    case REDUCTION = 'reduction';
    /** Supplément offert (nail art, french, effet chrome…), utilisable en ligne en réservant avec ce supplément. */
    case SUPPLEMENT_OFFERT = 'supplement';
    /** Avantage en nature remis au salon par la prothésiste. */
    case EN_SALON = 'en_salon';

    public function libelle(): string
    {
        return match ($this) {
            self::REDUCTION => 'Réduction en ligne',
            self::SUPPLEMENT_OFFERT => 'Supplément offert en ligne',
            self::EN_SALON => 'Avantage au salon',
        };
    }
}
