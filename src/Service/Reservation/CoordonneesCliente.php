<?php

declare(strict_types=1);

namespace App\Service\Reservation;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Coordonnées saisies dans le tunnel de réservation (minimisation : rien d'autre).
 */
final class CoordonneesCliente
{
    #[Assert\NotBlank(message: 'Indiquez votre prénom.')]
    #[Assert\Length(max: 100)]
    public string $prenom = '';

    #[Assert\NotBlank(message: 'Indiquez votre nom.')]
    #[Assert\Length(max: 100)]
    public string $nom = '';

    /** Saisi librement (« 06 12 34 56 78 »), normalisé en +33612345678. */
    #[Assert\NotBlank(message: 'Indiquez votre numéro de téléphone.')]
    #[Assert\Callback([self::class, 'validerTelephone'])]
    public string $telephone = '';

    #[Assert\NotBlank(message: 'Indiquez votre adresse email pour recevoir la confirmation.')]
    #[Assert\Email(message: 'Adresse email invalide.')]
    #[Assert\Length(max: 180)]
    public string $email = '';

    #[Assert\IsTrue(message: 'Vous devez accepter les conditions de réservation.')]
    public bool $accepteConditions = false;

    /**
     * Numéro français (06…, 07…, 01…) ou international (+32…), converti au format E.164.
     */
    public static function normaliserTelephone(string $saisie): ?string
    {
        $chiffres = preg_replace('/[\s.\-()]/', '', $saisie) ?? '';

        if (preg_match('/^0[1-9]\d{8}$/', $chiffres)) {
            return '+33'.substr($chiffres, 1);
        }
        if (str_starts_with($chiffres, '00')) {
            $chiffres = '+'.substr($chiffres, 2);
        }

        return preg_match('/^\+[1-9]\d{7,14}$/', $chiffres) ? $chiffres : null;
    }

    public static function validerTelephone(string $telephone, \Symfony\Component\Validator\Context\ExecutionContextInterface $contexte): void
    {
        if ('' !== $telephone && null === self::normaliserTelephone($telephone)) {
            $contexte->buildViolation('Numéro de téléphone invalide (exemple : 06 12 34 56 78).')->addViolation();
        }
    }
}
