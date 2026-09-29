<?php

declare(strict_types=1);

namespace App\Service\Reservation;

use App\Entity\Parametre;
use App\Entity\Reservation;
use App\Repository\ParametreRepository;
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
        private readonly ParametreRepository $parametres,
        #[Autowire('%app.salon%')] private readonly array $salon,
        /** Adresse du domaine du site : un envoi « de la part » d'une adresse Gmail finirait en spam. */
        #[Autowire(env: 'MAILER_EXPEDITEUR')] private readonly string $expediteur,
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

    /** La veille du rendez-vous confirmé. */
    public function rappel(Reservation $reservation): void
    {
        $this->envoyerALaCliente($reservation, 'Rappel : votre rendez-vous de demain', 'emails/reservation/rappel.html.twig');
    }

    /**
     * Le lendemain d'un rendez-vous honoré : invitation (sans contrepartie) à laisser un avis Google.
     * Envoyée à toutes les clientes de la même façon, comme l'exigent les règles de Google.
     */
    public function demandeAvis(Reservation $reservation, string $lienAvis, string $lienRefus): void
    {
        $this->envoyerALaCliente($reservation, 'Votre avis compte beaucoup pour moi', 'emails/reservation/demande_avis.html.twig', [
            'lien_avis' => $lienAvis,
            'lien_refus' => $lienRefus,
        ]);
    }

    /**
     * @param bool $acompteConserve acompte débité et non remboursé (annulation à moins de 48 h)
     */
    public function annulee(Reservation $reservation, bool $acompteConserve = false): void
    {
        $this->envoyerALaCliente($reservation, 'Votre rendez-vous est annulé', 'emails/reservation/annulee.html.twig', ['acompte_conserve' => $acompteConserve]);
    }

    /**
     * @param array<string, mixed> $contexte
     */
    private function envoyerALaCliente(Reservation $reservation, string $sujet, string $gabarit, array $contexte = []): void
    {
        $email = $reservation->getEmailNotification();
        if (null === $email) {
            return;
        }

        $this->envoyer(
            (new TemplatedEmail())
                ->to(new Address($email, $reservation->getClient()->getNomComplet()))
                ->subject($sujet.' · '.$this->salon['nom'])
                ->htmlTemplate($gabarit)
                ->context($contexte + [
                    'reservation' => $reservation,
                    // Date limite d'annulation gratuite (acompte remboursé), rappelée dans les emails.
                    'limite_annulation' => $reservation->getDebut()->modify(\sprintf('-%d hours', $this->parametres->valeur(Parametre::ANNULATION_GRATUITE_HEURES))),
                ]),
            $reservation,
            basename($gabarit, '.html.twig'),
        );
    }

    private function envoyer(TemplatedEmail $email, Reservation $reservation, string $type): void
    {
        $email
            ->from(new Address($this->expediteur, (string) $this->salon['nom']))
            // Les réponses des clientes arrivent dans la boîte de la prothésiste.
            ->replyTo((string) $this->salon['email']);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $erreur) {
            // Jamais l'adresse ni le contenu dans les logs : l'identifiant de réservation suffit.
            $this->logger->error('Email de réservation non envoyé.', ['type' => $type, 'reservation' => $reservation->getId(), 'erreur' => $erreur->getMessage()]);
        }
    }
}
