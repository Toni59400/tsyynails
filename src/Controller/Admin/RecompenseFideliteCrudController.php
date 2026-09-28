<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\RecompenseFidelite;
use App\Enum\TypeRecompense;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Paliers du programme de fidélité. Une récompense déjà utilisée se désactive plutôt que se supprime.
 *
 * @extends AbstractCrudController<RecompenseFidelite>
 */
final class RecompenseFideliteCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return RecompenseFidelite::class;
    }

    public function createEntity(string $entityFqcn): RecompenseFidelite
    {
        return new RecompenseFidelite('', 100, TypeRecompense::REDUCTION, 500);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Récompense')
            ->setEntityLabelInPlural('Paliers de fidélité')
            ->setDefaultSort(['seuilPoints' => 'ASC'])
            ->setHelp(Crud::PAGE_INDEX, 'Conseil : gardez un taux de 5 à 6 % (ex. 100 points = 5 €) et préférez les petits avantages au salon (nail art, strass), très appréciés et peu coûteux.');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::DELETE, Action::BATCH_DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IntegerField::new('seuilPoints', 'Points nécessaires');
        yield TextField::new('nom', 'Récompense')->setHelp('Exemple : « 5 € de réduction », « Nail art offert ».');
        yield TextareaField::new('description')->hideOnIndex();
        yield ChoiceField::new('type')->setChoices([
            TypeRecompense::REDUCTION->libelle() => TypeRecompense::REDUCTION,
            TypeRecompense::EN_SALON->libelle() => TypeRecompense::EN_SALON,
        ])->setHelp('Réduction : la cliente l\'utilise en réservant en ligne. Avantage au salon : vous le remettez depuis sa fiche.');
        yield MoneyField::new('valeurCentimes', 'Valeur')->setCurrency('EUR')->setStoredAsCents()
            ->setHelp('Montant déduit du prix (réduction) ou valeur affichée de l\'avantage.');
        yield BooleanField::new('active', 'Proposée');
    }
}
