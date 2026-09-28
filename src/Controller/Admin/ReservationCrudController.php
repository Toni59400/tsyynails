<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Reservation;
use App\Enum\StatutReservation;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;

/**
 * Consultation seule : les réservations sont créées par le tunnel de réservation
 * et changent de statut via ReservationWorkflow (validation, refus, Stripe).
 *
 * @extends AbstractCrudController<Reservation>
 */
final class ReservationCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Reservation::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Réservation')
            ->setEntityLabelInPlural('Réservations')
            ->setDefaultSort(['debut' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('statut')->setChoices($this->choixStatuts()))
            ->add(DateTimeFilter::new('debut', 'Date'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('debut', 'Rendez-vous')->setFormat('EEE dd/MM/yyyy HH:mm');
        yield AssociationField::new('client', 'Cliente');
        yield AssociationField::new('prestation');
        yield ChoiceField::new('statut')->setChoices($this->choixStatuts())->renderAsBadges([
            StatutReservation::EN_ATTENTE->value => 'warning',
            StatutReservation::CONFIRMEE->value => 'success',
            StatutReservation::HONOREE->value => 'primary',
            StatutReservation::NON_HONOREE->value => 'danger',
        ]);
        yield MoneyField::new('prixCentimes', 'Prix')->setCurrency('EUR')->setStoredAsCents();
        yield MoneyField::new('acompteCentimes', 'Acompte')->setCurrency('EUR')->setStoredAsCents()->onlyOnDetail();
        yield MoneyField::new('reductionCentimes', 'Réduction fidélité')->setCurrency('EUR')->setStoredAsCents()->onlyOnDetail();
        yield IntegerField::new('pointsUtilises', 'Points utilisés')->onlyOnDetail();
        yield DateTimeField::new('createdAt', 'Demandée le')->onlyOnDetail()->setFormat('dd/MM/yyyy HH:mm');
        yield DateTimeField::new('decisionAt', 'Décision le')->onlyOnDetail()->setFormat('dd/MM/yyyy HH:mm');
    }

    /**
     * @return array<string, StatutReservation>
     */
    private function choixStatuts(): array
    {
        $choix = [];
        foreach (StatutReservation::cases() as $statut) {
            $choix[$statut->libelle()] = $statut;
        }

        return $choix;
    }
}
