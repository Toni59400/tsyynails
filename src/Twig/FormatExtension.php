<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Attribute\AsTwigFilter;

/**
 * Formats d'affichage en français : prix, durées, dates, téléphones.
 */
final class FormatExtension
{
    private const ESPACE_INSECABLE = "\u{00A0}";

    /** 5500 → « 55 € », 1250 → « 12,50 € ». */
    #[AsTwigFilter('euros')]
    public function euros(int $centimes): string
    {
        $decimales = 0 === $centimes % 100 ? 0 : 2;

        return number_format($centimes / 100, $decimales, ',', self::ESPACE_INSECABLE).self::ESPACE_INSECABLE.'€';
    }

    /** 45 → « 45 min », 90 → « 1 h 30 », 120 → « 2 h ». */
    #[AsTwigFilter('duree')]
    public function duree(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes.self::ESPACE_INSECABLE.'min';
        }

        $heures = intdiv($minutes, 60);
        $reste = $minutes % 60;

        return $heures.self::ESPACE_INSECABLE.'h'.(0 === $reste ? '' : self::ESPACE_INSECABLE.str_pad((string) $reste, 2, '0', \STR_PAD_LEFT));
    }

    /**
     * Motif ICU : « EEEE d MMMM » → « mardi 6 octobre ».
     *
     * @see https://unicode-org.github.io/icu/userguide/format_parse/datetime/#datetime-format-syntax
     */
    #[AsTwigFilter('date_fr')]
    public function dateFr(\DateTimeInterface $date, string $motif = 'EEEE d MMMM yyyy'): string
    {
        $formateur = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $date->getTimezone(), null, $motif);

        return (string) $formateur->format($date);
    }

    /** 09:30 → « 9 h 30 », 14:00 → « 14 h ». */
    #[AsTwigFilter('heure')]
    public function heure(\DateTimeInterface $date): string
    {
        $minutes = $date->format('i');

        return $date->format('G').self::ESPACE_INSECABLE.'h'.('00' === $minutes ? '' : self::ESPACE_INSECABLE.$minutes);
    }

    /** +33612345678 → « 06 12 34 56 78 » ; les numéros étrangers restent au format international. */
    #[AsTwigFilter('telephone')]
    public function telephone(?string $e164): string
    {
        if (null === $e164 || !preg_match('/^\+33(\d{9})$/', $e164, $morceaux)) {
            return (string) $e164;
        }

        return implode(' ', str_split('0'.$morceaux[1], 2));
    }
}
