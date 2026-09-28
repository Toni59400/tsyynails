<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Photo;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Une photo supprimée de la base ne doit pas rester accessible sur le serveur.
 */
#[AsEntityListener(event: Events::postRemove, method: 'postRemove', entity: Photo::class)]
final class SupprimerFichierPhotoListener
{
    public function __construct(#[Autowire('%app.dossier_galerie%')] private readonly string $dossierGalerie)
    {
    }

    public function postRemove(Photo $photo): void
    {
        $nom = basename($photo->getFichier());
        if ('' !== $nom) {
            (new Filesystem())->remove($this->dossierGalerie.'/'.$nom);
        }
    }
}
