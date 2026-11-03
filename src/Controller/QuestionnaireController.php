<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\QuestionnaireType;
use App\Service\Fidelite\QuestionnaireSatisfaction;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page ouverte par le QR code de l'affiche du salon : questionnaire de satisfaction, points de fidélité en remerciement,
 * puis invitation (sans condition ni contrepartie) à laisser aussi un avis Google.
 */
final class QuestionnaireController extends AbstractController
{
    public function __construct(
        private readonly QuestionnaireSatisfaction $questionnaire,
        #[Autowire(service: 'limiter.questionnaire')] private readonly RateLimiterFactory $limiteur,
        #[Autowire(env: 'AVIS_GOOGLE_URL')] private readonly string $lienAvisGoogle,
    ) {
    }

    #[Route('/votre-avis', name: 'app_questionnaire', methods: ['GET', 'POST'])]
    public function repondre(Request $request): Response
    {
        $formulaire = $this->createForm(QuestionnaireType::class);
        $formulaire->handleRequest($request);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            if (!$this->limiteur->create($request->getClientIp())->consume()->isAccepted()) {
                $this->addFlash('erreur', 'Trop de réponses depuis votre connexion. Réessayez dans une heure.');

                return $this->redirectToRoute('app_questionnaire');
            }

            /** @var array{note: int, recommande: string, commentaire: ?string, email: string} $reponse */
            $reponse = $formulaire->getData();
            $this->questionnaire->repondre($reponse['email'], $reponse['note'], $reponse['recommande'], $reponse['commentaire']);

            return $this->redirectToRoute('app_questionnaire_merci');
        }

        return $this->render('site/questionnaire.html.twig', [
            'formulaire' => $formulaire,
            'points' => $this->questionnaire->points(),
        ], new Response(status: $formulaire->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/votre-avis/merci', name: 'app_questionnaire_merci', methods: ['GET'])]
    public function merci(): Response
    {
        return $this->render('site/questionnaire_merci.html.twig', [
            'points' => $this->questionnaire->points(),
            'lien_avis_google' => '' !== trim($this->lienAvisGoogle) ? $this->lienAvisGoogle : null,
        ]);
    }
}
