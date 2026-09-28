<?php

declare(strict_types=1);

namespace App\Service\Paiement;

final class Empreinte
{
    public function __construct(
        public readonly string $identifiant,
        /** Secret transmis au navigateur pour que Stripe Elements confirme ce paiement précis. */
        public readonly string $clientSecret,
    ) {
    }
}
