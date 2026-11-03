<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Service\Fidelite\QuestionnaireSatisfaction;
use App\Service\QrCodeGenerateur;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Affiche A4 à imprimer pour le salon : QR code vers le questionnaire de satisfaction. Route : admin_affiche_avis_index.
 */
#[IsGranted(User::ROLE_ADMIN)]
#[AdminRoute('/affiche-avis', name: 'affiche_avis')]
final class AfficheAvisController extends AbstractController
{
    public function __construct(
        private readonly QrCodeGenerateur $qrCodes,
        private readonly QuestionnaireSatisfaction $questionnaire,
    ) {
    }

    #[AdminRoute('/', name: 'index')]
    public function index(): Response
    {
        $lien = $this->generateUrl('app_questionnaire', [], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->render('admin/affiche_avis.html.twig', [
            'lien' => $lien,
            'qr_code' => $this->qrCodes->dataUri($lien, 520),
            'points' => $this->questionnaire->points(),
        ]);
    }
}
