<?php

declare(strict_types=1);

namespace App\Service\Compte;

use App\Entity\Client;
use App\Entity\Parametre;
use App\Entity\User;
use App\Repository\ParametreRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Emails liés au compte cliente. Aucun lien ni mot de passe dans les logs.
 */
final class NotificationsCompte
{
    /**
     * @param array<string, string|null> $salon
     */
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly ParametreRepository $parametres,
        #[Autowire('%app.salon%')] private readonly array $salon,
        #[Autowire(env: 'MAILER_EXPEDITEUR')] private readonly string $expediteur,
    ) {
    }

    public function confirmation(User $user, string $lien): void
    {
        $this->envoyer($user->getEmail(), 'Confirmez votre adresse email', 'emails/compte/confirmation.html.twig', ['lien' => $lien]);
    }

    /** Tentative d'inscription avec une adresse déjà utilisée : on prévient sans le révéler sur le site. */
    public function compteExistant(User $user, string $lienReinitialisation): void
    {
        $this->envoyer($user->getEmail(), 'Vous avez déjà un compte', 'emails/compte/compte_existant.html.twig', ['lien' => $lienReinitialisation]);
    }

    public function reinitialisation(User $user, string $lien): void
    {
        $this->envoyer($user->getEmail(), 'Choisir un nouveau mot de passe', 'emails/compte/reinitialisation.html.twig', ['lien' => $lien]);
    }

    /** Compte inactif : il sera supprimé à cette date sans nouvelle connexion ni rendez-vous. */
    public function suppressionProchaine(User $user, \DateTimeImmutable $date): void
    {
        $this->envoyer($user->getEmail(), 'Votre compte va être supprimé', 'emails/compte/suppression_prochaine.html.twig', ['date' => $date]);
    }

    public function expirationPoints(Client $client, int $points, \DateTimeImmutable $expiration): void
    {
        if (null === $client->getEmail()) {
            return;
        }

        $this->envoyer($client->getEmail(), 'Vos points de fidélité vont expirer', 'emails/compte/expiration_points.html.twig', [
            'client' => $client,
            'points' => $points,
            'expiration' => $expiration,
        ]);
    }

    /**
     * Réduction à vie : accordée, renouvelée (+5 %), perdue, ou rappel avant l'anniversaire.
     *
     * @param 'accordee'|'renouvelee'|'perdue'|'rappel' $evenement
     */
    public function reductionAVie(Client $client, string $evenement, int $pourcentage, int $rendezVousManquants = 0, ?\DateTimeImmutable $echeance = null): void
    {
        if (null === $client->getEmail()) {
            return;
        }

        $sujets = [
            'accordee' => \sprintf('Vous avez gagné %d %% de réduction à vie', $pourcentage),
            'renouvelee' => \sprintf('Votre réduction à vie passe à %d %%', $pourcentage),
            'perdue' => 'Votre réduction à vie',
            'rappel' => 'Gardez votre réduction à vie',
        ];
        $this->envoyer($client->getEmail(), $sujets[$evenement], 'emails/compte/reduction_a_vie.html.twig', [
            'client' => $client,
            'evenement' => $evenement,
            'pourcentage' => $pourcentage,
            'manquants' => $rendezVousManquants,
            'echeance' => $echeance,
            'visites_requises' => $this->parametres->valeur(Parametre::PRIVILEGE_VISITES_ANNUELLES),
            'plafond' => $this->parametres->valeur(Parametre::PRIVILEGE_REDUCTION_MAX),
        ]);
    }

    /**
     * @param array<string, mixed> $contexte
     */
    private function envoyer(string $destinataire, string $sujet, string $gabarit, array $contexte): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->expediteur, (string) $this->salon['nom']))
            ->replyTo((string) $this->salon['email'])
            ->to($destinataire)
            ->subject($sujet.' · '.$this->salon['nom'])
            ->htmlTemplate($gabarit)
            ->context($contexte);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $erreur) {
            $this->logger->error('Email de compte non envoyé.', ['gabarit' => basename($gabarit), 'erreur' => $erreur->getMessage()]);
        }
    }
}
