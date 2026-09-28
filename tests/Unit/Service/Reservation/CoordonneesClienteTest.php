<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Reservation;

use App\Service\Reservation\CoordonneesCliente;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CoordonneesClienteTest extends TestCase
{
    #[DataProvider('telephones')]
    public function testNormalisationDuTelephone(string $saisie, ?string $attendu): void
    {
        self::assertSame($attendu, CoordonneesCliente::normaliserTelephone($saisie));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function telephones(): iterable
    {
        yield 'mobile avec espaces' => ['06 12 34 56 78', '+33612345678'];
        yield 'mobile avec points' => ['07.12.34.56.78', '+33712345678'];
        yield 'fixe' => ['0320123456', '+33320123456'];
        yield 'déjà international' => ['+33 6 12 34 56 78', '+33612345678'];
        yield 'belge en 00' => ['0032 470 12 34 56', '+32470123456'];
        yield 'trop court' => ['06 12 34', null];
        yield 'lettres' => ['06 AB CD EF GH', null];
        yield 'commence par 00 seul' => ['0012', null];
    }
}
