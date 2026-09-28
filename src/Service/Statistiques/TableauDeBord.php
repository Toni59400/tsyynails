<?php

declare(strict_types=1);

namespace App\Service\Statistiques;

use App\Entity\Reservation;
use App\Repository\ClientRepository;
use App\Repository\ReservationRepository;
use App\Service\Planning\Agenda;
use Psr\Clock\ClockInterface;

/**
 * Indicateurs du tableau de bord de l'admin.
 */
final class TableauDeBord
{
    public const PERIODES = ['jour' => 'Jour', 'semaine' => 'Semaine', 'mois' => 'Mois', 'annee' => 'Année'];

    /** Au-delà, l'empreinte Stripe expire : la demande doit être traitée avant. */
    public const JOURS_AVANT_EXPIRATION = 7;

    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly ClientRepository $clientes,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function construire(string $periode, \DateTimeImmutable $date): array
    {
        $maintenant = $this->clock->now();
        $aujourdhui = $maintenant->setTime(0, 0);
        $lundi = $aujourdhui->modify('monday this week');

        [$debut, $fin, $precedente, $suivante] = self::bornes($periode, $date);
        $dureePrecedente = self::bornes($periode, $precedente);

        $stats = $this->reservations->statistiquesEntre($debut, $fin);
        $statsPrecedentes = $this->reservations->statistiquesEntre($dureePrecedente[0], $dureePrecedente[1]);
        $realisees = $stats['honorees'] + $stats['non_honorees'];

        $enAttente = $this->reservations->findEnAttente();

        return [
            'periode' => $periode,
            'debut' => $debut,
            'fin' => $fin,
            'precedente' => $precedente,
            'suivante' => $suivante,
            'en_cours' => $debut <= $maintenant && $maintenant < $fin,

            'ca' => $stats['ca_realise'],
            'ca_precedent' => $statsPrecedentes['ca_realise'],
            'evolution' => self::evolution($stats['ca_realise'], $statsPrecedentes['ca_realise']),
            'ca_prevu' => $stats['ca_prevu'],
            'prestations_realisees' => $stats['honorees'],
            'panier_moyen' => $stats['honorees'] > 0 ? intdiv($stats['ca_realise'], $stats['honorees']) : 0,
            'non_honores' => $stats['non_honorees'],
            'taux_non_honores' => $realisees > 0 ? (int) round($stats['non_honorees'] * 100 / $realisees) : 0,
            'nouvelles_clientes' => $this->clientes->countCreeesEntre($debut, $fin),
            'meilleures_prestations' => $this->reservations->meilleuresPrestationsEntre($debut, $fin),

            'rdv_aujourdhui' => $this->reservations->findPourAgenda($aujourdhui, $aujourdhui->modify('+1 day'), Agenda::STATUTS_AFFICHES),
            'prochains_rdv' => $this->reservations->findProchains($maintenant, 6),
            'semaine' => $this->reservations->statistiquesEntre($lundi, $lundi->modify('+7 days')),
            'rdv_semaine' => \count($this->reservations->findPourAgenda($lundi, $lundi->modify('+7 days'), Agenda::STATUTS_AFFICHES)),
            'en_attente' => $enAttente,
            'en_attente_urgentes' => \count(array_filter(
                $enAttente,
                fn (Reservation $r): bool => $r->getCreatedAt() < $maintenant->modify(\sprintf('-%d days', self::JOURS_AVANT_EXPIRATION - 2)),
            )),
        ];
    }

    /**
     * @return array{\DateTimeImmutable, \DateTimeImmutable, \DateTimeImmutable, \DateTimeImmutable} début, fin (exclue), période précédente, période suivante
     */
    public static function bornes(string $periode, \DateTimeImmutable $date): array
    {
        $jour = $date->setTime(0, 0);

        return match ($periode) {
            'jour' => [$jour, $jour->modify('+1 day'), $jour->modify('-1 day'), $jour->modify('+1 day')],
            'semaine' => [
                $lundi = $jour->modify('monday this week'),
                $lundi->modify('+7 days'),
                $lundi->modify('-7 days'),
                $lundi->modify('+7 days'),
            ],
            'annee' => [
                $janvier = $jour->setDate((int) $jour->format('Y'), 1, 1),
                $janvier->modify('+1 year'),
                $janvier->modify('-1 year'),
                $janvier->modify('+1 year'),
            ],
            default => [
                $premier = $jour->modify('first day of this month'),
                $premier->modify('+1 month'),
                $premier->modify('-1 month'),
                $premier->modify('+1 month'),
            ],
        };
    }

    /** Variation en pourcentage, null si la période précédente est vide. */
    public static function evolution(int $actuel, int $precedent): ?int
    {
        return 0 === $precedent ? null : (int) round(($actuel - $precedent) * 100 / $precedent);
    }
}
