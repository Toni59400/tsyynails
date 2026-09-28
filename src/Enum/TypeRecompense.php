<?php

declare(strict_types=1);

namespace App\Enum;

enum TypeRecompense: string
{
    /** Réduction en euros, utilisable en ligne au moment de réserver. */
    case REDUCTION = 'reduction';
    /** Avantage en nature (nail art, strass…), remis au salon par la prothésiste. */
    case EN_SALON = 'en_salon';

    public function libelle(): string
    {
        return match ($this) {
            self::REDUCTION => 'Réduction en ligne',
            self::EN_SALON => 'Avantage au salon',
        };
    }
}
