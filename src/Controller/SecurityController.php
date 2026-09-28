<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route('/connexion', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser() instanceof User) {
            return $this->redirectToRoute('app_apres_connexion');
        }

        return $this->render('security/login.html.twig', [
            'dernier_email' => $authenticationUtils->getLastUsername(),
            'erreur' => $authenticationUtils->getLastAuthenticationError(),
        ]);
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

        // Espace cliente à venir : retour à l'accueil en attendant.
        return $this->redirect('/');
    }
}
