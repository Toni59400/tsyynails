<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Supplement;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Suppléments proposés dans le tunnel de réservation (nail art niveau 1, niveau 2…).
 *
 * @extends AbstractCrudController<Supplement>
 */
final class SupplementCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Supplement::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Supplément')
            ->setEntityLabelInPlural('Suppléments')
            ->setDefaultSort(['ordre' => 'ASC', 'prixCentimes' => 'ASC'])
            ->setPageTitle(Crud::PAGE_INDEX, 'Suppléments (nail art…)')
            ->setHelp(Crud::PAGE_INDEX, 'Proposés au moment de choisir le créneau. Un changement de prix ne modifie pas les rendez-vous déjà demandés.');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('nom')->setHelp('Exemple : « Nail art niveau 1 ».');
        yield TextareaField::new('description', 'Ce qui est compris')->hideOnIndex()
            ->setHelp('Affiché à la cliente. Exemple : « Décor simple sur 2 ongles : paillettes, points, ligne ».');
        yield MoneyField::new('prixCentimes', 'Prix')->setCurrency('EUR')->setStoredAsCents();
        yield IntegerField::new('dureeMinutes', 'Temps en plus (min)')
            ->setHelp('Ajouté à la durée du rendez-vous pour bloquer le bon créneau. Multiple de 15 (0 si aucun).');
        yield AssociationField::new('prestations', 'Prestations concernées')
            ->setFormTypeOption('by_reference', false)
            ->setRequired(false)
            ->setHelp('Laissez vide pour le proposer sur toutes les prestations.')
            ->formatValue(static fn ($valeur, ?Supplement $s): string => null === $s || $s->getPrestations()->isEmpty() ? 'Toutes' : (string) $s->getPrestations()->count());
        yield IntegerField::new('ordre', 'Ordre d\'affichage')->hideOnIndex();
        yield BooleanField::new('active', 'Proposé aux clientes');
    }
}
