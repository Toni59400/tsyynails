<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\EvenementStripe;
use App\Repository\EvenementStripeRepository;
use App\Repository\ReservationRepository;
use App\Service\Reservation\ReservationWorkflow;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\Webhook;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Notifications de Stripe. Signature vérifiée, chaque événement traité une seule fois.
 * À déclarer dans le tableau de bord Stripe : https://<domaine>/stripe/webhook,
 * événement payment_intent.amount_capturable_updated.
 */
final class StripeWebhookController
{
    public function __construct(
        #[Autowire(env: 'STRIPE_WEBHOOK_SECRET')] private readonly string $secret,
        private readonly EvenementStripeRepository $evenements,
        private readonly ReservationRepository $reservations,
        private readonly ReservationWorkflow $workflow,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/stripe/webhook', name: 'app_stripe_webhook', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if ('' === $this->secret) {
            return new Response('Webhook non configuré.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            $evenement = Webhook::constructEvent($request->getContent(), (string) $request->headers->get('Stripe-Signature'), $this->secret);
        } catch (SignatureVerificationException|\UnexpectedValueException) {
            $this->logger->warning('Webhook Stripe refusé : signature invalide.');

            return new Response('Signature invalide.', Response::HTTP_BAD_REQUEST);
        }

        if (null !== $this->evenements->find($evenement->id)) {
            return new Response('Déjà traité.');
        }

        if ('payment_intent.amount_capturable_updated' === $evenement->type && $evenement->data->object instanceof PaymentIntent) {
            $reservation = $this->reservations->findOneBy(['stripePaymentIntentId' => $evenement->data->object->id]);
            if (null !== $reservation) {
                $this->workflow->empreinteAutorisee($reservation);
            }
        }

        $this->entityManager->persist(new EvenementStripe($evenement->id, $evenement->type));
        $this->entityManager->flush();

        return new Response('OK');
    }
}
