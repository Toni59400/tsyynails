<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Client;
use App\Entity\User;
use App\Enum\StatutReservation;
use App\Form\CoordonneesCompteType;
use App\Repository\ClientRepository;
use App\Repository\MouvementPointsRepository;
use App\Repository\ReservationRepository;
use App\Service\Compte\ComptesClientes;
use App\Service\Compte\CoordonneesCompte;
use App\Service\Fidelite\Parrainage;
use App\Service\Fidelite\ProgrammeFidelite;
use App\Service\Reservation\CoordonneesCliente;
use App\Service\Securite\ChiffrementDonnees;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Espace cliente. Aucune route ne prend d'identifiant : chaque page ne montre que la fiche
 * rattachée au compte connecté (pas d'accès possible aux données d'une autre cliente).
 */
#[Route('/compte')]
#[IsGranted(User::ROLE_CLIENT)]
final class CompteController extends AbstractController
{
    public function __construct(
        private readonly ComptesClientes $comptes,
        private readonly ProgrammeFidelite $fidelite,
        private readonly ReservationRepository $reservations,
        private readonly MouvementPointsRepository $mouvements,
        private readonly ClientRepository $clientes,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'app_compte', methods: ['GET'])]
    public function index(): Response
    {
        $client = $this->fiche();

        return $this->render('compte/index.html.twig', [
            'client' => $client,
            'fidelite' => null === $client ? null : $this->fidelite->etat($client),
            'prochains' => null === $client ? [] : array_values(array_filter(
                $this->reservations->findPourClient($client, 10),
                fn ($r): bool => $r->getFin() > $this->clock->now() && \in_array($r->getStatut(), StatutReservation::bloquantLeCreneau(), true),
            )),
        ]);
    }

    #[Route('/rendez-vous', name: 'app_compte_rendez_vous', methods: ['GET'])]
    public function rendezVous(): Response
    {
        $client = $this->fiche();

        return $this->render('compte/rendez_vous.html.twig', [
            'client' => $client,
            'reservations' => null === $client ? [] : $this->reservations->findPourClient($client, 100),
        ]);
    }

    #[Route('/fidelite', name: 'app_compte_fidelite', methods: ['GET'])]
    public function fidelite(Parrainage $parrainage): Response
    {
        $client = $this->fiche();

        return $this->render('compte/fidelite.html.twig', [
            'client' => $client,
            'fidelite' => null === $client ? null : $this->fidelite->etat($client),
            'mouvements' => null === $client ? [] : $this->mouvements->historiquePour($client),
            'parrainage' => null !== $client && $parrainage->estActif() ? $parrainage->etat($client) : null,
        ]);
    }

    #[Route('/donnees', name: 'app_compte_donnees', methods: ['GET', 'POST'])]
    public function donnees(Request $request): Response
    {
        $client = $this->fiche();
        $formulaire = null;

        if (null !== $client) {
            $coordonnees = new CoordonneesCompte();
            $coordonnees->prenom = $client->getPrenom();
            $coordonnees->nom = $client->getNom();
            $coordonnees->telephone = (string) $client->getTelephone();

            $formulaire = $this->createForm(CoordonneesCompteType::class, $coordonnees);
            $formulaire->handleRequest($request);
            if ($formulaire->isSubmitted() && $formulaire->isValid()) {
                $telephone = (string) CoordonneesCliente::normaliserTelephone($coordonnees->telephone);
                $autre = $this->clientes->findOneBy(['telephone' => $telephone]);
                if (null !== $autre && $autre !== $client) {
                    $this->addFlash('erreur', 'Ce numéro est déjà utilisé. Contactez le salon si c\'est bien le vôtre.');
                } else {
                    $client->setPrenom($coordonnees->prenom)->setNom($coordonnees->nom)->setTelephone($telephone);
                    $this->entityManager->flush();
                    $this->addFlash('succes', 'Vos coordonnées sont enregistrées.');

                    return $this->redirectToRoute('app_compte_donnees');
                }
            }
        }

        return $this->render('compte/donnees.html.twig', ['client' => $client, 'formulaire' => $formulaire]);
    }

    /**
     * Droit d'accès et de portabilité (RGPD) : toutes les données de la cliente, au format JSON.
     */
    #[Route('/donnees/export', name: 'app_compte_export', methods: ['GET'])]
    public function export(ChiffrementDonnees $chiffrement): JsonResponse
    {
        $user = $this->utilisateur();
        $client = $this->fiche();

        $donnees = [
            'exporte_le' => $this->clock->now()->format(\DATE_ATOM),
            'compte' => [
                'email' => $user->getEmail(),
                'cree_le' => $user->getCreatedAt()->format(\DATE_ATOM),
                'email_confirme_le' => $user->getEmailVerifieAt()?->format(\DATE_ATOM),
            ],
        ];

        if (null !== $client) {
            $donnees['fiche'] = [
                'prenom' => $client->getPrenom(),
                'nom' => $client->getNom(),
                'telephone' => $client->getTelephone(),
                'email' => $client->getEmail(),
                'creee_le' => $client->getCreatedAt()->format(\DATE_ATOM),
                'derniere_visite' => $client->getDerniereVisiteAt()?->format(\DATE_ATOM),
                'notes_sante' => null === $client->getNotesSanteChiffrees() ? null : $chiffrement->dechiffrer($client->getNotesSanteChiffrees()),
                'consentement_sante_le' => $client->getConsentementSanteAt()?->format(\DATE_ATOM),
            ];
            $donnees['rendez_vous'] = array_map(static fn ($r): array => [
                'date' => $r->getDebut()->format(\DATE_ATOM),
                'prestation' => $r->getPrestation()->getNom(),
                'statut' => $r->getStatut()->libelle(),
                'prix_euros' => $r->getPrixCentimes() / 100,
                'acompte_euros' => $r->getAcompteCentimes() / 100,
                'reduction_euros' => $r->getReductionCentimes() / 100,
            ], $this->reservations->findPourClient($client, 1000));
            $donnees['points'] = [
                'solde' => $this->fidelite->solde($client),
                'mouvements' => array_map(static fn ($m): array => [
                    'date' => $m->getCreatedAt()->format(\DATE_ATOM),
                    'points' => $m->getDelta(),
                    'motif' => $m->getMotif()->libelle(),
                ], $this->mouvements->historiquePour($client)),
            ];
        }

        $reponse = new JsonResponse($donnees, json: false);
        $reponse->setEncodingOptions(\JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $reponse->headers->set('Content-Disposition', $reponse->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'mes-donnees-tsyynails.json'));
        $reponse->headers->set('Cache-Control', 'no-store');

        return $reponse;
    }

    /**
     * Droit à l'effacement : le compte est supprimé, la fiche anonymisée (les rendez-vous
     * passés restent pour la comptabilité, sans identité). Impossible avec un rendez-vous à venir.
     */
    #[Route('/supprimer', name: 'app_compte_supprimer', methods: ['POST'])]
    public function supprimer(Request $request, Security $security): Response
    {
        if (!$this->isCsrfTokenValid('supprimer_compte', $request->request->getString('_token')) || !$request->request->getBoolean('confirmation')) {
            $this->addFlash('erreur', 'Cochez la case de confirmation pour supprimer votre compte.');

            return $this->redirectToRoute('app_compte_donnees');
        }

        $user = $this->utilisateur();
        if ($user->isAdmin()) {
            throw $this->createAccessDeniedException();
        }

        $client = $this->fiche();
        if (null !== $client) {
            foreach ($this->reservations->findPourClient($client, 1000) as $reservation) {
                if ($reservation->getFin() > $this->clock->now() && \in_array($reservation->getStatut(), StatutReservation::bloquantLeCreneau(), true)) {
                    $this->addFlash('erreur', 'Vous avez un rendez-vous à venir : contactez le salon pour l\'annuler avant de supprimer votre compte.');

                    return $this->redirectToRoute('app_compte_donnees');
                }
            }
            $client->anonymiser($this->clock->now());
        }

        $security->logout(false);
        $this->entityManager->remove($user);
        $this->entityManager->flush();
        $this->logger->notice('Compte cliente supprimé à sa demande.', ['fiche' => $client?->getId()]);

        $this->addFlash('succes', 'Votre compte est supprimé et vos données personnelles effacées.');

        return $this->redirectToRoute('app_accueil');
    }

    private function utilisateur(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function fiche(): ?Client
    {
        return $this->comptes->ficheDe($this->utilisateur());
    }
}
