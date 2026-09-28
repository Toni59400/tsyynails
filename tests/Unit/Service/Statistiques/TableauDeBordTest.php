<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Statistiques;

use App\Service\Statistiques\TableauDeBord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TableauDeBordTest extends TestCase
{
    /**
     * @param array{string, string, string, string} $attendu début, fin, précédente, suivante
     */
    #[DataProvider('periodes')]
    public function testBornesDesPeriodes(string $periode, array $attendu): void
    {
        $bornes = TableauDeBord::bornes($periode, new \DateTimeImmutable('2026-09-30 15:42'));

        self::assertSame($attendu, array_map(static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d H:i'), $bornes));
    }

    /**
     * @return iterable<string, array{string, array{string, string, string, string}}>
     */
    public static function periodes(): iterable
    {
        yield 'jour' => ['jour', ['2026-09-30 00:00', '2026-10-01 00:00', '2026-09-29 00:00', '2026-10-01 00:00']];
        yield 'semaine du lundi au dimanche' => ['semaine', ['2026-09-28 00:00', '2026-10-05 00:00', '2026-09-21 00:00', '2026-10-05 00:00']];
        yield 'mois' => ['mois', ['2026-09-01 00:00', '2026-10-01 00:00', '2026-08-01 00:00', '2026-10-01 00:00']];
        yield 'année' => ['annee', ['2026-01-01 00:00', '2027-01-01 00:00', '2025-01-01 00:00', '2027-01-01 00:00']];
    }

    public function testLeMoisDe31JoursNeSauteParFevrier(): void
    {
        [$debut, , $precedente] = TableauDeBord::bornes('mois', new \DateTimeImmutable('2026-03-31'));

        self::assertSame('2026-03-01', $debut->format('Y-m-d'));
        self::assertSame('2026-02-01', $precedente->format('Y-m-d'));
    }

    public function testEvolution(): void
    {
        self::assertSame(25, TableauDeBord::evolution(12500, 10000));
        self::assertSame(-22, TableauDeBord::evolution(3085, 3965));
        self::assertNull(TableauDeBord::evolution(5000, 0));
    }
}
