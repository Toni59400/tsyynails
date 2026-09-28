<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\HoraireOuverture;
use App\Entity\Indisponibilite;
use App\Repository\HoraireOuvertureRepository;
use App\Repository\IndisponibiliteRepository;
use Psr\Clock\ClockInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * Horaires et fermetures affichés sur le site (pied de page, infos pratiques).
 */
final class PlanningExtension
{
    public function __construct(
        private readonly HoraireOuvertureRepository $horaires,
        private readonly IndisponibiliteRepository $indisponibilites,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Les 7 jours de la semaine, du lundi au dimanche ; une liste de plages vide = fermé.
     *
     * @return list<array{jour: string, plages: list<HoraireOuverture>}>
     */
    #[AsTwigFunction('horaires_semaine')]
    public function horairesSemaine(): array
    {
        $parJour = [];
        foreach ($this->horaires->findToutesOrdonnees() as $horaire) {
            $parJour[$horaire->getJourSemaine()][] = $horaire;
        }

        $semaine = [];
        foreach (HoraireOuverture::JOURS as $numero => $jour) {
            $semaine[] = ['jour' => $jour, 'plages' => $parJour[$numero] ?? []];
        }

        return $semaine;
    }

    /**
     * Congés et fermetures des prochaines semaines, pour prévenir la clientèle.
     *
     * @return list<Indisponibilite>
     */
    #[AsTwigFunction('fermetures_a_venir')]
    public function fermeturesAVenir(int $semaines = 8): array
    {
        $maintenant = $this->clock->now();

        return array_values(array_filter(
            $this->indisponibilites->findChevauchant($maintenant, $maintenant->modify(\sprintf('+%d weeks', $semaines))),
            // Seules les fermetures d'au moins une demi-journée intéressent la clientèle.
            static fn (Indisponibilite $i): bool => $i->getFin()->getTimestamp() - $i->getDebut()->getTimestamp() >= 4 * 3600,
        ));
    }
}
