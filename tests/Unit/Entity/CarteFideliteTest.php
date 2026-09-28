<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\CarteFidelite;
use App\Entity\Client;
use PHPUnit\Framework\TestCase;

final class CarteFideliteTest extends TestCase
{
    public function testLeJetonEstAleatoireEtUtilisableDansUneUrl(): void
    {
        $jetons = array_map(static fn () => CarteFidelite::generer()->getJeton(), range(1, 100));

        self::assertCount(100, array_unique($jetons));
        foreach ($jetons as $jeton) {
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{24}$/', $jeton);
        }
    }

    public function testUneCarteNePeutEtreAssocieeQuUneFois(): void
    {
        $carte = CarteFidelite::generer();
        $carte->associerA(new Client('Léa', 'Martin', '+33612345678'), new \DateTimeImmutable());

        $this->expectException(\LogicException::class);

        $carte->associerA(new Client('Inès', 'Durand', '+33698765432'), new \DateTimeImmutable());
    }

    public function testUneCarteDesactiveeNePeutPasEtreAssociee(): void
    {
        $carte = CarteFidelite::generer();
        $carte->desactiver();

        $this->expectException(\LogicException::class);

        $carte->associerA(new Client('Léa', 'Martin', '+33612345678'), new \DateTimeImmutable());
    }
}
