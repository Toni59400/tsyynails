<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Parametre;
use App\Entity\User;
use App\Form\ModificationRendezVous;
use App\Form\ModificationRendezVousType;
use App\Form\RendezVousSalon;
use App\Form\RendezVousSalonType;
use App\Repository\ParametreRepository;
use App\Repository\ReservationRepository;
use App\Service\Reservation\CreneauIndisponibleException;
use App\Service\Reservation\ReservationWorkflow;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Rendez-vous pris par la prothésiste (téléphone, au salon, RDV duo) : confirmé d'emblée, sans acompte en ligne.
 * Routes : admin_rendez_vous_nouveau, admin_rendez_vous_modifier.
 */
#[IsGranted(User::ROLE_ADMIN)]
#[AdminRoute('/rendez-vous', name: 'rendez_vous')]
final class RendezVousSalonController extends AbstractController
{
    public function __construct(
        private readonly ReservationWorkflow $workflow,
        private readonly ParametreRepository $parametres,
        private readonly ReservationRepository $reservations,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    #[AdminRoute('/nouveau', name: 'nouveau', options: ['methods' => ['GET', 'POST']])]
    public function nouveau(Request $request): Response
    {
        $rendezVous = new RendezVousSalon();
        $rendezVous->remiseDuo = $this->parametres->valeur(Parametre::DUO_REDUCTION_POURCENTAGE);
        // Pré-rempli depuis l'agenda (?date=2026-11-03&heure=19:00).
        $rendezVous->date = \DateTimeImmutable::createFromFormat('!Y-m-d', $request->query->getString('date')) ?: null;
        $rendezVous->heure = \DateTimeImmutable::createFromFormat('!H:i', $request->query->getString('heure')) ?: null;

        $formulaire = $this->createForm(RendezVousSalonType::class, $rendezVous);
        $formulaire->handleRequest($request);

        if ($formulaire->isSubmitted() && $formulaire->isValid() && null !== $rendezVous->prestation) {
            /** @var User $admin */
            $admin = $this->getUser();
            try {
                $reservation = $this->workflow->reserverParLeSalon(
                    $rendezVous->prestation,
                    $rendezVous->debut(),
                    $rendezVous->coordonnees(),
                    $admin->getUserIdentifier(),
                    $rendezVous->supplement,
                    $rendezVous->duo ? $rendezVous->prestationDuo : null,
                    // Duo : réduction saisie ; sinon automatique (réduction à vie de la cliente, s'il y en a une).
                    $rendezVous->duo ? (int) $rendezVous->remiseDuo : null,
                );
                $this->addFlash('success', \sprintf('Rendez-vous de %s confirmé le %s.', $reservation->getClient()->getPrenom(), $reservation->getDebut()->format('d/m à H\hi')));

                return $this->redirect($this->adminUrlGenerator->setController(ReservationCrudController::class)->setAction('detail')->setEntityId($reservation->getId())->generateUrl());
            } catch (CreneauIndisponibleException) {
                $formulaire->get('heure')->addError(new FormError('Ce créneau chevauche un autre rendez-vous ou une fermeture.'));
            } catch (\InvalidArgumentException $erreur) {
                $formulaire->addError(new FormError($erreur->getMessage()));
            }
        }

        return $this->render('admin/rendez_vous_nouveau.html.twig', [
            'formulaire' => $formulaire,
        ], new Response(status: $formulaire->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * Modification d'un rendez-vous à venir (en attente ou confirmé). Route : admin_rendez_vous_modifier.
     * Avec un acompte par carte, la prestation est figée ; horaire, supplément et remise restent modifiables.
     */
    #[AdminRoute('/{id}/modifier', name: 'modifier', options: ['methods' => ['GET', 'POST'], 'requirements' => ['id' => '\d+']])]
    public function modifier(int $id, Request $request): Response
    {
        $reservation = $this->reservations->find($id) ?? throw $this->createNotFoundException();
        $detail = $this->adminUrlGenerator->setController(ReservationCrudController::class)->setAction('detail')->setEntityId($reservation->getId())->generateUrl();

        $modification = ModificationRendezVous::depuis($reservation);
        $formulaire = $this->createForm(ModificationRendezVousType::class, $modification, ['prestations_figees' => $reservation->aUnAcompteCarte()]);
        $formulaire->handleRequest($request);

        if ($formulaire->isSubmitted() && $formulaire->isValid() && null !== $modification->prestation) {
            /** @var User $admin */
            $admin = $this->getUser();
            try {
                $prevenue = $this->workflow->modifier(
                    $reservation,
                    $modification->prestation,
                    $modification->debut(),
                    $modification->supplement,
                    $modification->duo ? $modification->prestationDuo : null,
                    (int) $modification->remise,
                    $admin->getUserIdentifier(),
                    $modification->prevenir,
                );
                $this->addFlash('success', 'Rendez-vous modifié'.($prevenue ? ' : la cliente a reçu le récapitulatif à jour.' : '.'));

                return $this->redirect($detail);
            } catch (CreneauIndisponibleException) {
                $formulaire->get('heure')->addError(new FormError('Ce créneau chevauche un autre rendez-vous ou une fermeture.'));
            } catch (\LogicException|\InvalidArgumentException $erreur) {
                $formulaire->addError(new FormError($erreur->getMessage()));
            }
        }

        return $this->render('admin/rendez_vous_modifier.html.twig', [
            'formulaire' => $formulaire,
            'reservation' => $reservation,
            'detail' => $detail,
        ], new Response(status: $formulaire->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
