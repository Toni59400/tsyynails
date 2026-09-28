<?php

declare(strict_types=1);

namespace App\Service\Media;

/**
 * Retire les métadonnées des photos envoyées (position GPS, appareil, date, logiciel…)
 * sans ré-encoder l'image, donc sans perte de qualité ni dépendance à GD.
 *
 * - JPEG : segments APP1 (EXIF, XMP), APP13 (IPTC) et commentaires supprimés.
 *   Seule l'orientation EXIF est conservée, sinon les photos de téléphone s'afficheraient couchées.
 * - PNG : blocs eXIf, tEXt, zTXt, iTXt et tIME supprimés.
 * - WebP : blocs EXIF et XMP supprimés.
 */
final class NettoyeurMetadonnees
{
    private const TAG_ORIENTATION = 0x0112;

    public function nettoyerFichier(string $chemin): void
    {
        $contenu = file_get_contents($chemin);
        if (false === $contenu) {
            throw new \RuntimeException('Photo illisible.');
        }

        $nettoye = $this->nettoyer($contenu);
        if ($nettoye !== $contenu && false === file_put_contents($chemin, $nettoye)) {
            throw new \RuntimeException('Impossible d\'enregistrer la photo nettoyée.');
        }
    }

    public function nettoyer(string $contenu): string
    {
        return match (true) {
            str_starts_with($contenu, "\xFF\xD8") => $this->nettoyerJpeg($contenu),
            str_starts_with($contenu, "\x89PNG\r\n\x1A\n") => $this->nettoyerPng($contenu),
            str_starts_with($contenu, 'RIFF') && 'WEBP' === substr($contenu, 8, 4) => $this->nettoyerWebp($contenu),
            default => throw new \InvalidArgumentException('Format non pris en charge (JPEG, PNG ou WebP attendu).'),
        };
    }

    private function nettoyerJpeg(string $contenu): string
    {
        $sortie = "\xFF\xD8";
        $orientation = null;
        $position = 2;
        $taille = \strlen($contenu);

        while ($position + 4 <= $taille) {
            if ("\xFF" !== $contenu[$position]) {
                throw new \InvalidArgumentException('JPEG invalide.');
            }
            $marqueur = \ord($contenu[$position + 1]);

            // Début des données d'image : tout le reste est recopié tel quel.
            if (0xDA === $marqueur) {
                break;
            }

            $longueur = unpack('n', substr($contenu, $position + 2, 2))[1];
            $segment = substr($contenu, $position, $longueur + 2);

            if (0xE1 === $marqueur) {
                $orientation ??= $this->orientationExif(substr($segment, 4));
            } elseif (0xED !== $marqueur && 0xFE !== $marqueur) {
                $sortie .= $segment;
            }

            $position += $longueur + 2;
        }

        if (null !== $orientation && 1 !== $orientation) {
            // Juste après l'éventuel APP0 (JFIF), comme le veut l'usage.
            $apresApp0 = str_starts_with(substr($sortie, 2), "\xFF\xE0") ? 2 + unpack('n', substr($sortie, 4, 2))[1] + 2 : 2;
            $sortie = substr($sortie, 0, $apresApp0).$this->exifOrientationSeule($orientation).substr($sortie, $apresApp0);
        }

        return $sortie.substr($contenu, $position);
    }

    /**
     * Lit la valeur d'orientation (1 à 8) dans le premier répertoire EXIF, sans rien garder d'autre.
     */
    private function orientationExif(string $donnees): ?int
    {
        if (!str_starts_with($donnees, "Exif\0\0")) {
            return null;
        }

        $tiff = substr($donnees, 6);
        $format = match (substr($tiff, 0, 2)) {
            'II' => ['v', 'V'],
            'MM' => ['n', 'N'],
            default => null,
        };
        if (null === $format || \strlen($tiff) < 8) {
            return null;
        }

        [$court, $long] = $format;
        $repertoire = unpack($long, substr($tiff, 4, 4))[1];
        if ($repertoire + 2 > \strlen($tiff)) {
            return null;
        }

        $entrees = unpack($court, substr($tiff, $repertoire, 2))[1];
        for ($i = 0; $i < $entrees; ++$i) {
            $entree = substr($tiff, $repertoire + 2 + $i * 12, 12);
            if (12 !== \strlen($entree)) {
                return null;
            }
            if (self::TAG_ORIENTATION === unpack($court, substr($entree, 0, 2))[1]) {
                $valeur = unpack($court, substr($entree, 8, 2))[1];

                return $valeur >= 1 && $valeur <= 8 ? $valeur : null;
            }
        }

        return null;
    }

    /**
     * Segment APP1 minimal : un seul répertoire EXIF contenant uniquement l'orientation.
     */
    private function exifOrientationSeule(int $orientation): string
    {
        $tiff = 'II*'."\0".pack('V', 8)
            .pack('v', 1)
            .pack('vvVvv', self::TAG_ORIENTATION, 3, 1, $orientation, 0)
            .pack('V', 0);
        $donnees = "Exif\0\0".$tiff;

        return "\xFF\xE1".pack('n', \strlen($donnees) + 2).$donnees;
    }

    private function nettoyerPng(string $contenu): string
    {
        $aSupprimer = ['eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME'];
        $sortie = substr($contenu, 0, 8);
        $position = 8;

        while ($position + 12 <= \strlen($contenu)) {
            $longueur = unpack('N', substr($contenu, $position, 4))[1];
            $type = substr($contenu, $position + 4, 4);
            $bloc = substr($contenu, $position, $longueur + 12);

            if (!\in_array($type, $aSupprimer, true)) {
                $sortie .= $bloc;
            }
            $position += $longueur + 12;

            if ('IEND' === $type) {
                break;
            }
        }

        return $sortie;
    }

    private function nettoyerWebp(string $contenu): string
    {
        $blocs = '';
        $position = 12;

        while ($position + 8 <= \strlen($contenu)) {
            $type = substr($contenu, $position, 4);
            $longueur = unpack('V', substr($contenu, $position + 4, 4))[1];
            $bloc = substr($contenu, $position, 8 + $longueur + ($longueur % 2));

            if ('VP8X' === $type) {
                // Indicateurs « contient EXIF » (0x08) et « contient XMP » (0x04) retirés.
                $bloc[8] = \chr(\ord($bloc[8]) & ~0x0C);
            }
            if ('EXIF' !== $type && 'XMP ' !== $type) {
                $blocs .= $bloc;
            }
            $position += 8 + $longueur + ($longueur % 2);
        }

        return 'RIFF'.pack('V', 4 + \strlen($blocs)).'WEBP'.$blocs;
    }
}
