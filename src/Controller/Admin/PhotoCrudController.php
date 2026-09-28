<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Photo;
use App\Service\Media\ImportPhotos;
use App\Service\Media\OptimiseurPhoto;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\Image;

/**
 * Photos de la galerie : réalisations liées à une prestation et/ou photos d'inspiration classées par thèmes.
 *
 * @extends AbstractCrudController<Photo>
 */
final class PhotoCrudController extends AbstractCrudController
{
    /** Relatif au dossier du projet, identique au paramètre app.dossier_galerie. */
    public const DOSSIER_UPLOAD = 'public/uploads/galerie';

    public function __construct(private readonly OptimiseurPhoto $optimiseur)
    {
    }

    public static function getEntityFqcn(): string
    {
        return Photo::class;
    }

    public function createEntity(string $entityFqcn): Photo
    {
        return new Photo('', '');
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Photo')
            ->setEntityLabelInPlural('Photos')
            ->setDefaultSort(['ordre' => 'ASC', 'createdAt' => 'DESC'])
            ->setSearchFields(['legende'])
            ->setPageTitle(Crud::PAGE_INDEX, 'Galerie photos');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->add(Crud::PAGE_INDEX, Action::new('importer', 'Importer des photos', 'fa fa-upload')
            ->linkToRoute('admin_photos_import_index')
            ->createAsGlobalAction());
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('prestation'))
            ->add(EntityFilter::new('inspirations', 'Thème d\'inspiration'))
            ->add(BooleanFilter::new('publiee', 'Publiée'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield ImageField::new('fichier', 'Photo')
            ->setBasePath('uploads/galerie')
            ->setUploadDir(self::DOSSIER_UPLOAD)
            // Nom aléatoire : le nom d'origine du fichier n'est jamais conservé.
            ->setUploadedFileNamePattern('[randomhash].[extension]')
            ->setFileConstraints(new Image(
                maxSize: ImportPhotos::TAILLE_MAX,
                mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                mimeTypesMessage: 'Formats acceptés : JPEG, PNG ou WebP.',
            ))
            ->setFormTypeOption('upload_new', $this->enregistrer(...))
            ->setFormTypeOption('allow_delete', false)
            ->setRequired(Crud::PAGE_NEW === $pageName)
            ->setHelp('JPEG, PNG ou WebP, 15 Mo maximum, redimensionnée automatiquement. Pour ajouter plusieurs photos d\'un coup, utilisez « Importer des photos ». Les informations cachées de la photo (position GPS, modèle de téléphone) sont retirées automatiquement.');

        yield TextField::new('legende', 'Description')
            ->setHelp('Lue par les lecteurs d\'écran et affichée sous la photo. Exemple : « French rose poudré sur ongles courts ».');
        yield AssociationField::new('prestation')
            ->setRequired(false)
            ->setHelp('La photo illustre cette prestation sur la page des tarifs.');
        yield AssociationField::new('inspirations', 'Thèmes d\'inspiration')
            ->setFormTypeOption('by_reference', false)
            ->setRequired(false)
            ->setHelp('Classe la photo dans un ou plusieurs thèmes de la galerie (été, mariage…).');
        yield IntegerField::new('ordre', 'Ordre d\'affichage')->hideOnIndex()->setHelp('Les plus petits nombres s\'affichent en premier.');
        yield BooleanField::new('publiee', 'Visible sur le site');
    }

    /**
     * Remplace l'enregistrement par défaut d'EasyAdmin : déplace le fichier, puis retire ses métadonnées.
     * Un fichier illisible est supprimé plutôt que publié tel quel.
     */
    private function enregistrer(UploadedFile $fichier, string $dossier, string $nom): void
    {
        $destination = $fichier->move($dossier, basename($nom));

        try {
            $this->optimiseur->optimiser($destination->getPathname());
        } catch (\Throwable $erreur) {
            @unlink($destination->getPathname());

            throw $erreur;
        }
    }
}
