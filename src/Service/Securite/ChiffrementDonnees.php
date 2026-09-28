<?php

declare(strict_types=1);

namespace App\Service\Securite;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Chiffrement symétrique authentifié (libsodium secretbox) des données sensibles,
 * comme les notes santé des clientes.
 *
 * La clé (32 octets encodés en base64) vient de APP_ENCRYPTION_KEY :
 * .env.local en dev, coffre de secrets Symfony en prod.
 * Générer une clé : php -r "echo base64_encode(sodium_crypto_secretbox_keygen());"
 */
final class ChiffrementDonnees
{
    private readonly string $cle;

    public function __construct(#[Autowire(env: 'APP_ENCRYPTION_KEY')] string $cleBase64)
    {
        $cle = base64_decode($cleBase64, true);
        if (false === $cle || SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($cle)) {
            throw new \InvalidArgumentException('APP_ENCRYPTION_KEY doit contenir 32 octets encodés en base64.');
        }

        $this->cle = $cle;
    }

    public function chiffrer(string $texte): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce.sodium_crypto_secretbox($texte, $nonce, $this->cle));
    }

    public function dechiffrer(string $chiffre): string
    {
        $binaire = base64_decode($chiffre, true);
        if (false === $binaire || \strlen($binaire) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Donnée chiffrée illisible.');
        }

        $nonce = substr($binaire, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $texte = sodium_crypto_secretbox_open(substr($binaire, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->cle);
        if (false === $texte) {
            throw new \RuntimeException('Déchiffrement impossible : clé incorrecte ou donnée altérée.');
        }

        return $texte;
    }
}
