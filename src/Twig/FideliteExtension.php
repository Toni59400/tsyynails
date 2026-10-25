<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Client;
use App\Entity\Parametre;
use App\Repository\ParametreRepository;
use App\Repository\PrestationRepository;
use App\Service\Fidelite\ProgrammeFidelite;
use App\Service\Fidelite\ReductionAVie;
use Twig\Attribute\AsTwigFunction;

final class FideliteExtension
{
    public function __construct(
        private readonly ProgrammeFidelite $fidelite,
        private readonly ReductionAVie $reductionAVie,
        private readonly ParametreRepository $parametres,
        private readonly PrestationRepository $prestations,
    ) {
    }

    /**
     * Règles de la réduction à vie pour les encarts du site (null si désactivée dans les réglages).
     * « rendez_vous » : nombre approximatif de rendez-vous pour atteindre le seuil, d'après le prix moyen des prestations.
     *
     * @return array{seuil: int, depart: int, pas: int, plafond: int, visites: int, rendez_vous: int, paliers: list<int>}|null
     */
    #[AsTwigFunction('programme_vie')]
    public function programmeVie(): ?array
    {
        $seuil = $this->parametres->valeur(Parametre::PRIVILEGE_SEUIL_POINTS);
        if ($seuil <= 0) {
            return null;
        }
        $depart = $this->parametres->valeur(Parametre::PRIVILEGE_REDUCTION_DEPART);
        $pas = $this->parametres->valeur(Parametre::PRIVILEGE_REDUCTION_PAS);
        $plafond = max($depart, $this->parametres->valeur(Parametre::PRIVILEGE_REDUCTION_MAX));

        // Paliers successifs : 10, 15, 20 % (s'arrête au plafond).
        $paliers = [$depart];
        while ($pas > 0 && end($paliers) < $plafond && \count($paliers) < 5) {
            $paliers[] = min($plafond, end($paliers) + $pas);
        }

        $prix = array_map(static fn ($p): int => $p->getPrixCentimes(), $this->prestations->findActives());
        $pointsParRdv = [] === $prix ? 0 : $this->fidelite->pointsPour((int) (array_sum($prix) / \count($prix)));

        return [
            'seuil' => $seuil,
            'depart' => $depart,
            'pas' => $pas,
            'plafond' => $plafond,
            'visites' => $this->parametres->valeur(Parametre::PRIVILEGE_VISITES_ANNUELLES),
            'rendez_vous' => $pointsParRdv > 0 ? (int) ceil($seuil / $pointsParRdv) : 0,
            'paliers' => $paliers,
        ];
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
