<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Fidelite\ProgrammeFidelite;
use Twig\Attribute\AsTwigFunction;

final class FideliteExtension
{
    public function __construct(private readonly ProgrammeFidelite $fidelite)
    {
    }

    /** Points gagnés pour un montant payé, selon le réglage en vigueur. */
    #[AsTwigFunction('points_pour')]
    public function pointsPour(int $montantCentimes): int
    {
        return $this->fidelite->pointsPour($montantCentimes);
    }
}
