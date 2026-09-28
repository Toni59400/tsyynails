<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\FormatExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormatExtensionTest extends TestCase
{
    private const NBSP = "\u{00A0}";

    #[DataProvider('prix')]
    public function testEuros(int $centimes, string $attendu): void
    {
        self::assertSame(str_replace(' ', self::NBSP, $attendu), (new FormatExtension())->euros($centimes));
    }

    /**
     * @return iterable<array{int, string}>
     */
    public static function prix(): iterable
    {
        yield [5500, '55 €'];
        yield [1250, '12,50 €'];
        yield [0, '0 €'];
        yield [150000, '1 500 €'];
    }

    #[DataProvider('durees')]
    public function testDuree(int $minutes, string $attendu): void
    {
        self::assertSame(str_replace(' ', self::NBSP, $attendu), (new FormatExtension())->duree($minutes));
    }

    /**
     * @return iterable<array{int, string}>
     */
    public static function durees(): iterable
    {
        yield [45, '45 min'];
        yield [60, '1 h'];
        yield [90, '1 h 30'];
        yield [105, '1 h 45'];
        yield [120, '2 h'];
    }

    public function testDateEnFrancais(): void
    {
        $date = new \DateTimeImmutable('2026-10-06 09:30', new \DateTimeZone('Europe/Paris'));

        self::assertSame('mardi 6 octobre', (new FormatExtension())->dateFr($date, 'EEEE d MMMM'));
    }

    public function testHeure(): void
    {
        $extension = new FormatExtension();

        self::assertSame('9'.self::NBSP.'h'.self::NBSP.'30', $extension->heure(new \DateTimeImmutable('09:30')));
        self::assertSame('14'.self::NBSP.'h', $extension->heure(new \DateTimeImmutable('14:00')));
    }

    public function testTelephone(): void
    {
        $extension = new FormatExtension();

        self::assertSame('06 39 98 10 00', $extension->telephone('+33639981000'));
        self::assertSame('+32470123456', $extension->telephone('+32470123456'));
    }
}
