<?php

declare(strict_types=1);

namespace App\Service\Fidelite;

use App\Entity\Client;
use App\Entity\MouvementPoints;
use App\Entity\Parametre;
use App\Entity\Prestation;
use App\Entity\RecompenseFidelite;
use App\Entity\Reservation;
use App\Entity\Supplement;
use App\Enum\MotifMouvementPoints;
use App\Repository\MouvementPointsRepository;
use App\Repository\ParametreRepository;
use App\Repository\RecompenseFideliteRepository;
use App\Repository\ReservationRepository;
use App\Service\Reservation\Tarification;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Règles du programme de fidélité : gain (points par euro payé), paliers, statut, expiration.
 * Le solde est toujours la somme des mouvements ; ce service est le seul à en créer.
 */
final class ProgrammeFidelite
{
    /** Horizon de réservation des autres clientes, en jours. */
    public const HORIZON_STANDARD_JOURS = 28;

    public function __construct(
        private readonly MouvementPointsRepository $mouvements,
        private readonly RecompenseFideliteRepository $recompenses,
        private readonly ReservationRepository $reservations,
        private readonly ParametreRepository $parametres,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Points gagnés pour un montant payé : 45,00 € → 45 points (au taux par défaut). */
    public function pointsPour(int $montantCentimes): int
    {
        return intdiv(max(0, $montantCentimes), 100) * $this->parametres->valeur(Parametre::POINTS_PAR_EURO);
    }

    public function solde(Client $client): int
    {
        return $this->mouvements->soldePour($client);
    }

    /**
     * @return list<RecompenseFidelite>
     */
    public function paliers(): array
    {
        return $this->recompenses->findActives();
    }

    /**
     * Récompenses qu'une cliente peut utiliser en ligne sur cette prestation (et son supplément) :
     * réduction, points suffisants, et pas plus que le plafond du prix total.
     *
     * @return list<RecompenseFidelite>
     */
    public function utilisablesEnLigne(Client $client, Prestation $prestation, ?Supplement $supplement = null): array
    {
        $solde = $this->solde($client);

        return array_values(array_filter(
            $this->paliers(),
            fn (RecompenseFidelite $r): bool => $r->estUtilisableEnLigne()
                && $r->getSeuilPoints() <= $solde
                && $this->respecteLePlafond($r, Tarification::prixCentimes($prestation, $supplement)),
        ));
    }

    public function respecteLePlafond(RecompenseFidelite $recompense, int $prixCentimes): bool
    {
        return $recompense->getValeurCentimes() * 100 <= $prixCentimes * $this->parametres->valeur(Parametre::REDUCTION_MAX_POURCENTAGE);
    }

    /**
     * Tout ce qu'il faut pour afficher la carte de fidélité (espace cliente, fiche admin).
     *
     * @return array{solde: int, paliers: list<array{recompense: RecompenseFidelite, atteint: bool, manque: int}>, prochain: ?RecompenseFidelite, progression: int, expiration: ?\DateTimeImmutable, fidele: bool, visites: int, visites_requises: int, points_par_euro: int}
     */
    public function etat(Client $client): array
    {
        $solde = $this->solde($client);
        $paliers = $this->paliers();
        $prochain = null;
        foreach ($paliers as $palier) {
            if ($palier->getSeuilPoints() > $solde) {
                $prochain = $palier;
                break;
            }
        }

        $visites = $this->visitesSurUnAn($client);

        return [
            'solde' => $solde,
            'paliers' => array_map(static fn (RecompenseFidelite $r): array => [
                'recompense' => $r,
                'atteint' => $solde >= $r->getSeuilPoints(),
                'manque' => max(0, $r->getSeuilPoints() - $solde),
            ], $paliers),
            'prochain' => $prochain,
            'progression' => null === $prochain ? 100 : (int) floor(100 * $solde / $prochain->getSeuilPoints()),
            'expiration' => $solde > 0 ? $this->dateExpiration($client) : null,
            'fidele' => $visites >= $this->parametres->valeur(Parametre::FIDELE_VISITES),
            'visites' => $visites,
            'visites_requises' => $this->parametres->valeur(Parametre::FIDELE_VISITES),
            'points_par_euro' => $this->parametres->valeur(Parametre::POINTS_PAR_EURO),
        ];
    }

    public function dateExpiration(Client $client): ?\DateTimeImmutable
    {
        return $this->mouvements->dernierGainPour($client)
            ?->modify(\sprintf('+%d months', $this->parametres->valeur(Parametre::EXPIRATION_POINTS_MOIS)));
    }

    public function visitesSurUnAn(Client $client): int
    {
        return $this->reservations->countHonoreesDepuis($client, $this->clock->now()->modify('-12 months'));
    }

    /** Nombre de jours à l'avance où la cliente peut réserver (plus pour les fidèles). */
    public function horizonJours(?Client $client): int
    {
        if (null !== $client && $this->visitesSurUnAn($client) >= $this->parametres->valeur(Parametre::FIDELE_VISITES)) {
            return max(self::HORIZON_STANDARD_JOURS, $this->parametres->valeur(Parametre::HORIZON_FIDELE_JOURS));
        }

        return self::HORIZON_STANDARD_JOURS;
    }

    /**
     * Rendez-vous honoré : points sur le prix payé (après réduction), une seule fois par réservation.
     */
    public function crediterVisite(Reservation $reservation, ?string $auteur): int
    {
        $points = $this->pointsPour($reservation->getPrixCentimes() - $reservation->getReductionCentimes());
        if ($points > 0) {
            $this->entityManager->persist(new MouvementPoints($reservation->getClient(), $points, MotifMouvementPoints::VISITE, $reservation, $auteur));
        }

        return $points;
    }

    /** Bienvenue : une seule fois par cliente, quel que soit le nombre de comptes. */
    public function crediterBonusInscription(Client $client): int
    {
        $bonus = $this->parametres->valeur(Parametre::BONUS_INSCRIPTION_POINTS);
        if ($bonus <= 0 || $this->mouvements->existePour($client, MotifMouvementPoints::INSCRIPTION)) {
            return 0;
        }

        $this->entityManager->persist(new MouvementPoints($client, $bonus, MotifMouvementPoints::INSCRIPTION, null, null, 'Création du compte'));
        $this->entityManager->flush();

        return $bonus;
    }

    /**
     * Récompense remise au salon (avantage en nature ou réduction sur place), par la prothésiste.
     */
    public function echangerAuSalon(Client $client, RecompenseFidelite $recompense, string $auteur): void
    {
        if (!$recompense->isActive()) {
            throw new \LogicException('Cette récompense n\'est plus proposée.');
        }
        $solde = $this->solde($client);
        if ($solde < $recompense->getSeuilPoints()) {
            throw new \LogicException(\sprintf('Points insuffisants : %d sur %d.', $solde, $recompense->getSeuilPoints()));
        }

        $this->entityManager->persist(new MouvementPoints($client, -$recompense->getSeuilPoints(), MotifMouvementPoints::UTILISATION, null, $auteur, $recompense->getNom().' (au salon)'));
        $this->entityManager->flush();
        $this->logger->notice('Récompense remise au salon.', ['client' => $client->getId(), 'recompense' => $recompense->getId(), 'par' => $auteur]);
    }

    /** Points perdus après la période d'inactivité. */
    public function expirer(Client $client): int
    {
        $solde = $this->solde($client);
        if ($solde <= 0) {
            return 0;
        }

        $this->entityManager->persist(new MouvementPoints($client, -$solde, MotifMouvementPoints::EXPIRATION, null, null, 'Points expirés'));
        $this->entityManager->flush();

        return $solde;
    }
}
