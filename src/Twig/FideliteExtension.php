<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Client;
use App\Service\Fidelite\ProgrammeFidelite;
use App\Service\Fidelite\ReductionAVie;
use Twig\Attribute\AsTwigFunction;

final class FideliteExtension
{
    public function __construct(
        private readonly ProgrammeFidelite $fidelite,
        private readonly ReductionAVie $reductionAVie,
    ) {
    }

    /** Points gagnés pour un montant payé, selon le réglage en vigueur. */
    #[AsTwigFunction('points_pour')]
    public function pointsPour(int $montantCentimes): int
    {
        return $this->fidelite->pointsPour($montantCentimes);
    }

    /**
     * Réduction à vie de la cliente : en cours, ou progression vers le seuil.
     *
     * @return array<string, mixed>
     */
    #[AsTwigFunction('reduction_a_vie')]
    public function reductionAVie(Client $client): array
    {
        return $this->reductionAVie->etat($client);
    }
}
