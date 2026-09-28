<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Parametre;
use App\Entity\User;
use App\Repository\ParametreRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Réglages de la réservation et du programme de fidélité. Route : admin_reglages_index.
 */
#[IsGranted(User::ROLE_ADMIN)]
#[AdminRoute('/reglages', name: 'reglages')]
final class ReglagesController extends AbstractController
{
    public function __construct(
        private readonly ParametreRepository $parametres,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
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

        return $this->render('admin/reglages.html.twig', ['reglages' => $reglages], new Response(status: [] === $erreurs ? 200 : 422));
    }
}
