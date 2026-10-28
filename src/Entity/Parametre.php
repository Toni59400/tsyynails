<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ParametreRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Réglage modifiable depuis l'admin (clé / valeur entière).
 * Un réglage absent de la base prend sa valeur par défaut (DEFINITIONS).
 */
#[ORM\Entity(repositoryClass: ParametreRepository::class)]
class Parametre
{
    /** Acompte demandé à la réservation, en pourcentage du prix. */
    public const ACOMPTE_POURCENTAGE = 'reservation.acompte_pourcentage';
    /** Délai minimum avant un rendez-vous pour réserver, en heures. */
    public const DELAI_MIN_RESERVATION_HEURES = 'reservation.delai_min_heures';
    /** Annulation au moins ce nombre d'heures avant le rendez-vous : acompte remboursé. */
    public const ANNULATION_GRATUITE_HEURES = 'reservation.annulation_gratuite_heures';

    /** Points gagnés par euro réellement payé (rendez-vous honoré). */
    public const POINTS_PAR_EURO = 'fidelite.points_par_euro';
    /** Les points expirent après ce nombre de mois sans nouveau gain. */
    public const EXPIRATION_POINTS_MOIS = 'fidelite.expiration_mois';
    /** Points offerts à la création d'un compte (email vérifié). */
    public const BONUS_INSCRIPTION_POINTS = 'fidelite.bonus_inscription';
    /** Une récompense ne peut pas dépasser ce pourcentage du prix de la prestation. */
    public const REDUCTION_MAX_POURCENTAGE = 'fidelite.reduction_max_pourcentage';
    /** Nombre de visites sur 12 mois pour obtenir le statut « Cliente fidèle ». */
    public const FIDELE_VISITES = 'fidelite.statut_visites';
    /** Réservation ouverte ce nombre de jours à l'avance pour les clientes fidèles. */
    public const HORIZON_FIDELE_JOURS = 'fidelite.horizon_fidele_jours';
    /** Points de la marraine quand sa filleule vient pour la première fois. */
    public const PARRAINAGE_POINTS_MARRAINE = 'parrainage.points_marraine';
    /** Points de bienvenue de la filleule à son premier rendez-vous. */
    public const PARRAINAGE_POINTS_FILLEULE = 'parrainage.points_filleule';
    /** Nombre maximal de filleules récompensées par marraine sur 12 mois. */
    public const PARRAINAGE_MAX_PAR_AN = 'parrainage.max_par_an';
    /** Réservation en ligne avec acompte par carte (Stripe). */
    public const PAIEMENT_CARTE_ACTIF = 'paiement.carte_actif';
    /** Réservation en ligne sans acompte, tout réglé en espèces au rendez-vous. */
    public const PAIEMENT_ESPECES_ACTIF = 'paiement.especes_actif';
    /** Rendez-vous commençant à partir de cette heure : majoration du soir. */
    public const MAJORATION_SOIR_HEURE = 'reservation.majoration_soir_heure';
    /** Montant de la majoration du soir, en euros (0 = aucune). */
    public const MAJORATION_SOIR_EUROS = 'reservation.majoration_soir_euros';
    /** Offre « RDV duo » affichée et réservable en ligne (0/1). */
    public const DUO_ACTIF = 'duo.actif';
    /** Réduction du RDV duo, en % du prix des prestations. */
    public const DUO_REDUCTION_POURCENTAGE = 'duo.reduction_pourcentage';
    /** Points gagnés en rendez-vous sur 12 mois pour obtenir la réduction à vie (0 = désactivée). */
    public const PRIVILEGE_SEUIL_POINTS = 'privilege.seuil_points';
    /** Réduction à vie la première année, en %. */
    public const PRIVILEGE_REDUCTION_DEPART = 'privilege.reduction_depart';
    /** Réduction ajoutée chaque année de fidélité confirmée, en %. */
    public const PRIVILEGE_REDUCTION_PAS = 'privilege.reduction_pas';
    /** Plafond de la réduction à vie, en %. */
    public const PRIVILEGE_REDUCTION_MAX = 'privilege.reduction_max';
    /** Rendez-vous honorés par an pour garder (et augmenter) la réduction à vie. */
    public const PRIVILEGE_VISITES_ANNUELLES = 'privilege.visites_annuelles';

