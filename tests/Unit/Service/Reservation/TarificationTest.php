<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Reservation;

use App\Service\Reservation\Tarification;
use PHPUnit\Framework\TestCase;

final class TarificationTest extends TestCase
{
    public function testLAcompteEstArrondiALEuroSuperieur(): void
    {
        self::assertSame(1700, Tarification::calculerAcompte(5500, 30));
        self::assertSame(900, Tarification::calculerAcompte(3000, 30));
    }

    public function testLAcompteNeDepassePasLePrix(): void
    {
        self::assertSame(1000, Tarification::calculerAcompte(1000, 100));
        self::assertSame(1000, Tarification::calculerAcompte(1000, 150));
    }

    public function testPasDAcomptePourUnePrestationGratuite(): void
    {
        self::assertSame(0, Tarification::calculerAcompte(0, 30));
    }
}
