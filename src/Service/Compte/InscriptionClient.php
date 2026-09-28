<?php

declare(strict_types=1);

namespace App\Service\Compte;

use App\Service\Reservation\CoordonneesCliente;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Formulaire de création de compte sur le site (minimisation : identité, téléphone, email, mot de passe).
 */
final class InscriptionClient
{
    #[Assert\NotBlank(message: 'Indiquez votre prénom.')]
    #[Assert\Length(max: 100)]
    public string $prenom = '';

    #[Assert\NotBlank(message: 'Indiquez votre nom.')]
    #[Assert\Length(max: 100)]
    public string $nom = '';

    #[Assert\NotBlank(message: 'Indiquez votre numéro de téléphone.')]
    #[Assert\Callback([CoordonneesCliente::class, 'validerTelephone'])]
    public string $telephone = '';

    #[Assert\NotBlank(message: 'Indiquez votre adresse email.')]
    #[Assert\Email(message: 'Adresse email invalide.')]
    #[Assert\Length(max: 180)]
    public string $email = '';

    #[Assert\NotBlank(message: 'Choisissez un mot de passe.')]
    #[Assert\Length(min: 12, max: 4096, minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.')]
    #[Assert\NotCompromisedPassword(message: 'Ce mot de passe a fuité lors d\'un piratage connu : choisissez-en un autre.', skipOnError: true)]
    public string $motDePasse = '';

    /** Code donné par une cliente qui vous a recommandé le salon (facultatif). */
    #[Assert\Length(max: 12)]
    public ?string $codeParrainage = null;

    #[Assert\IsTrue(message: 'Vous devez accepter le règlement du programme de fidélité.')]
    public bool $accepteConditions = false;
}
