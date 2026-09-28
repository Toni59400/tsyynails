<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Client;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    public function testLAnonymisationEffaceToutesLesDonneesIdentifiantes(): void
    {
        $client = (new Client('Léa', 'Martin', '+33612345678'))
            ->setEmail('lea@example.com')
            ->setUser(new User('lea@example.com'))
            ->enregistrerNotesSante('chiffre', new \DateTimeImmutable());

        $client->anonymiser(new \DateTimeImmutable('2029-01-01'));

        self::assertTrue($client->estAnonymise());
        self::assertNull($client->getTelephone());
        self::assertNull($client->getEmail());
        self::assertNull($client->getUser());
        self::assertNull($client->getNotesSanteChiffrees());
        self::assertNull($client->getConsentementSanteAt());
        self::assertStringNotContainsString('Martin', $client->getNomComplet());
    }

    public function testLaDerniereVisiteNeReculeJamais(): void
    {
        $client = new Client('Léa', 'Martin', '+33612345678');

        $client->enregistrerVisite(new \DateTimeImmutable('2026-10-05'));
        $client->enregistrerVisite(new \DateTimeImmutable('2026-09-01'));

        self::assertEquals(new \DateTimeImmutable('2026-10-05'), $client->getDerniereVisiteAt());
    }
}
