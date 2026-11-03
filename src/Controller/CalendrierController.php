<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Planning\FluxIcs;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lien d'abonnement à l'agenda du salon (flux iCal), lu par Google Agenda, Apple Calendrier, Outlook…
 * Ces applications ne savent pas se connecter : l'accès repose sur le jeton secret du lien (64 caractères aléatoires),
 * régénérable ou désactivable depuis l'admin (Agenda → Synchroniser mon agenda).
 */
final class CalendrierController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly FluxIcs $flux,
    ) {
    }

    #[Route('/calendrier/{jeton<[a-f0-9]{64}>}.ics', name: 'app_calendrier', methods: ['GET'])]
    public function flux(string $jeton): Response
    {
        $user = $this->users->findOneBy(['jetonAgenda' => $jeton]);
        if (!$user instanceof User || !$user->isAdmin()) {
            throw $this->createNotFoundException();
        }

        return new Response($this->flux->generer(), Response::HTTP_OK, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="agenda.ics"',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
