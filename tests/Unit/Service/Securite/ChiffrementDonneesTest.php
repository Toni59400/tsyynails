<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Securite;

use App\Service\Securite\ChiffrementDonnees;
use PHPUnit\Framework\TestCase;

final class ChiffrementDonneesTest extends TestCase
{
    public function testUnTexteChiffrePuisDechiffreEstIdentique(): void
    {
        $service = new ChiffrementDonnees($this->cle());

        $chiffre = $service->chiffrer('Allergie au latex');

        self::assertStringNotContainsString('latex', $chiffre);
        self::assertSame('Allergie au latex', $service->dechiffrer($chiffre));
    }

    public function testDeuxChiffrementsDuMemeTexteSontDifferents(): void
    {
        $service = new ChiffrementDonnees($this->cle());

        self::assertNotSame($service->chiffrer('texte'), $service->chiffrer('texte'));
    }

    public function testUneAutreCleNePeutPasDechiffrer(): void
    {
        $chiffre = (new ChiffrementDonnees($this->cle()))->chiffrer('texte');

        $this->expectException(\RuntimeException::class);

        (new ChiffrementDonnees($this->cle()))->dechiffrer($chiffre);
    }

    public function testUneCleInvalideEstRefusee(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ChiffrementDonnees('trop-courte');
    }

    private function cle(): string
    {
        return base64_encode(sodium_crypto_secretbox_keygen());
    }
}
