<?php

declare(strict_types=1);

namespace App\Service;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\SvgWriter;

/**
 * Génère des QR codes en SVG (aucune extension GD requise).
 */
final class QrCodeGenerateur
{
    /**
     * @return string URI data: utilisable dans un <img src>
     */
    public function dataUri(string $contenu, int $taille = 240): string
    {
        return (new Builder(
            writer: new SvgWriter(),
            data: $contenu,
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $taille,
            margin: 8,
        ))->build()->getDataUri();
    }
}
