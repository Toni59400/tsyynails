<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Client;
use App\Entity\Prestation;
use App\Entity\RecompenseFidelite;
use App\Entity\Reservation;
use App\Entity\User;
use App\Enum\StatutReservation;
use App\Form\CoordonneesType;
use App\Repository\PrestationRepository;
use App\Repository\ReservationRepository;
use App\Service\Compte\ComptesClientes;
use App\Service\Fidelite\ProgrammeFidelite;
use App\Service\Paiement\PaiementGateway;
use App\Service\Planning\CalculateurCreneaux;
use App\Service\Reservation\CoordonneesCliente;
use App\Service\Reservation\CreneauIndisponibleException;
use App\Service\Reservation\ReservationWorkflow;
use App\Service\Reservation\Tarification;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tunnel de réservation : prestation → créneau → coordonnées → empreinte bancaire → suivi.
 */
#[Route('/reservation')]
final class ReservationController extends AbstractController
{
    private const FORMAT_CRENEAU = 'Y-m-d\TH:i';

    public function __construct(
        private readonly PrestationRepository $prestations,
        private readonly ReservationRepository $reservations,
        private readonly CalculateurCreneaux $calculateur,
        private readonly Tarification $tarification,
        private readonly ReservationWorkflow $workflow,
        private readonly PaiementGateway $paiement,
        private readonly ProgrammeFidelite $fidelite,
        private readonly ComptesClientes $comptes,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
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

        $horizon = $this->fidelite->horizonJours($this->ficheConnectee());
        $aujourdhui = $this->clock->now()->setTime(0, 0);
        $creneauxParJour = $this->calculateur->creneauxParJour($prestation, $aujourdhui, $horizon);

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
            'horizon_fidele' => $horizon > ProgrammeFidelite::HORIZON_STANDARD_JOURS,
        ]);
    }

    /**
     * Récapitulatif et coordonnées. La demande n'est créée qu'à l'envoi du formulaire.
     * Cliente connectée : coordonnées connues et choix d'une récompense fidélité.
     * Sinon : connexion proposée, ou création du compte en même temps que la demande.
     */
    #[Route('/{id<\d+>}/{creneau<\d{4}-\d{2}-\d{2}T\d{2}:\d{2}>}', name: 'app_reservation_recapitulatif', methods: ['GET', 'POST'])]
    public function recapitulatif(
        #[MapEntity] Prestation $prestation,
        string $creneau,
        Request $request,
        #[Autowire(service: 'limiter.demande_reservation')] RateLimiterFactory $limiteur,
    ): Response {
        $this->exigerActive($prestation);
        $user = $this->getUser();
        $cliente = $this->ficheConnectee();

        // L'horizon (28 jours, plus pour les fidèles) est vérifié ici aussi : l'URL peut être forgée.
        $debut = \DateTimeImmutable::createFromFormat('!'.self::FORMAT_CRENEAU, $creneau);
        $limite = $this->clock->now()->setTime(0, 0)->modify(\sprintf('+%d days', $this->fidelite->horizonJours($cliente)));
        if (false === $debut || $debut >= $limite || !$this->calculateur->estDisponible($prestation, $debut)) {
            return $this->creneauPris($prestation);
        }

        $coordonnees = new CoordonneesCliente();
        $recompenses = [];
        if (null !== $cliente && $user instanceof User) {
            $coordonnees->prenom = $cliente->getPrenom();
            $coordonnees->nom = $cliente->getNom();
            $coordonnees->telephone = (string) $cliente->getTelephone();
            $coordonnees->email = $user->getEmail();
            $recompenses = $this->fidelite->utilisablesEnLigne($cliente, $prestation);
        } elseif ($user instanceof User) {
            $coordonnees->email = $user->getEmail();
        }

        $formulaire = $this->createForm(CoordonneesType::class, $coordonnees, [
            'connectee' => null !== $cliente,
            'avec_compte' => !$user instanceof User,
            'recompenses' => $recompenses,
        ]);
        $formulaire->handleRequest($request);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            $retour = $this->redirectToRoute('app_reservation_recapitulatif', ['id' => $prestation->getId(), 'creneau' => $creneau]);
            if (!$limiteur->create($request->getClientIp())->consume()->isAccepted()) {
                $this->addFlash('erreur', 'Trop de demandes depuis votre connexion. Réessayez dans une heure ou appelez-moi.');

                return $retour;
            }

            $recompense = null !== $cliente ? $formulaire->get('recompense')->getData() : null;

            try {
                $reservation = $this->workflow->demander($prestation, $debut, $coordonnees, $cliente, $recompense instanceof RecompenseFidelite ? $recompense : null);
            } catch (CreneauIndisponibleException) {
                return $this->creneauPris($prestation);
            } catch (\LogicException $erreur) {
                $this->addFlash('erreur', $erreur->getMessage());

                return $retour;
            } catch (\Throwable $erreur) {
                $this->logger->error('Demande de réservation impossible.', ['erreur' => $erreur->getMessage()]);
                $this->addFlash('erreur', 'Le paiement en ligne est momentanément indisponible. Réessayez plus tard ou appelez-moi.');

                return $retour;
            }

            if (!$user instanceof User && $coordonnees->creerCompte) {
                $this->addFlash('succes', $this->comptes->inscrireDepuisReservation($reservation, $coordonnees->email, (string) $coordonnees->motDePasse)
                    ? 'Votre compte est créé : confirmez votre adresse avec le lien reçu par email pour cumuler vos points.'
                    : 'Un compte existe déjà avec cette adresse : connectez-vous pour retrouver vos points.');
            }

            return $this->redirectToRoute('app_reservation_paiement', ['jeton' => $reservation->getJetonSuivi()]);
        }

        return $this->render('reservation/recapitulatif.html.twig', [
            'prestation' => $prestation,
            'debut' => $debut,
            'fin' => $debut->modify(\sprintf('+%d minutes', $prestation->getDureeMinutes())),
            'acompte' => $this->tarification->acompteCentimes($prestation),
            'formulaire' => $formulaire,
            'cliente' => $cliente,
            'solde' => null === $cliente ? null : $this->fidelite->solde($cliente),
            'points_gagnes' => $this->fidelite->pointsPour($prestation->getPrixCentimes()),
            'cible_connexion' => $request->getPathInfo(),
        ], new Response(status: $formulaire->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/paiement/{jeton<[A-Za-z0-9_-]{22}>}', name: 'app_reservation_paiement', methods: ['GET'])]
    public function paiement(string $jeton): Response
    {
        $reservation = $this->reservationParJeton($jeton);
        if (StatutReservation::PAIEMENT_EN_COURS !== $reservation->getStatut() || null === $reservation->getStripePaymentIntentId()) {
            return $this->redirectToRoute('app_reservation_suivi', ['jeton' => $jeton]);
        }

        return $this->render('reservation/paiement.html.twig', [
            'reservation' => $reservation,
            'simulation' => $this->paiement->estSimulation(),
            'cle_publique' => $this->paiement->clePublique(),
            'secret_client' => $this->paiement->secretClient($reservation->getStripePaymentIntentId()),
        ]);
    }

    /**
     * Page de suivi (retour de Stripe après la saisie de la carte, lien des emails).
     * Si le webhook n'est pas encore passé, l'état de l'empreinte est vérifié directement auprès de Stripe.
     */
    #[Route('/suivi/{jeton<[A-Za-z0-9_-]{22}>}', name: 'app_reservation_suivi', methods: ['GET'])]
    public function suivi(string $jeton): Response
    {
        $reservation = $this->reservationParJeton($jeton);

        if (StatutReservation::PAIEMENT_EN_COURS === $reservation->getStatut()
            && null !== $reservation->getStripePaymentIntentId()
            && !$this->paiement->estSimulation()
            && PaiementGateway::STATUT_AUTORISEE === $this->paiement->statut($reservation->getStripePaymentIntentId())) {
            $this->workflow->empreinteAutorisee($reservation);
        }

        return $this->render('reservation/suivi.html.twig', ['reservation' => $reservation]);
    }

    /** Développement sans clés Stripe uniquement : simule la saisie d'une carte valide. */
    #[Route('/suivi/{jeton<[A-Za-z0-9_-]{22}>}/simuler', name: 'app_reservation_simuler', methods: ['POST'])]
    public function simuler(string $jeton, Request $request): Response
    {
        if (!$this->paiement->estSimulation()) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('simuler_'.$jeton, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $this->workflow->empreinteAutorisee($this->reservationParJeton($jeton));

        return $this->redirectToRoute('app_reservation_suivi', ['jeton' => $jeton]);
    }

    /** Fiche de la cliente connectée, s'il y en a une (un compte admin n'en a pas). */
    private function ficheConnectee(): ?Client
    {
        $user = $this->getUser();

        return $user instanceof User ? $this->comptes->ficheDe($user) : null;
    }

    private function reservationParJeton(string $jeton): Reservation
    {
        return $this->reservations->findOneBy(['jetonSuivi' => $jeton])
            ?? throw $this->createNotFoundException('Réservation introuvable.');
    }

    private function creneauPris(Prestation $prestation): Response
    {
        $this->addFlash('erreur', 'Ce créneau n\'est plus disponible. Choisissez-en un autre.');

        return $this->redirectToRoute('app_reservation_creneau', ['id' => $prestation->getId()]);
    }

    private function exigerActive(Prestation $prestation): void
    {
        if (!$prestation->isActive()) {
            throw new NotFoundHttpException('Prestation indisponible.');
        }
    }
}
