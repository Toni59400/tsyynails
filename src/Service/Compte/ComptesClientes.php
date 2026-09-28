<?php

declare(strict_types=1);

namespace App\Service\Compte;

use App\Entity\Client;
use App\Entity\Reservation;
use App\Entity\User;
use App\Repository\ClientRepository;
use App\Repository\UserRepository;
use App\Service\Fidelite\Parrainage;
use App\Service\Fidelite\ProgrammeFidelite;
use App\Service\Reservation\CoordonneesCliente;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Comptes clientes : création, confirmation de l'email, rattachement à la fiche, mot de passe oublié.
 *
 * Sécurité :
 * - liens signés (UriSigner) et limités dans le temps, sans table de jetons ;
 * - le lien de réinitialisation contient une empreinte du mot de passe actuel : il ne sert qu'une fois ;
 * - une fiche existante n'est rattachée qu'à un compte dont l'email, confirmé, est celui de la fiche ;
 * - les messages affichés ne révèlent jamais si une adresse a déjà un compte.
 */
final class ComptesClientes
{
    public const VALIDITE_CONFIRMATION = '+3 days';
    public const VALIDITE_REINITIALISATION = '+1 hour';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
        private readonly ClientRepository $clientes,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly UriSigner $signataire,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly NotificationsCompte $notifications,
        private readonly ProgrammeFidelite $fidelite,
        private readonly Parrainage $parrainage,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
    ) {
    }

    /**
     * Inscription depuis la page « Créer mon compte ». Envoie le lien de confirmation,
     * ou un email « compte existant » si l'adresse est déjà utilisée.
     */
    public function inscrire(InscriptionClient $inscription): void
    {
        $telephone = CoordonneesCliente::normaliserTelephone($inscription->telephone)
            ?? throw new \InvalidArgumentException('Téléphone invalide.');

        $user = $this->creerCompte($inscription->email, $inscription->motDePasse);
        if (null === $user) {
            return;
        }

        // Nouvelle cliente : sa fiche est créée tout de suite. Une fiche existante (même email ou même
        // téléphone) n'est jamais rattachée ici : seulement après confirmation de l'email (voir confirmer()).
        $existeDeja = null !== $this->clientes->findOneBy(['email' => $user->getEmail()])
            || null !== $this->clientes->findOneBy(['telephone' => $telephone]);
        $nouvelleFiche = null;
        if (!$existeDeja) {
            $nouvelleFiche = (new Client($inscription->prenom, $inscription->nom, $telephone))->setEmail($user->getEmail());
            $nouvelleFiche->setUser($user);
            $this->entityManager->persist($nouvelleFiche);
        }
        $this->entityManager->flush();

        // Seule une nouvelle fiche peut être parrainée (voir Client::peutEtreParraineePar()).
        if (null !== $nouvelleFiche) {
            $this->parrainage->rattacher($nouvelleFiche, $inscription->codeParrainage ?: $this->parrainage->codeMemorise());
        }

        $this->notifications->confirmation($user, $this->lienConfirmation($user));
    }

    /**
     * Case « Créer mon compte » cochée pendant une réservation. La fiche de la réservation
     * sera rattachée à la confirmation de l'email si c'est bien la même adresse.
     *
     * @return bool faux si un compte existe déjà avec cet email
     */
    public function inscrireDepuisReservation(Reservation $reservation, string $email, string $motDePasse): bool
    {
        $user = $this->creerCompte($email, $motDePasse);
        if (null === $user) {
            return false;
        }

        $this->entityManager->flush();
        // Arrivée par un lien de parrainage : la fiche de la réservation est parrainée si elle est nouvelle.
        $this->parrainage->rattacher($reservation->getClient(), $this->parrainage->codeMemorise());
        $this->notifications->confirmation($user, $this->lienConfirmation($user));

        return true;
    }

    public function lienConfirmation(User $user): string
    {
        return $this->signataire->sign($this->urlGenerator->generate('app_inscription_confirmer', [
            'id' => $user->getId(),
            'empreinte' => $this->empreinte('email', $user->getEmail()),
        ], UrlGeneratorInterface::ABSOLUTE_URL), new \DateTimeImmutable(self::VALIDITE_CONFIRMATION));
    }

    /**
     * Lien de confirmation cliqué : l'email est vérifié, la fiche au même email rattachée,
     * et le bonus de bienvenue crédité (une seule fois par fiche).
     */
    public function confirmer(Request $requete): ?User
    {
        if (!$this->signataire->checkRequest($requete)) {
            return null;
        }

        $user = $this->users->find($requete->query->getInt('id'));
        if (!$user instanceof User || !hash_equals($this->empreinte('email', $user->getEmail()), $requete->query->getString('empreinte'))) {
            return null;
        }

        $user->verifierEmail($this->clock->now());
        $client = $this->rattacherFiche($user);
        $this->entityManager->flush();

        if (null !== $client) {
            $this->fidelite->crediterBonusInscription($client);
        }
        $this->logger->info('Email de compte confirmé.', ['user' => $user->getId(), 'fiche' => $client?->getId()]);

        return $user;
    }

    /** Fiche de la cliente connectée (null si le compte attend un rattachement par le salon). */
    public function ficheDe(User $user): ?Client
    {
        return $this->clientes->findOneBy(['user' => $user]);
    }

    /** Renvoi du lien de confirmation ; ne dit rien si l'adresse est inconnue ou déjà confirmée. */
    public function renvoyerConfirmation(string $email): void
    {
        $user = $this->users->findOneBy(['email' => mb_strtolower(trim($email))]);
        if ($user instanceof User && !$user->isEmailVerifie() && !$user->isAdmin()) {
            $this->notifications->confirmation($user, $this->lienConfirmation($user));
        }
    }

    /** Mot de passe oublié ; ne dit rien si l'adresse est inconnue. */
    public function demanderReinitialisation(string $email): void
    {
        $user = $this->users->findOneBy(['email' => mb_strtolower(trim($email))]);
        if ($user instanceof User && !$user->isAdmin()) {
            $this->notifications->reinitialisation($user, $this->lienReinitialisation($user));
        }
    }

    public function lienReinitialisation(User $user): string
    {
        return $this->signataire->sign($this->urlGenerator->generate('app_mot_de_passe_nouveau', [
            'id' => $user->getId(),
            'empreinte' => $this->empreinte('mdp', $user->getPassword()),
        ], UrlGeneratorInterface::ABSOLUTE_URL), new \DateTimeImmutable(self::VALIDITE_REINITIALISATION));
    }

    /** Compte visé par un lien de réinitialisation valide (signé, non expiré, pas encore utilisé). */
    public function compteDuLienDeReinitialisation(Request $requete): ?User
    {
        if (!$this->signataire->checkRequest($requete)) {
            return null;
        }

        $user = $this->users->find($requete->query->getInt('id'));
        if (!$user instanceof User || $user->isAdmin() || !hash_equals($this->empreinte('mdp', $user->getPassword()), $requete->query->getString('empreinte'))) {
            return null;
        }

        return $user;
    }

    /**
     * Nouveau mot de passe. Recevoir le lien prouve la possession de l'adresse :
     * l'email est considéré comme confirmé.
     */
    public function reinitialiser(User $user, string $motDePasse): void
    {
        $user->setPassword($this->hasher->hashPassword($user, $motDePasse));
        $user->verifierEmail($this->clock->now());
        $client = $this->rattacherFiche($user);
        $this->entityManager->flush();

        if (null !== $client) {
            $this->fidelite->crediterBonusInscription($client);
        }
        $this->logger->notice('Mot de passe réinitialisé.', ['user' => $user->getId()]);
    }

    private function creerCompte(string $email, string $motDePasse): ?User
    {
        $email = mb_strtolower(trim($email));
        $existant = $this->users->findOneBy(['email' => $email]);
        if ($existant instanceof User) {
            if (!$existant->isAdmin()) {
                $this->notifications->compteExistant($existant, $this->lienReinitialisation($existant));
            }

            return null;
        }

        $user = new User($email);
        $user->setPassword($this->hasher->hashPassword($user, $motDePasse));
        $this->entityManager->persist($user);
        $this->logger->info('Compte cliente créé.');

        return $user;
    }

    /**
     * Rattache au compte la fiche dont l'email est l'adresse confirmée, si elle n'a pas déjà un compte.
     */
    private function rattacherFiche(User $user): ?Client
    {
        $client = $this->ficheDe($user);
        if (null !== $client) {
            return $client;
        }

        $client = $this->clientes->findOneBy(['email' => $user->getEmail(), 'user' => null]);
        if ($client instanceof Client && !$client->estAnonymise()) {
            $client->setUser($user);

            return $client;
        }

        return null;
    }

    private function empreinte(string $usage, string $valeur): string
    {
        return substr(hash_hmac('sha256', $usage.'|'.$valeur, $this->secret), 0, 32);
    }
}
