<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\EmailSeulType;
use App\Form\InscriptionType;
use App\Form\NouveauMotDePasseType;
use App\Service\Compte\ComptesClientes;
use App\Service\Compte\InscriptionClient;
use App\Service\Fidelite\Parrainage;
use App\Service\Fidelite\ProgrammeFidelite;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages publiques du compte cliente : création, confirmation de l'email, mot de passe oublié.
 * Les messages sont identiques que l'adresse ait un compte ou non (pas d'énumération).
 */
final class CompteAccesController extends AbstractController
{
    public function __construct(
        private readonly ComptesClientes $comptes,
        private readonly ProgrammeFidelite $fidelite,
        private readonly Parrainage $parrainage,
        #[Autowire(service: 'limiter.compte_email')] private readonly RateLimiterFactory $limiteur,
    ) {
    }

    #[Route('/inscription', name: 'app_inscription', methods: ['GET', 'POST'])]
    public function inscription(Request $request): Response
    {
        if ($this->getUser() instanceof User) {
            return $this->redirectToRoute('app_compte');
        }

        $inscription = new InscriptionClient();
        $inscription->codeParrainage = $this->parrainage->codeMemorise();
        $formulaire = $this->createForm(InscriptionType::class, $inscription);
        $formulaire->handleRequest($request);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            if (!$this->limiteur->create($request->getClientIp())->consume()->isAccepted()) {
                $this->addFlash('erreur', 'Trop de tentatives depuis votre connexion. Réessayez dans une heure.');

                return $this->redirectToRoute('app_inscription');
            }

            $this->comptes->inscrire($inscription);

            return $this->render('compte/verifiez_email.html.twig', ['email' => $inscription->email]);
        }

        return $this->render('compte/inscription.html.twig', [
            'formulaire' => $formulaire,
            'paliers' => $this->fidelite->paliers(),
        ], new Response(status: $formulaire->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** Lien partagé par une cliente : le code est mémorisé pour l'inscription ou la réservation. */
    #[Route('/parrainage/{code<[A-Za-z0-9]{4,12}>}', name: 'app_parrainage', methods: ['GET'])]
    public function parrainage(string $code): Response
    {
        $marraine = $this->parrainage->marraineParCode($code);
        if (null === $marraine || !$this->parrainage->estActif()) {
            $this->addFlash('erreur', 'Ce lien de parrainage n\'est pas valable.');

            return $this->redirectToRoute('app_accueil');
        }
        if ($this->getUser() instanceof User) {
            $this->addFlash('erreur', 'Le parrainage est réservé aux nouvelles clientes.');

            return $this->redirectToRoute('app_compte');
        }

        $this->parrainage->memoriser($code);
        $this->addFlash('succes', \sprintf('%s vous recommande le salon : créez votre compte (ou réservez en cochant « Créer mon compte ») pour recevoir vos points de bienvenue à votre premier rendez-vous.', $marraine->getPrenom()));

        return $this->redirectToRoute('app_inscription');
    }

    #[Route('/inscription/confirmer', name: 'app_inscription_confirmer', methods: ['GET'])]
    public function confirmer(Request $request): Response
    {
        $user = $this->comptes->confirmer($request);
        if (null === $user) {
            $this->addFlash('erreur', 'Ce lien n\'est plus valable. Demandez-en un nouveau ci-dessous.');

            return $this->redirectToRoute('app_inscription_renvoyer');
        }

        $this->addFlash('succes', 'Votre adresse est confirmée : vous pouvez vous connecter.');

        return $this->redirectToRoute('app_login');
    }

    #[Route('/inscription/renvoyer-le-lien', name: 'app_inscription_renvoyer', methods: ['GET', 'POST'])]
    public function renvoyer(Request $request): Response
    {
        return $this->formulaireEmail($request, 'Recevoir un nouveau lien de confirmation',
            fn (string $email) => $this->comptes->renvoyerConfirmation($email),
            'Si un compte non confirmé existe avec cette adresse, un nouveau lien vient de lui être envoyé.');
    }

    #[Route('/mot-de-passe-oublie', name: 'app_mot_de_passe_oublie', methods: ['GET', 'POST'])]
    public function motDePasseOublie(Request $request): Response
    {
        return $this->formulaireEmail($request, 'Mot de passe oublié',
            fn (string $email) => $this->comptes->demanderReinitialisation($email),
            'Si un compte existe avec cette adresse, un lien pour choisir un nouveau mot de passe vient de lui être envoyé. Il est valable une heure.');
    }

    #[Route('/mot-de-passe-oublie/nouveau', name: 'app_mot_de_passe_nouveau', methods: ['GET', 'POST'])]
    public function nouveauMotDePasse(Request $request): Response
    {
        $user = $this->comptes->compteDuLienDeReinitialisation($request);
        if (null === $user) {
            $this->addFlash('erreur', 'Ce lien n\'est plus valable (il expire au bout d\'une heure et ne sert qu\'une fois).');

            return $this->redirectToRoute('app_mot_de_passe_oublie');
        }

        $formulaire = $this->createForm(NouveauMotDePasseType::class);
        $formulaire->handleRequest($request);
        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            $this->comptes->reinitialiser($user, (string) $formulaire->get('motDePasse')->getData());
            $this->addFlash('succes', 'Mot de passe enregistré : vous pouvez vous connecter.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('compte/nouveau_mot_de_passe.html.twig', ['formulaire' => $formulaire]);
    }

    /**
     * @param callable(string): void $action
     */
    private function formulaireEmail(Request $request, string $titre, callable $action, string $confirmation): Response
    {
        $formulaire = $this->createForm(EmailSeulType::class);
        $formulaire->handleRequest($request);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            if ($this->limiteur->create($request->getClientIp())->consume()->isAccepted()) {
                $action((string) $formulaire->get('email')->getData());
            }
            // Même message dans tous les cas, limite atteinte comprise.
            $this->addFlash('succes', $confirmation);

            return $this->redirectToRoute('app_login');
        }

        return $this->render('compte/formulaire_email.html.twig', ['formulaire' => $formulaire, 'titre' => $titre]);
    }
}
