<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Entity\Inspiration;
use App\Entity\Photo;
use App\Entity\Prestation;
use App\Repository\PhotoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Ajout d'une photo à la galerie (import en lot depuis l'admin) : contrôle du fichier,
 * nom aléatoire, optimisation, enregistrement. Une photo par appel : le navigateur envoie
 * le lot fichier par fichier, sans limite de nombre.
 */
final class ImportPhotos
{
    public const TAILLE_MAX = '15M';
    public const TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly ValidatorInterface $validator,
        private readonly OptimiseurPhoto $optimiseur,
        private readonly PhotoRepository $photos,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%app.dossier_galerie%')] private readonly string $dossier,
    ) {
    }

    /**
     * @param list<Inspiration> $themes
     *
     * @throws \InvalidArgumentException fichier refusé (message lisible par la prothésiste)
     */
    public function importer(UploadedFile $fichier, string $legende, ?Prestation $prestation, array $themes, bool $publiee): Photo
    {
        $erreurs = $this->validator->validate($fichier, new Image(
            maxSize: self::TAILLE_MAX,
            mimeTypes: self::TYPES,
            mimeTypesMessage: 'Format non accepté : JPEG, PNG ou WebP (les photos iPhone en HEIC doivent être exportées en JPEG).',
            maxSizeMessage: 'Photo trop lourde ({{ size }} {{ suffix }}), maximum {{ limit }} {{ suffix }}.',
        ));
        if (\count($erreurs) > 0) {
            throw new \InvalidArgumentException((string) $erreurs[0]->getMessage());
        }

        $extension = match ($fichier->getMimeType()) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        // Nom aléatoire : le nom d'origine (souvent daté ou nominatif) n'est jamais conservé.
        $nom = bin2hex(random_bytes(20)).'.'.$extension;
        $destination = $fichier->move($this->dossier, $nom);

        try {
            $this->optimiseur->optimiser($destination->getPathname());
        } catch (\Throwable $erreur) {
            @unlink($destination->getPathname());

            throw new \InvalidArgumentException('Image illisible ou abîmée.', previous: $erreur);
        }

        $photo = (new Photo($nom, mb_substr(trim($legende), 0, 255)))
            ->setPrestation($prestation)
            ->setPubliee($publiee)
            ->setOrdre($this->photos->prochainOrdre());
        foreach ($themes as $theme) {
            $photo->addInspiration($theme);
        }
        $this->entityManager->persist($photo);
        $this->entityManager->flush();

        return $photo;
    }
}
