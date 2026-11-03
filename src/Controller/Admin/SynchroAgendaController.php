<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Service\QrCodeGenerateur;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Synchronisation de l'agenda du salon avec l'agenda personnel de l'admin (Google, Apple, Outlook…) par abonnement iCal.
 * Routes : admin_synchro_agenda_index, admin_synchro_agenda_lien.
 */
#[IsGranted(User::ROLE_ADMIN)]
#[AdminRoute('/synchro-agenda', name: 'synchro_agenda')]
final class SynchroAgendaController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QrCodeGenerateur $qrCodes,
    ) {
    }

    #[AdminRoute('/', name: 'index', options: ['methods' => ['GET']])]
    public function index(): Response
    {
        $jeton = $this->admin()->getJetonAgenda();
        $lien = null === $jeton ? null : $this->generateUrl('app_calendrier', ['jeton' => $jeton], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->render('admin/synchro_agenda.html.twig', [
            'lien' => $lien,
            'lien_webcal' => null === $lien ? null : preg_replace('#^https?://#', 'webcal://', $lien),
            'qr_code' => null === $lien ? null : $this->qrCodes->dataUri((string) preg_replace('#^https?://#', 'webcal://', $lien), 220),
        ]);
    }

    #[AdminRoute('/lien', name: 'lien', options: ['methods' => ['POST']])]
    public function lien(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('synchro_agenda', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $admin = $this->admin();
        if ('desactiver' === $request->request->getString('action')) {
            $admin->desactiverJetonAgenda();
            $this->addFlash('success', 'Synchronisation désactivée : l\'ancien lien ne fonctionne plus.');
        } else {
            $remplace = null !== $admin->getJetonAgenda();
            $admin->regenererJetonAgenda();
            $this->addFlash('success', $remplace
                ? 'Nouveau lien créé : l\'ancien ne fonctionne plus, abonnez à nouveau votre agenda.'
                : 'Lien créé : ajoutez-le à votre agenda en suivant les étapes ci-dessous.');
        }
        $this->entityManager->flush();

        return $this->redirectToRoute('admin_synchro_agenda_index');
    }

    private function admin(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
