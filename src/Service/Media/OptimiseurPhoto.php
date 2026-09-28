<?php

declare(strict_types=1);

namespace App\Service\Media;

use Psr\Log\LoggerInterface;

/**
 * Prépare une photo pour le web : orientation corrigée, 1 600 px de côté au maximum,
 * compression, métadonnées retirées (GPS, appareil). Le format et le nom du fichier sont conservés.
 *
 * Utilise Imagick s'il est disponible (hébergement OVH) ; sinon, se contente de retirer
 * les métadonnées sans toucher aux pixels (poste de développement sans extension d'image).
 */
final class OptimiseurPhoto
{
    public const COTE_MAX = 1600;
    public const QUALITE = 82;

    public function __construct(
        private readonly NettoyeurMetadonnees $nettoyeur,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function optimiser(string $chemin): void
    {
        if (!class_exists(\Imagick::class)) {
            $this->nettoyeur->nettoyerFichier($chemin);

            return;
        }

        try {
            $image = new \Imagick($chemin);
            self::redresser($image);

            if ($image->getImageWidth() > self::COTE_MAX || $image->getImageHeight() > self::COTE_MAX) {
                $image->thumbnailImage(self::COTE_MAX, self::COTE_MAX, true);
            }

            // Profil couleur conservé (rendu fidèle), tout le reste retiré.
            $profils = $image->getImageProfiles('icc', true);
            $image->stripImage();
            if (isset($profils['icc'])) {
                $image->profileImage('icc', $profils['icc']);
            }
            $image->setImageCompressionQuality(self::QUALITE);
            if ('JPEG' === $image->getImageFormat()) {
                $image->setInterlaceScheme(\Imagick::INTERLACE_PLANE);
            }

            $image->writeImage($chemin);
            $image->clear();
        } catch (\Throwable $erreur) {
            // Image inhabituelle : on garde l'original, mais sans ses métadonnées.
            $this->logger->warning('Optimisation de photo impossible, métadonnées seulement retirées.', ['erreur' => $erreur->getMessage()]);
            $this->nettoyeur->nettoyerFichier($chemin);
        }
    }

    /** Applique l'orientation EXIF (photos de téléphone) avant de retirer les métadonnées. */
    private static function redresser(\Imagick $image): void
    {
        match ($image->getImageOrientation()) {
            \Imagick::ORIENTATION_BOTTOMRIGHT => $image->rotateImage('#000', 180),
            \Imagick::ORIENTATION_RIGHTTOP => $image->rotateImage('#000', 90),
            \Imagick::ORIENTATION_LEFTBOTTOM => $image->rotateImage('#000', -90),
            default => null,
        };
        $image->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
    }
}
