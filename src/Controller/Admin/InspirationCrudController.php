<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Inspiration;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Thèmes de la galerie. Les photos se rangent dans un thème depuis l'écran « Photos ».
 *
 * @extends AbstractCrudController<Inspiration>
 */
final class InspirationCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Inspiration::class;
    }

    public function createEntity(string $entityFqcn): Inspiration
    {
        return new Inspiration('');
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Thème d\'inspiration')
            ->setEntityLabelInPlural('Thèmes d\'inspiration')
            ->setDefaultSort(['ordre' => 'ASC', 'nom' => 'ASC'])
            ->setHelp(Crud::PAGE_INDEX, 'Pour ajouter des photos à un thème, ouvrez « Photos » et choisissez le thème sur chaque photo.');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('nom')->setHelp('Exemple : « Inspiration été », « Mariage », « French revisitée ».');
        yield TextareaField::new('description')->hideOnIndex()->setHelp('Quelques mots affichés au-dessus des photos du thème (facultatif).');
        yield IntegerField::new('photos.count', 'Photos')->onlyOnIndex();
        yield IntegerField::new('ordre', 'Ordre d\'affichage')->hideOnIndex();
        yield BooleanField::new('publiee', 'Visible sur le site');
    }
}
