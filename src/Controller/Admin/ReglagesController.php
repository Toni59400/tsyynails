<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Parametre;
use App\Entity\User;
use App\Repository\ParametreRepository;
use App\Service\Seo\IndexNow;
use App\Service\Seo\PagesPubliques;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Réglages de la réservation et du programme de fidélité. Routes : admin_reglages_index, admin_reglages_indexnow.
 */
#[IsGranted(User::ROLE_ADMIN)]
#[AdminRoute('/reglages', name: 'reglages')]
final class ReglagesController extends AbstractController
{
    public function __construct(
        private readonly ParametreRepository $parametres,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly IndexNow $indexNow,
        private readonly PagesPubliques $pages,
    ) {
    }

    #[AdminRoute('/', name: 'index', options: ['methods' => ['GET', 'POST']])]
    public function index(Request $request): Response
    {
        $erreurs = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('reglages', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $valeurs = $request->request->all('reglages');
            foreach (Parametre::DEFINITIONS as $cle => [$libelle, , , $min, $max]) {
                $valeur = filter_var($valeurs[str_replace('.', '_', $cle)] ?? null, \FILTER_VALIDATE_INT);
                if (false === $valeur || $valeur < $min || $valeur > $max) {
                    $erreurs[$cle] = \sprintf('%s : nombre entier entre %d et %d.', $libelle, $min, $max);
                    continue;
                }

                $parametre = $this->parametres->find($cle) ?? new Parametre($cle, (string) $valeur);
                $parametre->setValeur((string) $valeur);
                $this->entityManager->persist($parametre);
            }

            if ([] === $erreurs) {
                $this->entityManager->flush();
                /** @var User $admin */
                $admin = $this->getUser();
                $this->logger->notice('Réglages modifiés.', ['par' => $admin->getUserIdentifier()]);
                $this->addFlash('success', 'Réglages enregistrés.');

                return $this->redirectToRoute('admin_reglages_index');
            }
        }

        $reglages = [];
        foreach (Parametre::DEFINITIONS as $cle => [$libelle, $aide, , $min, $max]) {
            $reglages[] = [
                'nom' => str_replace('.', '_', $cle),
                'libelle' => $libelle,
                'aide' => $aide,
                'valeur' => $this->parametres->valeur($cle),
                'min' => $min,
                'max' => $max,
                'erreur' => $erreurs[$cle] ?? null,
            ];
        }

        return $this->render('admin/reglages.html.twig', [
            'reglages' => $reglages,
            'indexnow_actif' => $this->indexNow->estActive(),
        ], new Response(status: [] === $erreurs ? 200 : 422));
    }

    /** Signale tout le site à Bing (IndexNow) : utile à la mise en service ou après de gros changements. */
    #[AdminRoute('/indexnow', name: 'indexnow', options: ['methods' => ['POST']])]
    public function indexNow(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('indexnow', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $urls = $this->pages->urls();
        if ($this->indexNow->soumettre($urls, $this->pages->urlCleIndexNow())) {
            $this->addFlash('success', \sprintf('%d pages signalées à Bing (IndexNow). Elles seront relues dans les prochaines heures.', \count($urls)));
        } else {
            $this->addFlash('danger', 'Signalement impossible pour le moment. Réessayez plus tard.');
        }

        return $this->redirectToRoute('admin_reglages_index');
    }
}
