<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Prestation;
use App\Repository\PrestationRepository;
use App\Service\Planning\CalculateurCreneaux;
use App\Service\Reservation\Tarification;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tunnel de réservation : prestation → créneau → récapitulatif.
 * Les étapes coordonnées, fidélité et acompte Stripe viendront ensuite.
 */
#[Route('/reservation')]
final class ReservationController extends AbstractController
{
    /** Nombre de jours proposés à partir d'aujourd'hui. */
    public const HORIZON_JOURS = 28;

    private const FORMAT_CRENEAU = 'Y-m-d\TH:i';

    public function __construct(
        private readonly PrestationRepository $prestations,
        private readonly CalculateurCreneaux $calculateur,
        private readonly Tarification $tarification,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'app_reservation', methods: ['GET'])]
    public function prestation(): Response
    {
        return $this->render('reservation/prestation.html.twig', [
            'prestations' => $this->prestations->findActives(),
        ]);
    }

    #[Route('/{id<\d+>}', name: 'app_reservation_creneau', methods: ['GET'])]
    public function creneau(#[MapEntity] Prestation $prestation, Request $request): Response
    {
        $this->exigerActive($prestation);

        $aujourdhui = $this->clock->now()->setTime(0, 0);
        $creneauxParJour = $this->calculateur->creneauxParJour($prestation, $aujourdhui, self::HORIZON_JOURS);

        $jourDemande = $request->query->getString('jour');
        if (!\array_key_exists($jourDemande, $creneauxParJour)) {
            // Par défaut : le premier jour qui a des créneaux libres.
            $jourDemande = array_key_first(array_filter($creneauxParJour)) ?? array_key_first($creneauxParJour);
        }

        return $this->render('reservation/creneau.html.twig', [
            'prestation' => $prestation,
            'creneaux_par_jour' => $creneauxParJour,
            'jour' => $jourDemande,
            'format_creneau' => self::FORMAT_CRENEAU,
        ]);
    }

    #[Route('/{id<\d+>}/{creneau<\d{4}-\d{2}-\d{2}T\d{2}:\d{2}>}', name: 'app_reservation_recapitulatif', methods: ['GET'])]
    public function recapitulatif(#[MapEntity] Prestation $prestation, string $creneau): Response
    {
        $this->exigerActive($prestation);

        $debut = \DateTimeImmutable::createFromFormat('!'.self::FORMAT_CRENEAU, $creneau);
        if (false === $debut || !$this->calculateur->estDisponible($prestation, $debut)) {
            $this->addFlash('erreur', 'Ce créneau n\'est plus disponible. Choisissez-en un autre.');

            return $this->redirectToRoute('app_reservation_creneau', ['id' => $prestation->getId()]);
        }

        return $this->render('reservation/recapitulatif.html.twig', [
            'prestation' => $prestation,
            'debut' => $debut,
            'fin' => $debut->modify(\sprintf('+%d minutes', $prestation->getDureeMinutes())),
            'acompte' => $this->tarification->acompteCentimes($prestation),
        ]);
    }

    private function exigerActive(Prestation $prestation): void
    {
        if (!$prestation->isActive()) {
            throw new NotFoundHttpException('Prestation indisponible.');
        }
    }
}
