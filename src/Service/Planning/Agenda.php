<?php

declare(strict_types=1);

namespace App\Service\Planning;

use App\Entity\Indisponibilite;
use App\Entity\Reservation;
use App\Enum\StatutReservation;
use App\Repository\HoraireOuvertureRepository;
use App\Repository\IndisponibiliteRepository;
use App\Repository\ReservationRepository;

/**
 * Données de l'agenda de l'admin : vues jour, semaine et mois.
 */
final class Agenda
{
    public const VUES = ['jour', 'semaine', 'mois'];

    /** Les demandes refusées, expirées ou annulées libèrent le créneau : elles n'encombrent pas l'agenda. */
    public const STATUTS_AFFICHES = [
        StatutReservation::EN_ATTENTE,
        StatutReservation::CONFIRMEE,
        StatutReservation::HONOREE,
        StatutReservation::NON_HONOREE,
    ];

    /** Hauteur d'une ligne de la grille horaire. */
    public const PAS_MINUTES = 15;

    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly IndisponibiliteRepository $indisponibilites,
        private readonly HoraireOuvertureRepository $horaires,
    ) {
    }

    /**
     * @return array{
     *     vue: string,
     *     debut: \DateTimeImmutable,
     *     fin: \DateTimeImmutable,
     *     precedente: \DateTimeImmutable,
     *     suivante: \DateTimeImmutable,
     *     jours: list<array{date: \DateTimeImmutable, ouvert: bool, dans_le_mois: bool, reservations: list<array{reservation: Reservation, ligne: int, lignes: int}>, indisponibilites: list<array{indisponibilite: Indisponibilite, ligne: int, lignes: int}>}>,
     *     heures: list<array{heure: int, ligne: int}>,
     *     lignes: int,
     *     en_attente: int,
     * }
     */
    public function construire(string $vue, \DateTimeImmutable $date): array
    {
        $jour = $date->setTime(0, 0);
        [$debut, $fin, $precedente, $suivante] = match ($vue) {
            'jour' => [$jour, $jour->modify('+1 day'), $jour->modify('-1 day'), $jour->modify('+1 day')],
            'mois' => [
                // Grille complète : du lundi de la première semaine au dimanche de la dernière.
                $jour->modify('first day of this month')->modify('monday this week'),
                $jour->modify('last day of this month')->modify('sunday this week')->modify('+1 day'),
                $jour->modify('first day of previous month'),
                $jour->modify('first day of next month'),
            ],
            default => [$jour->modify('monday this week'), $jour->modify('monday this week')->modify('+7 days'), $jour->modify('-7 days'), $jour->modify('+7 days')],
        };

        [$ouverture, $fermeture] = $this->amplitude();
        $reservations = $this->reservations->findPourAgenda($debut, $fin, self::STATUTS_AFFICHES);
        foreach ($reservations as $reservation) {
            $ouverture = min($ouverture, self::minutes($reservation->getDebut()));
            $fermeture = max($fermeture, self::minutes($reservation->getFin()));
        }
        $ouverture = intdiv($ouverture, 60) * 60;
        $fermeture = (int) ceil($fermeture / 60) * 60;

        $joursOuverts = [];
        foreach ($this->horaires->findToutesOrdonnees() as $horaire) {
            $joursOuverts[$horaire->getJourSemaine()] = true;
        }
        $indisponibilites = $this->indisponibilites->findChevauchant($debut, $fin);

        $jours = [];
        for ($d = $debut; $d < $fin; $d = $d->modify('+1 day')) {
            $lendemain = $d->modify('+1 day');
            $jours[] = [
                'date' => $d,
                'ouvert' => isset($joursOuverts[(int) $d->format('N')]),
                'dans_le_mois' => 'mois' !== $vue || $d->format('m') === $jour->format('m'),
                'reservations' => array_values(array_map(
                    static fn (Reservation $r): array => ['reservation' => $r, ...self::position($r->getDebut(), $r->getFin(), $ouverture, $fermeture)],
                    array_filter($reservations, static fn (Reservation $r): bool => $r->getDebut() >= $d && $r->getDebut() < $lendemain),
                )),
                'indisponibilites' => array_values(array_map(
                    static fn (Indisponibilite $i): array => ['indisponibilite' => $i, ...self::position(max($i->getDebut(), $d), min($i->getFin(), $lendemain), $ouverture, $fermeture)],
                    array_filter($indisponibilites, static fn (Indisponibilite $i): bool => $i->getDebut() < $lendemain && $i->getFin() > $d),
                )),
            ];
        }

        $heures = [];
        for ($minutes = $ouverture; $minutes < $fermeture; $minutes += 60) {
            $heures[] = ['heure' => intdiv($minutes, 60), 'ligne' => intdiv($minutes - $ouverture, self::PAS_MINUTES) + 1];
        }

        return [
            'vue' => $vue,
            'debut' => $debut,
            'fin' => $fin,
            'precedente' => $precedente,
            'suivante' => $suivante,
            'jours' => $jours,
            'heures' => $heures,
            'lignes' => intdiv($fermeture - $ouverture, self::PAS_MINUTES),
            'en_attente' => \count(array_filter($reservations, static fn (Reservation $r): bool => StatutReservation::EN_ATTENTE === $r->getStatut())),
        ];
    }

    /**
     * Heures d'ouverture les plus larges de la semaine, en minutes depuis minuit (9 h – 19 h par défaut).
     *
     * @return array{int, int}
     */
    private function amplitude(): array
    {
        $ouverture = 9 * 60;
        $fermeture = 19 * 60;
        foreach ($this->horaires->findToutesOrdonnees() as $horaire) {
            $ouverture = min($ouverture, self::minutes($horaire->getHeureDebut()));
            $fermeture = max($fermeture, self::minutes($horaire->getHeureFin()));
        }

        return [$ouverture, $fermeture];
    }

    /**
     * Ligne de départ et nombre de lignes dans la grille (une ligne = 15 minutes), bornés à la journée affichée.
     *
     * @return array{ligne: int, lignes: int}
     */
    private static function position(\DateTimeImmutable $debut, \DateTimeImmutable $fin, int $ouverture, int $fermeture): array
    {
        $debutMinutes = max($ouverture, self::minutes($debut));
        $finMinutes = $fin->format('Y-m-d') > $debut->format('Y-m-d') ? $fermeture : min($fermeture, self::minutes($fin));

        return [
            'ligne' => intdiv($debutMinutes - $ouverture, self::PAS_MINUTES) + 1,
            'lignes' => max(1, (int) ceil(($finMinutes - $debutMinutes) / self::PAS_MINUTES)),
        ];
    }

    private static function minutes(\DateTimeImmutable $date): int
    {
        return (int) $date->format('G') * 60 + (int) $date->format('i');
    }
}
