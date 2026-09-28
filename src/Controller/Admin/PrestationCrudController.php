<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Prestation;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * @extends AbstractCrudController<Prestation>
 */
final class PrestationCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Prestation::class;
    }

    public function createEntity(string $entityFqcn): Prestation
    {
        return new Prestation('', 0, 60);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Prestation')
            ->setEntityLabelInPlural('Prestations')
            ->setDefaultSort(['ordre' => 'ASC', 'nom' => 'ASC'])
            ->setSearchFields(['nom', 'description']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('nom');
        yield TextareaField::new('description', 'Résumé')->hideOnIndex()
            ->setHelp('Une ou deux phrases, affichées sur les cartes et reprises par Google.');
        yield TextareaField::new('contenu', 'Texte détaillé')->hideOnIndex()->setNumOfRows(10)
            ->setHelp('Page de la prestation : déroulé, tenue, entretien, pour qui, conseils (300 à 500 mots idéalement, avec les mots que vos clientes cherchent : « pose gel Arras »…).');
        yield TextField::new('slug', 'Adresse de la page')->onlyOnDetail();
        yield MoneyField::new('prixCentimes', 'Prix')->setCurrency('EUR')->setStoredAsCents();
        yield IntegerField::new('dureeMinutes', 'Durée (min)')->setHelp('Multiple de 15 minutes.');
        yield IntegerField::new('ordre', 'Ordre d\'affichage')->hideOnIndex();
        yield BooleanField::new('active', 'Visible sur le site');
    }
}
