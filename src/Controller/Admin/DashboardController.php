<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Service\Statistiques\TableauDeBord;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
#[IsGranted(User::ROLE_ADMIN)]
final class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly TableauDeBord $tableauDeBord,
        private readonly RequestStack $requestStack,
        private readonly ClockInterface $clock,
    ) {
    }

    public function index(): Response
    {
        $requete = $this->requestStack->getCurrentRequest();
        $periode = $requete?->query->getString('periode', 'mois') ?? 'mois';
        if (!\array_key_exists($periode, TableauDeBord::PERIODES)) {
            $periode = 'mois';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $requete?->query->getString('date') ?? '') ?: $this->clock->now();

        return $this->render('admin/tableau_de_bord.html.twig', [
            'tdb' => $this->tableauDeBord->construire($periode, $date),
            'periodes' => TableauDeBord::PERIODES,
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Tsyynails')
            ->setLocales(['fr'])
            ->renderContentMaximized();
    }

    public function configureAssets(): Assets
    {
        return Assets::new()->addAssetMapperEntry('admin');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Tableau de bord', 'fa fa-house');

        yield MenuItem::section('Activité');
        yield MenuItem::linkToRoute('Agenda', 'fa fa-calendar-days', 'admin_agenda_index');
        yield MenuItem::linkTo(ClientCrudController::class, 'Clientes', 'fa fa-users');

        yield MenuItem::section('Catalogue');
        yield MenuItem::linkTo(PrestationCrudController::class, 'Prestations', 'fa fa-hand-sparkles');
        yield MenuItem::linkTo(PhotoCrudController::class, 'Photos', 'fa fa-images');
        yield MenuItem::linkTo(InspirationCrudController::class, 'Thèmes d\'inspiration', 'fa fa-wand-magic-sparkles');

        yield MenuItem::section('Fidélité');
        yield MenuItem::linkTo(RecompenseFideliteCrudController::class, 'Paliers de récompenses', 'fa fa-gift');
        yield MenuItem::linkToRoute('Réglages', 'fa fa-sliders', 'admin_reglages_index');

        yield MenuItem::section('Planning');
        yield MenuItem::linkTo(HoraireOuvertureCrudController::class, 'Horaires d\'ouverture', 'fa fa-clock');
        yield MenuItem::linkTo(IndisponibiliteCrudController::class, 'Congés et fermetures', 'fa fa-umbrella-beach');

        yield MenuItem::section();
        yield MenuItem::linkToRoute('Voir le site', 'fa fa-globe', 'app_accueil');
        yield MenuItem::linkToLogout('Déconnexion', 'fa fa-right-from-bracket');
    }
}
