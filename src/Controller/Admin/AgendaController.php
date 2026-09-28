<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Service\Planning\Agenda;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Agenda des rendez-vous : jour, semaine ou mois. Route : admin_agenda_index.
 */
#[IsGranted(User::ROLE_ADMIN)]
#[AdminRoute('/agenda', name: 'agenda')]
final class AgendaController extends AbstractController
{
    public function __construct(
        private readonly Agenda $agenda,
        private readonly ClockInterface $clock,
    ) {
    }

    #[AdminRoute('/', name: 'index', options: ['methods' => ['GET']])]
    public function index(Request $request): Response
    {
        $vue = $request->query->getString('vue', 'semaine');
        if (!\in_array($vue, Agenda::VUES, true)) {
            $vue = 'semaine';
        }

        $aujourdhui = $this->clock->now()->setTime(0, 0);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $request->query->getString('date')) ?: $aujourdhui;

        return $this->render('admin/agenda.html.twig', [
            'agenda' => $this->agenda->construire($vue, $date),
            'date' => $date,
            'aujourdhui' => $aujourdhui,
        ]);
    }
}
