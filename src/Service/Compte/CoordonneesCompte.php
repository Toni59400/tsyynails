<?php

declare(strict_types=1);

namespace App\Service\Compte;

use App\Service\Reservation\CoordonneesCliente;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Coordonnées modifiables par la cliente dans son espace (l'email, identifiant du compte, passe par le salon).
 */
final class CoordonneesCompte
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
}
