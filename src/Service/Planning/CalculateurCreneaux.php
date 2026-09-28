<?php

declare(strict_types=1);

namespace App\Service\Planning;

use App\Entity\HoraireOuverture;
use App\Entity\Parametre;
use App\Entity\Prestation;
use App\Repository\HoraireOuvertureRepository;
use App\Repository\IndisponibiliteRepository;
use App\Repository\ParametreRepository;
use App\Repository\ReservationRepository;
use Psr\Clock\ClockInterface;

/**
 * Créneaux proposés à la réservation : horaires d'ouverture
 * moins les indisponibilités et les réservations qui bloquent l'agenda,
 * par pas de 15 minutes, selon la durée de la prestation.
 */
final class CalculateurCreneaux
{
    public const PAS_MINUTES = 15;
    public const DELAI_MIN_HEURES_PAR_DEFAUT = 24;

    public function __construct(
        private readonly HoraireOuvertureRepository $horaires,
        private readonly IndisponibiliteRepository $indisponibilites,
        private readonly ReservationRepository $reservations,
        private readonly ParametreRepository $parametres,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, list<\DateTimeImmutable>> créneaux par jour (clé Y-m-d), jours sans créneau inclus
     */
    public function creneauxParJour(Prestation $prestation, \DateTimeImmutable $premierJour, int $nombreJours): array
    {
        $debut = $premierJour->setTime(0, 0);
        $fin = $debut->modify(\sprintf('+%d days', $nombreJours));

        $occupations = [];
        foreach ($this->indisponibilites->findChevauchant($debut, $fin) as $indisponibilite) {
            $occupations[] = [$indisponibilite->getDebut(), $indisponibilite->getFin()];
        }
        foreach ($this->reservations->findBloquantesEntre($debut, $fin) as $reservation) {
            $occupations[] = [$reservation->getDebut(), $reservation->getFin()];
        }

        $delaiHeures = $this->parametres->entier(Parametre::DELAI_MIN_RESERVATION_HEURES, self::DELAI_MIN_HEURES_PAR_DEFAUT);
        $pasAvant = $this->clock->now()->modify(\sprintf('+%d hours', $delaiHeures));

        return self::calculer($this->horaires->findToutesOrdonnees(), $occupations, $prestation->getDureeMinutes(), $debut, $nombreJours, $pasAvant);
    }

    public function estDisponible(Prestation $prestation, \DateTimeImmutable $debut): bool
    {
        $creneaux = $this->creneauxParJour($prestation, $debut, 1)[$debut->format('Y-m-d')] ?? [];

        foreach ($creneaux as $creneau) {
            if ($creneau == $debut) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calcul pur, sans base de données.
     *
     * @param list<HoraireOuverture>                                    $horaires
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $occupations périodes [début, fin[ indisponibles
     *
     * @return array<string, list<\DateTimeImmutable>>
     */
    public static function calculer(
        array $horaires,
        array $occupations,
        int $dureeMinutes,
        \DateTimeImmutable $premierJour,
        int $nombreJours,
        \DateTimeImmutable $pasAvant,
    ): array {
        $plagesParJour = [];
        foreach ($horaires as $horaire) {
            $plagesParJour[$horaire->getJourSemaine()][] = $horaire;
        }

        $resultat = [];
        for ($i = 0; $i < $nombreJours; ++$i) {
            $jour = $premierJour->setTime(0, 0)->modify(\sprintf('+%d days', $i));
            $creneaux = [];

            foreach ($plagesParJour[(int) $jour->format('N')] ?? [] as $plage) {
                $curseur = self::aLHeure($jour, $plage->getHeureDebut());
                $finPlage = self::aLHeure($jour, $plage->getHeureFin());

                while (($finCreneau = $curseur->modify(\sprintf('+%d minutes', $dureeMinutes))) <= $finPlage) {
                    if ($curseur >= $pasAvant && !self::chevauche($curseur, $finCreneau, $occupations)) {
                        $creneaux[] = $curseur;
                    }
                    $curseur = $curseur->modify(\sprintf('+%d minutes', self::PAS_MINUTES));
                }
            }

            $resultat[$jour->format('Y-m-d')] = $creneaux;
        }

        return $resultat;
    }

    private static function aLHeure(\DateTimeImmutable $jour, \DateTimeImmutable $heure): \DateTimeImmutable
    {
        return $jour->setTime((int) $heure->format('H'), (int) $heure->format('i'));
    }

    /**
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $occupations
     */
    private static function chevauche(\DateTimeImmutable $debut, \DateTimeImmutable $fin, array $occupations): bool
    {
        foreach ($occupations as [$debutOccupation, $finOccupation]) {
            if ($debut < $finOccupation && $fin > $debutOccupation) {
                return true;
            }
        }

        return false;
    }
}
