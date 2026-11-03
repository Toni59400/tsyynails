<?php

declare(strict_types=1);

namespace App\Service\Planning;

use App\Entity\Indisponibilite;
use App\Entity\Reservation;
use App\Enum\StatutReservation;
use App\Repository\IndisponibiliteRepository;
use App\Repository\ReservationRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Flux iCal (RFC 5545) de l'agenda du salon, pour s'y abonner depuis Google Agenda, Apple Calendrier, Outlook…
 * Rendez-vous à valider, confirmés et honorés (2 mois en arrière, 1 an en avant), congés et fermetures.
 *
 * Minimisation : l'agenda externe (Google, Apple…) ne reçoit que le prénom, l'initiale du nom et la prestation ;
 * téléphone et détails restent dans l'admin, accessible par le lien de chaque événement.
 */
final class FluxIcs
{
    private const PASSE = '-2 months';
    private const AVENIR = '+1 year';

    /**
     * @param array<string, mixed> $salon
     */
    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly IndisponibiliteRepository $indisponibilites,
        private readonly UrlGeneratorInterface $urls,
        private readonly ClockInterface $clock,
        #[Autowire('%app.salon%')] private readonly array $salon,
    ) {
    }

    public function generer(): string
    {
        $maintenant = $this->clock->now();
        $debut = $maintenant->modify(self::PASSE);
        $fin = $maintenant->modify(self::AVENIR);
        $nom = (string) ($this->salon['nom'] ?? 'Salon');

        $lignes = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//'.$nom.'//Agenda du salon//FR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.self::texte('Rendez-vous '.$nom),
            'X-WR-TIMEZONE:Europe/Paris',
            'REFRESH-INTERVAL;VALUE=DURATION:PT15M',
            'X-PUBLISHED-TTL:PT15M',
        ];
        foreach ($this->reservations->findPourAgenda($debut, $fin, Agenda::STATUTS_AFFICHES) as $reservation) {
            array_push($lignes, ...$this->evenementRendezVous($reservation, $maintenant));
        }
        foreach ($this->indisponibilites->findChevauchant($debut, $fin) as $indisponibilite) {
            array_push($lignes, ...$this->evenementFermeture($indisponibilite, $maintenant));
        }
        $lignes[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(self::plier(...), $lignes))."\r\n";
    }

    /**
     * @return list<string>
     */
    private function evenementRendezVous(Reservation $reservation, \DateTimeImmutable $maintenant): array
    {
        $client = $reservation->getClient();
        $titre = trim($client->getPrenom().' '.mb_substr($client->getNom(), 0, 1).'.').' · '.$reservation->getPrestation()->getNom();
        if (null !== $reservation->getPrestationDuoNom()) {
            $titre .= ' + '.$reservation->getPrestationDuoNom().' (duo)';
        }
        if (null !== $reservation->getSupplementNom()) {
            $titre .= ' + '.$reservation->getSupplementNom();
        }
        $statut = $reservation->getStatut();
        if (StatutReservation::EN_ATTENTE === $statut) {
            $titre = 'À valider · '.$titre;
        }
        $lien = $this->urls->generate('admin_reservation_detail', ['entityId' => $reservation->getId()], UrlGeneratorInterface::ABSOLUTE_URL);

        return [
            'BEGIN:VEVENT',
            'UID:rendez-vous-'.$reservation->getId().'@'.$this->domaine(),
            'DTSTAMP:'.self::utc($maintenant),
            'DTSTART:'.self::utc($reservation->getDebut()),
            'DTEND:'.self::utc($reservation->getFin()),
            'SUMMARY:'.self::texte($titre),
            'DESCRIPTION:'.self::texte(\sprintf("%s.\nFiche complète (téléphone, paiement) : %s", $statut->libelle(), $lien)),
            'URL:'.$lien,
            'STATUS:'.(StatutReservation::EN_ATTENTE === $statut ? 'TENTATIVE' : 'CONFIRMED'),
            'TRANSP:OPAQUE',
            'END:VEVENT',
        ];
    }

    /**
     * @return list<string>
     */
    private function evenementFermeture(Indisponibilite $indisponibilite, \DateTimeImmutable $maintenant): array
    {
        return [
            'BEGIN:VEVENT',
            'UID:fermeture-'.$indisponibilite->getId().'@'.$this->domaine(),
            'DTSTAMP:'.self::utc($maintenant),
            'DTSTART:'.self::utc($indisponibilite->getDebut()),
            'DTEND:'.self::utc($indisponibilite->getFin()),
            'SUMMARY:'.self::texte('Salon fermé'.(null !== $indisponibilite->getMotif() && '' !== $indisponibilite->getMotif() ? ' · '.$indisponibilite->getMotif() : '')),
            'TRANSP:OPAQUE',
            'END:VEVENT',
        ];
    }

    private function domaine(): string
    {
        return parse_url($this->urls->generate('app_accueil', [], UrlGeneratorInterface::ABSOLUTE_URL), \PHP_URL_HOST) ?: 'tsyynails';
    }

    private static function utc(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    /** Échappement des valeurs texte (RFC 5545, 3.3.11). */
    private static function texte(string $valeur): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $valeur);
    }

    /** Lignes de 75 octets au plus, suite précédée d'une espace (RFC 5545, 3.1), sans couper un caractère UTF-8. */
    private static function plier(string $ligne): string
    {
        $plie = '';
        $longueur = 0;
        foreach (mb_str_split($ligne) as $caractere) {
            if ($longueur + \strlen($caractere) > 75) {
                $plie .= "\r\n ";
                $longueur = 1;
            }
            $plie .= $caractere;
            $longueur += \strlen($caractere);
        }

        return $plie;
    }
}
