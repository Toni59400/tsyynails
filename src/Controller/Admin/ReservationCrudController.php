<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Reservation;
use App\Entity\User;
use App\Enum\StatutReservation;
use App\Service\Reservation\ReservationWorkflow;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Les réservations sont créées par le tunnel de réservation. La fiche détaillée propose
 * les actions possibles selon le statut (valider, refuser…), exécutées par ReservationWorkflow.
 *
 * @extends AbstractCrudController<Reservation>
 */
final class ReservationCrudController extends AbstractCrudController
{
    /** Action du formulaire → méthode du workflow et message de confirmation. */
    private const ACTIONS = [
        'valider' => ['valider', 'Rendez-vous confirmé : l\'acompte est débité et la cliente prévenue par email.'],
        'refuser' => ['refuser', 'Demande refusée : l\'empreinte est libérée et la cliente prévenue par email.'],
        'annuler' => ['annuler', 'Réservation annulée, la cliente est prévenue par email.'],
        'annuler-salon' => ['annulerParLeSalon', 'Réservation annulée à votre initiative : acompte remboursé, la cliente est prévenue par email.'],
        'honorer' => ['honorer', 'Rendez-vous honoré : les points de fidélité sont crédités.'],
        'non-honorer' => ['nonHonorer', 'Absence enregistrée.'],
    ];

    public function __construct(
        private readonly ReservationWorkflow $workflow,
        private readonly ClockInterface $clock,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Reservation::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Réservation')
            ->setEntityLabelInPlural('Réservations')
            ->setDefaultSort(['debut' => 'DESC'])
            ->setPageTitle(Crud::PAGE_DETAIL, static fn (Reservation $r): string => $r->getClient()->getNomComplet().' · '.$r->getPrestation()->getNom())
            ->overrideTemplate('crud/detail', 'admin/reservation/detail.html.twig');
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
            StatutReservation::PAIEMENT_EN_COURS->value => 'secondary',
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
        yield DateTimeField::new('acompteRembourseAt', 'Acompte remboursé le')->onlyOnDetail()->setFormat('dd/MM/yyyy HH:mm');
        yield DateTimeField::new('rappelEnvoyeAt', 'Rappel envoyé le')->onlyOnDetail()->setFormat('dd/MM/yyyy HH:mm');
    }

    /**
     * Actions sur une réservation depuis sa fiche. Route : admin_reservation_workflow.
     *
     * @param AdminContext<Reservation> $context
     */
    #[AdminRoute('/{entityId}/{action}', name: 'workflow', options: ['methods' => ['POST'], 'requirements' => ['action' => 'valider|refuser|annuler|annuler-salon|honorer|non-honorer']])]
    public function executerAction(AdminContext $context, Request $request, string $action): RedirectResponse
    {
        $reservation = $context->getEntity()->getInstance();
        if (!$reservation instanceof Reservation) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('reservation_'.$action.'_'.$reservation->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        /** @var User $admin */
        $admin = $this->getUser();
        [$methode, $message] = self::ACTIONS[$action];

        try {
            $this->workflow->{$methode}($reservation, $admin->getUserIdentifier());
            $this->addFlash('success', $message);
        } catch (\LogicException $erreur) {
            $this->addFlash('danger', $erreur->getMessage());
        } catch (\Throwable $erreur) {
            $this->addFlash('danger', 'Stripe n\'a pas pu traiter l\'opération. Réessayez dans quelques minutes.');
        }

        return $this->redirectToRoute('admin_reservation_detail', ['entityId' => $reservation->getId()]);
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        $reservation = Crud::PAGE_DETAIL === $responseParameters->get('pageName') ? $responseParameters->get('entity')->getInstance() : null;
        if ($reservation instanceof Reservation) {
            $responseParameters->set('actions_workflow', $this->actionsPossibles($reservation));
            $responseParameters->set('annulation_gratuite', $this->workflow->annulationGratuite($reservation));
            $responseParameters->set('limite_annulation', $this->workflow->limiteAnnulationGratuite($reservation));
        }

        return $responseParameters;
    }

    /**
     * Actions proposées selon le statut et la date, pour la fiche détaillée.
     *
     * @return list<string>
     */
    public function actionsPossibles(Reservation $reservation): array
    {
        $passe = $reservation->getDebut() <= $this->clock->now();

        return match ($reservation->getStatut()) {
            StatutReservation::EN_ATTENTE => ['valider', 'refuser'],
            StatutReservation::CONFIRMEE => $passe ? ['honorer', 'non-honorer'] : ['annuler', 'annuler-salon'],
            StatutReservation::PAIEMENT_EN_COURS => ['annuler'],
            default => [],
        };
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