    /**
     * Réglages proposés dans l'admin : libellé, aide, valeur par défaut, minimum, maximum.
     *
     * @var array<string, array{0: string, 1: string, 2: int, 3: int, 4: int}>
     */
    public const DEFINITIONS = [
        self::PAIEMENT_CARTE_ACTIF => ['Réservation en ligne : acompte par carte', 'La cliente bloque l\'acompte par carte (Stripe), débité à la validation.', 1, 0, 1],
        self::PAIEMENT_ESPECES_ACTIF => ['Réservation en ligne : paiement en espèces au rendez-vous', 'Sans acompte : la demande arrive directement en validation, tout est réglé au salon.', 0, 0, 1],
        self::ACOMPTE_POURCENTAGE => ['Acompte (% du prix)', 'Bloqué à la demande, débité à la validation.', 30, 0, 100],
        self::DELAI_MIN_RESERVATION_HEURES => ['Délai minimum de réservation (heures)', 'Aucun créneau proposé avant ce délai.', 24, 0, 168],
        self::ANNULATION_GRATUITE_HEURES => ['Annulation gratuite jusqu\'à (heures avant)', 'Plus tard, l\'acompte est conservé. En cas d\'absence, il est toujours conservé.', 48, 0, 336],
        self::POINTS_PAR_EURO => ['Points par euro payé', 'Crédités quand le rendez-vous est honoré, sur le prix payé après réduction.', 1, 0, 10],
        self::EXPIRATION_POINTS_MOIS => ['Expiration des points (mois sans visite)', 'Un email prévient la cliente 30 jours avant.', 12, 1, 60],
        self::BONUS_INSCRIPTION_POINTS => ['Bonus à la création du compte (points)', 'Offert une seule fois, après vérification de l\'email.', 20, 0, 500],
        self::REDUCTION_MAX_POURCENTAGE => ['Récompense maximale (% du prix)', 'Une récompense en ligne ne peut pas dépasser cette part du prix.', 50, 0, 100],
        self::FIDELE_VISITES => ['Statut « Cliente fidèle » (visites sur 12 mois)', 'Visites honorées nécessaires pour obtenir le statut.', 8, 1, 52],
        self::HORIZON_FIDELE_JOURS => ['Réservation à l\'avance pour les fidèles (jours)', 'Les autres clientes réservent jusqu\'à 28 jours à l\'avance.', 56, 28, 180],
        self::PARRAINAGE_POINTS_MARRAINE => ['Parrainage : points de la marraine', 'Versés au premier rendez-vous honoré de la filleule. 0 = parrainage désactivé.', 50, 0, 500],
        self::PARRAINAGE_POINTS_FILLEULE => ['Parrainage : points de la filleule', 'Versés à son premier rendez-vous honoré, en plus des points de la visite.', 25, 0, 500],
        self::PARRAINAGE_MAX_PAR_AN => ['Parrainage : filleules récompensées par an', 'Au-delà, les parrainages ne rapportent plus de points à la marraine pendant 12 mois.', 10, 1, 100],
        self::MAJORATION_SOIR_HEURE => ['Majoration du soir : à partir de (heure)', 'Un rendez-vous qui COMMENCE à cette heure ou plus tard est majoré. Exemple : 19 = à partir de 19 h 00.', 19, 0, 23],
        self::MAJORATION_SOIR_EUROS => ['Majoration du soir (euros)', 'Ajoutée au prix, jamais réduite par les remises. 0 = pas de majoration.', 0, 0, 100],
        self::DUO_ACTIF => ['Proposer le RDV duo', 'Affiche l\'offre « RDV duo » (deux personnes à la suite) dans les prestations et la réservation en ligne.', 0, 0, 1],
        self::DUO_REDUCTION_POURCENTAGE => ['RDV duo : réduction (%)', 'Sur le prix total des prestations du duo (hors majoration du soir).', 10, 0, 50],
        self::PRIVILEGE_SEUIL_POINTS => ['Réduction à vie : points à gagner en 12 mois', 'Points gagnés grâce aux rendez-vous honorés des 12 derniers mois (≈ 10 à 11 rendez-vous). 0 = désactivée.', 340, 0, 5000],
        self::PRIVILEGE_REDUCTION_DEPART => ['Réduction à vie : première année (%)', 'Accordée dès que le seuil est atteint.', 10, 0, 50],
        self::PRIVILEGE_REDUCTION_PAS => ['Réduction à vie : bonus par année confirmée (%)', 'Ajouté à chaque anniversaire si la cliente a fait assez de rendez-vous dans l\'année.', 5, 0, 20],
        self::PRIVILEGE_REDUCTION_MAX => ['Réduction à vie : plafond (%)', 'La réduction ne dépasse jamais ce pourcentage.', 20, 0, 50],
        self::PRIVILEGE_VISITES_ANNUELLES => ['Réduction à vie : rendez-vous par an pour la garder', 'En dessous, à l\'anniversaire, la cliente perd sa réduction (elle peut la regagner en atteignant à nouveau le seuil).', 8, 1, 52],
    ];

    /** Réglage oui / non (minimum 0, maximum 1) : affiché comme un interrupteur dans l'admin. */
    public static function estOuiNon(string $cle): bool
    {
        $definition = self::DEFINITIONS[$cle] ?? null;

        return null !== $definition && 0 === $definition[3] && 1 === $definition[4];
    }

    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $cle;

    #[ORM\Column(length: 255)]
    private string $valeur;

    public function __construct(string $cle, string $valeur)
    {
        $this->cle = $cle;
        $this->valeur = $valeur;
    }

    public function getCle(): string
    {
        return $this->cle;
    }

    public function getValeur(): string
    {
        return $this->valeur;
    }

    public function setValeur(string $valeur): static
    {
        $this->valeur = $valeur;

        return $this;
    }
}
