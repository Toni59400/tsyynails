<?php

declare(strict_types=1);

namespace App\Service\Reservation;

use App\Entity\Reservation;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Emails transactionnels des réservations, envoyés tout de suite (pas de file d'attente sur OVH mutualisé).
 * Un email qui échoue est journalisé mais ne bloque jamais la réservation.
 * Aucun email ne contient de donnée de santé.
 */
final class NotificationsReservation
{
    /**
     * @param array<string, string|null> $salon
     */
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.salon%')] private readonly array $salon,
    ) {
    }

    /** Empreinte autorisée : accusé de réception à la cliente et alerte à la prothésiste. */
    public function demandeRecue(Reservation $reservation): void
    {
        $this->envoyerALaCliente($reservation, 'Votre demande de rendez-vous est bien reçue', 'emails/reservation/demande_recue.html.twig');
        $this->envoyer(
            (new TemplatedEmail())
                ->to((string) $this->salon['email'])
                ->subject('Nouvelle demande : '.$reservation->getPrestation()->getNom())
                ->htmlTemplate('emails/reservation/nouvelle_demande_admin.html.twig')
                ->context(['reservation' => $reservation]),
            $reservation,
            'nouvelle_demande_admin',
        );
    }

    public function confirmee(Reservation $reservation): void
    {
        $this->envoyerALaCliente($reservation, 'Votre rendez-vous est confirmé', 'emails/reservation/confirmee.html.twig');
    }

    public function refusee(Reservation $reservation): void
    {
        $this->envoyerALaCliente($reservation, 'Votre demande de rendez-vous', 'emails/reservation/refusee.html.twig');
    }

    public function expiree(Reservation $reservation): void
    {
        $this->envoyerALaCliente($reservation, 'Votre demande de rendez-vous a expiré', 'emails/reservation/expiree.html.twig');
    }

    public function annulee(Reservation $reservation): void
    {
        $this->envoyerALaCliente($reservation, 'Votre rendez-vous est annulé', 'emails/reservation/annulee.html.twig');
    }

    private function envoyerALaCliente(Reservation $reservation, string $sujet, string $gabarit): void
    {
        $email = $reservation->getClient()->getEmail();
        if (null === $email) {
            return;
        }

        $this->envoyer(
            (new TemplatedEmail())
                ->to(new Address($email, $reservation->getClient()->getNomComplet()))
                ->subject($sujet.' · '.$this->salon['nom'])
                ->htmlTemplate($gabarit)
                ->context(['reservation' => $reservation]),
            $reservation,
            basename($gabarit, '.html.twig'),
        );
    }

    private function envoyer(TemplatedEmail $email, Reservation $reservation, string $type): void
    {
        $email->from(new Address((string) $this->salon['email'], (string) $this->salon['nom']));

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $erreur) {
            // Jamais l'adresse ni le contenu dans les logs : l'identifiant de réservation suffit.
            $this->logger->error('Email de réservation non envoyé.', ['type' => $type, 'reservation' => $reservation->getId(), 'erreur' => $erreur->getMessage()]);
        }
    }
}
