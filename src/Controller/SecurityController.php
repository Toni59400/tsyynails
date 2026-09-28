<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route('/connexion', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils, Request $request): Response
    {
        if ($this->getUser() instanceof User) {
            return $this->redirectToRoute('app_apres_connexion');
        }

        return $this->render('security/login.html.twig', [
            'dernier_email' => $authenticationUtils->getLastUsername(),
            'erreur' => $authenticationUtils->getLastAuthenticationError(),
            'cible' => self::cibleInterne($request->query->getString('cible')),
        ]);
    }

    /**
     * Page où revenir après connexion (tunnel de réservation) : uniquement un chemin du site,
     * jamais une adresse externe (redirection ouverte).
     */
    public static function cibleInterne(string $cible): ?string
    {
        // Commence par un seul « / » (pas « //domaine ») et ne contient que des caractères de chemin.
        return 1 === preg_match('#^/(?!/)[A-Za-z0-9/_.:-]*$#', $cible) ? $cible : null;
    }

    #[Route('/deconnexion', name: 'app_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('Intercepté par le pare-feu (logout).');
    }

    #[Route('/apres-connexion', name: 'app_apres_connexion', methods: ['GET'])]
    public function apresConnexion(): Response
    {
        $user = $this->getUser();
        if ($user instanceof User && $user->isAdmin()) {
            return $this->redirectToRoute('admin');
        }

        return $this->redirectToRoute('app_compte');
    }
}
