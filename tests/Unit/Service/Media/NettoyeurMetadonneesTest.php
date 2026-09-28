<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Media;

use App\Service\Media\NettoyeurMetadonnees;
use PHPUnit\Framework\TestCase;

final class NettoyeurMetadonneesTest extends TestCase
{
    private const GPS = 'GPS 50.6292N 3.0573E iPhone 15 Pro';

    public function testJpegPerdSesMetadonneesMaisGardeLImageEtLOrientation(): void
    {
        $image = "\xFF\xDA".pack('n', 4)."\x01\x02".'donnees-image'."\xFF\xD9";
        $jpeg = "\xFF\xD8"
            .$this->segment(0xE0, "JFIF\0\x01\x01\0\0\x01\0\x01\0\0")
            .$this->segment(0xE1, $this->exif(6))
            .$this->segment(0xE1, 'http://ns.adobe.com/xap/1.0/'."\0".'<x:xmpmeta>'.self::GPS.'</x:xmpmeta>')
            .$this->segment(0xED, 'Photoshop 3.0'."\0".self::GPS)
            .$this->segment(0xFE, self::GPS)
            .$image;

        $nettoye = (new NettoyeurMetadonnees())->nettoyer($jpeg);

        self::assertStringNotContainsString('GPS', $nettoye);
        self::assertStringNotContainsString('iPhone', $nettoye);
        self::assertStringStartsWith("\xFF\xD8\xFF\xE0", $nettoye);
        self::assertStringEndsWith($image, $nettoye);
        self::assertStringContainsString($this->exifOrientationAttendu(6), $nettoye);
    }

    public function testJpegSansRotationNeGardeAucunExif(): void
    {
        $jpeg = "\xFF\xD8".$this->segment(0xE1, $this->exif(1))."\xFF\xDA".pack('n', 2).'x'."\xFF\xD9";

        $nettoye = (new NettoyeurMetadonnees())->nettoyer($jpeg);

        self::assertStringNotContainsString('Exif', $nettoye);
        self::assertStringNotContainsString('GPS', $nettoye);
    }

    public function testPngPerdSesBlocsDeTexte(): void
    {
        $png = "\x89PNG\r\n\x1A\n"
            .$this->bloc('IHDR', str_repeat("\0", 13))
            .$this->bloc('tEXt', 'Comment'."\0".self::GPS)
            .$this->bloc('eXIf', 'MM'.self::GPS)
            .$this->bloc('IDAT', 'pixels')
            .$this->bloc('IEND', '');

        $nettoye = (new NettoyeurMetadonnees())->nettoyer($png);

        self::assertStringNotContainsString('GPS', $nettoye);
        self::assertStringContainsString('IHDR', $nettoye);
        self::assertStringContainsString('pixels', $nettoye);
        self::assertStringEndsWith($this->bloc('IEND', ''), $nettoye);
    }

    public function testWebpPerdSesBlocsExifEtXmpEtResteCoherent(): void
    {
        $vp8x = 'VP8X'.pack('V', 10).\chr(0x0C | 0x10).str_repeat("\0", 9);
        $blocs = $vp8x
            .'VP8L'.pack('V', 5).'image'."\0"
            .'EXIF'.pack('V', \strlen(self::GPS)).self::GPS
            .'XMP '.pack('V', 4).'<x/>';
        $webp = 'RIFF'.pack('V', 4 + \strlen($blocs)).'WEBP'.$blocs;

        $nettoye = (new NettoyeurMetadonnees())->nettoyer($webp);

        self::assertStringNotContainsString('GPS', $nettoye);
        self::assertStringNotContainsString('XMP ', $nettoye);
        self::assertSame(\strlen($nettoye) - 8, unpack('V', substr($nettoye, 4, 4))[1]);
        // Seul l'indicateur « alpha » (0x10) reste.
        self::assertSame(0x10, \ord($nettoye[20]));
    }

    public function testRefuseUnFormatInconnu(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new NettoyeurMetadonnees())->nettoyer('GIF89a...');
    }

    private function segment(int $marqueur, string $donnees): string
    {
        return "\xFF".\chr($marqueur).pack('n', \strlen($donnees) + 2).$donnees;
    }

    private function bloc(string $type, string $donnees): string
    {
        return pack('N', \strlen($donnees)).$type.$donnees.pack('N', crc32($type.$donnees));
    }

    /**
     * EXIF big-endian avec l'orientation, un modèle d'appareil et des coordonnées GPS en clair.
     */
    private function exif(int $orientation): string
    {
        $texte = self::GPS."\0";
        $tiff = 'MM'."\0*".pack('N', 8)
            .pack('n', 2)
            .pack('nnNnn', 0x0112, 3, 1, $orientation, 0)
            .pack('nnNN', 0x0110, 2, \strlen($texte), 8 + 2 + 24 + 4)
            .pack('N', 0)
            .$texte;

        return "Exif\0\0".$tiff;
    }

    private function exifOrientationAttendu(int $orientation): string
    {
        return "Exif\0\0".'II*'."\0".pack('V', 8).pack('v', 1).pack('vvVvv', 0x0112, 3, 1, $orientation, 0).pack('V', 0);
    }
}
