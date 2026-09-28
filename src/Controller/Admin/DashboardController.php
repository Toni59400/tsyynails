<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
#[IsGranted(User::ROLE_ADMIN)]
final class DashboardController extends AbstractDashboardController
{
    public function index(): Response
    {
        return $this->render('admin/tableau_de_bord.html.twig');
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Tsyynails')
            ->setLocales(['fr'])
            ->renderContentMaximized();
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Tableau de bord', 'fa fa-house');

        yield MenuItem::section('Activité');
        yield MenuItem::linkTo(ReservationCrudController::class, 'Réservations', 'fa fa-calendar-check');
        yield MenuItem::linkTo(ClientCrudController::class, 'Clientes', 'fa fa-users');

        yield MenuItem::section('Catalogue');
        yield MenuItem::linkTo(PrestationCrudController::class, 'Prestations', 'fa fa-hand-sparkles');

        yield MenuItem::section('Planning');
        yield MenuItem::linkTo(HoraireOuvertureCrudController::class, 'Horaires d\'ouverture', 'fa fa-clock');
        yield MenuItem::linkTo(IndisponibiliteCrudController::class, 'Congés et fermetures', 'fa fa-umbrella-beach');

        yield MenuItem::section();
        yield MenuItem::linkToRoute('Voir le site', 'fa fa-globe', 'app_apres_connexion');
        yield MenuItem::linkToLogout('Déconnexion', 'fa fa-right-from-bracket');
    }
}
