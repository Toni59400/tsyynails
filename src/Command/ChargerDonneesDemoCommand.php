<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\CarteFidelite;
use App\Entity\Client;
use App\Entity\HoraireOuverture;
use App\Entity\Indisponibilite;
use App\Entity\Inspiration;
use App\Entity\MouvementPoints;
use App\Entity\Parametre;
use App\Entity\Photo;
use App\Entity\Prestation;
use App\Entity\Reservation;
use App\Enum\MotifMouvementPoints;
use App\Enum\StatutReservation;
use App\Service\Reservation\Tarification;
use App\Service\Securite\ChiffrementDonnees;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Données fictives pour développer et faire des démonstrations.
 * Refusée en production. Ne touche jamais aux comptes (table user).
 *
 * Toutes les coordonnées sont fictives : emails en example.com,
 * téléphones dans la tranche 06 39 98 réservée par l'ARCEP à la fiction.
 */
#[AsCommand(name: 'app:demo:charger', description: 'Remplit la base de développement avec des données fictives (prestations, horaires, clientes, réservations…)')]
final class ChargerDonneesDemoCommand extends Command
{
    private const GRAINE = 20260928;
    private const JOURS_PASSES = 70;
    private const JOURS_FUTURS = 28;
    private const VALEUR_POINT_CENTIMES = 10;
    private const ACOMPTE_POURCENTAGE = 30;
    private const POINTS_PAR_UTILISATION = 100;

    private const PRESTATIONS = [
        ['Pose complète gel', 'Allongement au gel sur chablon, forme et longueur au choix, couleur unie ou french.', 5500, 120, 55],
        ['Remplissage gel', 'Entretien d\'une pose gel toutes les 3 à 4 semaines, avec changement de couleur.', 4500, 90, 45],
        ['Semi-permanent mains', 'Vernis semi-permanent sur ongles naturels, tenue jusqu\'à 3 semaines.', 3000, 60, 30],
        ['Gainage gel sur ongles naturels', 'Renforce les ongles fragiles sans rallonger, finition couleur au choix.', 4000, 75, 40],
        ['Capsules américaines', 'Capsules en gel souple posées en une fois : légères, naturelles et rapides.', 5000, 105, 50],
        ['Manucure russe', 'Soin complet des cuticules à la ponceuse, finition vernis ou huile nourrissante.', 3500, 60, 35],
        ['Semi-permanent pieds', 'Beauté des pieds avec pose de vernis semi-permanent.', 3500, 60, 35],
        ['Nail art (10 ongles)', 'Décor à main levée, strass, effets chrome ou french fantaisie, en complément d\'une pose.', 1000, 15, 10],
        ['Dépose complète et soin', 'Retrait en douceur du gel ou du semi-permanent, puis soin des ongles.', 2000, 45, 20],
    ];

    /** Plages d'ouverture : jour ISO, début, fin. */
    private const HORAIRES = [
        [2, '09:30', '12:30'], [2, '13:30', '19:00'],
        [3, '09:30', '12:30'], [3, '13:30', '19:00'],
        [4, '09:30', '12:30'], [4, '13:30', '19:00'],
        [5, '09:30', '12:30'], [5, '13:30', '19:30'],
        [6, '09:00', '16:00'],
    ];

    private const PRENOMS = [
        'Emma', 'Léa', 'Chloé', 'Manon', 'Camille', 'Inès', 'Sarah', 'Jade', 'Lina', 'Louise',
        'Zoé', 'Clara', 'Julie', 'Anaïs', 'Laura', 'Mélissa', 'Océane', 'Marine', 'Pauline', 'Élodie',
        'Nadia', 'Yasmine', 'Amandine', 'Charlotte', 'Justine', 'Morgane', 'Aurélie', 'Céline', 'Nathalie', 'Sandrine',
        'Émilie', 'Margaux', 'Lucie', 'Alice', 'Maëlys', 'Romane', 'Salomé', 'Eva', 'Noémie', 'Fanny',
    ];

    private const NOMS = [
        'Martin', 'Bernard', 'Dubois', 'Thomas', 'Robert', 'Richard', 'Petit', 'Durand', 'Leroy', 'Moreau',
        'Simon', 'Laurent', 'Lefebvre', 'Michel', 'Garcia', 'David', 'Bertrand', 'Roux', 'Vincent', 'Fournier',
        'Morel', 'Girard', 'André', 'Lefèvre', 'Mercier', 'Dupont', 'Lambert', 'Bonnet', 'François', 'Martinez',
        'Legrand', 'Garnier', 'Faure', 'Rousseau', 'Blanc', 'Guerin', 'Muller', 'Henry', 'Roussel', 'Nicolas',
    ];

    private const NOTES_SANTE = [
        'Allergie aux acrylates constatée en 2024 : utiliser la gamme hypoallergénique.',
        'Ongles très fins et cassants, éviter le ponçage appuyé.',
        'Eczéma sur les mains par périodes : vérifier la peau avant la pose.',
        'Diabétique : attention particulière aux cuticules et aux pieds.',
    ];

    private const INSPIRATIONS = [
        ['Inspiration été', 'Couleurs vitaminées, fleurs et paillettes pour les beaux jours.'],
        ['Automne cosy', 'Tons chauds, bordeaux, caramel et chocolat.'],
        ['Mariage', 'Nudes délicats, blanc laiteux et touches nacrées pour le grand jour.'],
        ['French revisitée', 'La french classique, inversée, colorée ou dorée.'],
    ];

    /** Légende (texte alternatif), couleur, pointe, décor, prestation (index dans PRESTATIONS), thèmes (index dans INSPIRATIONS). */
    private const PHOTOS = [
        ['French rose poudré sur ongles courts', '#f2d4d0', '#ffffff', null, 2, [3]],
        ['Baby boomer nude en gel, forme amande', '#ecd3c4', '#fbf4ef', null, 0, [2, 3]],
        ['Rouge cerise brillant sur ongles ronds', '#b3263a', null, null, 2, [0]],
        ['Nail art fleurs blanches sur fond nude', '#e7c9b8', null, 'fleurs', 7, [0, 2]],
        ['Dégradé lilas pailleté', '#c9b3dd', null, 'paillettes', 4, [0]],
        ['French inversée dorée', '#f3e3da', '#c9a24a', null, 0, [3]],
        ['Vert sauge mat, forme carrée', '#9fb09a', null, null, 3, [1]],
        ['Effet chrome perlé « glazed donut »', '#efe6e0', null, 'paillettes', 1, [2]],
        ['Semi-permanent bordeaux d\'automne', '#6d1f2c', null, null, 2, [1]],
        ['Pois noirs sur fond blanc laiteux', '#f7f3ef', null, 'points', 7, []],
        ['Beige caramel et fines lignes dorées', '#c99b77', null, 'lignes', 1, [1]],
        ['Rose fuchsia et strass', '#d24d86', null, 'paillettes', 4, [0]],
        ['Corail vitaminé sur les pieds', '#f26b5b', null, null, 6, [0]],
        ['Turquoise lagon et fleurs', '#3fb6b2', null, 'fleurs', null, [0]],
        ['Chocolat chaud et paillettes cuivrées', '#5a3a2e', null, 'paillettes', null, [1]],
        ['Blanc laiteux nacré pour mariée', '#f7f1ea', '#ffffff', 'paillettes', 0, [2]],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ChiffrementDonnees $chiffrement,
        private readonly ClockInterface $clock,
        #[Autowire('%kernel.environment%')] private readonly string $environnement,
        #[Autowire('%app.dossier_galerie%')] private readonly string $dossierGalerie,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('purger', null, InputOption::VALUE_NONE, 'Supprime d\'abord les données existantes (sauf les comptes utilisateurs).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('prod' === $this->environnement) {
            $io->error('Commande interdite en production.');

            return Command::FAILURE;
        }

        $dejaRemplie = $this->entityManager->getRepository(Prestation::class)->count([]) > 0;
        if ($dejaRemplie && !$input->getOption('purger')) {
            $io->error('La base contient déjà des données. Relancez avec --purger pour les remplacer (les comptes utilisateurs sont conservés).');

            return Command::FAILURE;
        }

        if ($input->getOption('purger')) {
            $this->purger();
            $io->note('Données existantes supprimées (comptes conservés).');
        }

        mt_srand(self::GRAINE);
        $maintenant = $this->clock->now();

        $this->creerParametres();
        $horaires = $this->creerHoraires();
        $indisponibilites = $this->creerIndisponibilites($maintenant);
        $prestations = $this->creerPrestations();
        $inspirations = $this->creerInspirations();
        $nombrePhotos = $this->creerPhotos($prestations, $inspirations);
        $clientes = $this->creerClientes($maintenant);
        $nombreCartes = $this->creerCartes($clientes, $maintenant);
        $stats = $this->creerReservations($prestations, $clientes, $horaires, $indisponibilites, $maintenant);

        $this->entityManager->flush();

        $io->success('Données de démonstration chargées.');
        $io->table(['Donnée', 'Nombre'], [
            ['Prestations', \count($prestations)],
            ['Plages horaires', \count($horaires)],
            ['Indisponibilités', \count($indisponibilites)],
            ['Thèmes d\'inspiration', \count($inspirations)],
            ['Photos de galerie', $nombrePhotos],
            ['Clientes', \count($clientes)],
            ['Cartes fidélité', $nombreCartes],
            ['Réservations', array_sum($stats)],
            ...array_map(static fn (string $statut, int $n): array => ['  dont '.$statut, $n], array_keys($stats), $stats),
        ]);

        return Command::SUCCESS;
    }

    private function purger(): void
    {
        foreach ([MouvementPoints::class, CarteFidelite::class, Reservation::class, Client::class, Photo::class, Inspiration::class,
            Prestation::class, HoraireOuverture::class, Indisponibilite::class, Parametre::class] as $classe) {
            $this->entityManager->createQuery(\sprintf('DELETE FROM %s e', $classe))->execute();
        }

        $systemeFichiers = new Filesystem();
        foreach (glob($this->dossierGalerie.'/demo-*.svg') ?: [] as $fichier) {
            $systemeFichiers->remove($fichier);
        }
    }

    private function creerParametres(): void
    {
        foreach ([
            Parametre::VALEUR_POINT_CENTIMES => self::VALEUR_POINT_CENTIMES,
            Parametre::ACOMPTE_POURCENTAGE => self::ACOMPTE_POURCENTAGE,
            Parametre::DELAI_MIN_RESERVATION_HEURES => 24,
        ] as $cle => $valeur) {
            $this->entityManager->persist(new Parametre($cle, (string) $valeur));
        }
    }

    /**
     * @return list<HoraireOuverture>
     */
    private function creerHoraires(): array
    {
        $horaires = [];
        foreach (self::HORAIRES as [$jour, $debut, $fin]) {
            $horaire = new HoraireOuverture($jour, new \DateTimeImmutable('1970-01-01 '.$debut), new \DateTimeImmutable('1970-01-01 '.$fin));
            $this->entityManager->persist($horaire);
            $horaires[] = $horaire;
        }

        return $horaires;
    }

    /**
     * @return list<Indisponibilite>
     */
    private function creerIndisponibilites(\DateTimeImmutable $maintenant): array
    {
        $aujourdhui = $maintenant->setTime(0, 0);
        $jeudiPasse = $aujourdhui->modify('thursday -3 weeks');
        $congesDebut = $aujourdhui->modify('tuesday +2 weeks');
        $rdvPerso = $aujourdhui->modify('friday next week')->setTime(14, 0);

        $indisponibilites = [
            new Indisponibilite($jeudiPasse, $jeudiPasse->modify('+1 day'), 'Formation nail art'),
            new Indisponibilite($congesDebut, $congesDebut->modify('+5 days'), 'Congés'),
            new Indisponibilite($rdvPerso, $rdvPerso->modify('+3 hours'), 'Rendez-vous personnel'),
        ];
        foreach ($indisponibilites as $indisponibilite) {
            $this->entityManager->persist($indisponibilite);
        }

        return $indisponibilites;
    }

    /**
     * @return list<Prestation>
     */
    private function creerPrestations(): array
    {
        $prestations = [];
        foreach (self::PRESTATIONS as $ordre => [$nom, $description, $prix, $duree, $points]) {
            $prestation = (new Prestation($nom, $prix, $duree))
                ->setDescription($description)
                ->setPoints($points)
                ->setOrdre($ordre);
            $this->entityManager->persist($prestation);
            $prestations[] = $prestation;
        }

        // Une ancienne prestation retirée du site, pour vérifier qu'elle n'apparaît plus.
        $this->entityManager->persist((new Prestation('Pose résine (arrêtée)', 5000, 120))->setActive(false)->setOrdre(99));

        return $prestations;
    }

    /**
     * @return list<Inspiration>
     */
    private function creerInspirations(): array
    {
        $inspirations = [];
        foreach (self::INSPIRATIONS as $ordre => [$nom, $description]) {
            $inspiration = (new Inspiration($nom))->setDescription($description)->setOrdre($ordre);
            $this->entityManager->persist($inspiration);
            $inspirations[] = $inspiration;
        }

        return $inspirations;
    }

    /**
     * @param list<Prestation>  $prestations
     * @param list<Inspiration> $inspirations
     */
    private function creerPhotos(array $prestations, array $inspirations): int
    {
        $systemeFichiers = new Filesystem();
        foreach (self::PHOTOS as $index => [$legende, $couleur, $pointe, $decor, $prestation, $themes]) {
            $fichier = \sprintf('demo-%02d.svg', $index + 1);
            $systemeFichiers->dumpFile($this->dossierGalerie.'/'.$fichier, $this->dessinerOngles($couleur, $pointe, $decor));

            $photo = (new Photo($fichier, $legende))
                ->setOrdre($index)
                ->setPrestation(null === $prestation ? null : $prestations[$prestation]);
            foreach ($themes as $theme) {
                $photo->addInspiration($inspirations[$theme]);
            }
            $this->entityManager->persist($photo);
        }

        return \count(self::PHOTOS);
    }

    /**
     * @return list<Client>
     */
    private function creerClientes(\DateTimeImmutable $maintenant): array
    {
        $clientes = [];
        foreach (self::PRENOMS as $index => $prenom) {
            $nom = self::NOMS[$index];
            $cliente = new Client($prenom, $nom, \sprintf('+3363998%04d', 1000 + $index * 37));
            if (mt_rand(1, 10) <= 8) {
                $cliente->setEmail(\sprintf('%s.%s@example.com', $this->sansAccents($prenom), $this->sansAccents($nom)));
            }
            if ($index < \count(self::NOTES_SANTE)) {
                $cliente->enregistrerNotesSante($this->chiffrement->chiffrer(self::NOTES_SANTE[$index]), $maintenant->modify(\sprintf('-%d days', 100 + $index)));
            }
            $this->antidater($cliente, $maintenant->modify(\sprintf('-%d days', mt_rand(self::JOURS_PASSES + 10, 400))));

            $this->entityManager->persist($cliente);
            $clientes[] = $cliente;
        }

        return $clientes;
    }

    /**
     * Les deux tiers des clientes ont une carte ; quelques cartes vierges attendent d'être associées.
     *
     * @param list<Client> $clientes
     */
    private function creerCartes(array $clientes, \DateTimeImmutable $maintenant): int
    {
        $nombre = 0;
        foreach ($clientes as $index => $cliente) {
            if (0 === $index % 3) {
                continue;
            }
            $carte = CarteFidelite::generer();
            $associeeAt = $cliente->getCreatedAt()->modify('+1 hour');
            $this->antidater($carte, $cliente->getCreatedAt());
            $carte->associerA($cliente, $associeeAt);
            $this->entityManager->persist($carte);
            ++$nombre;
        }

        for ($i = 0; $i < 10; ++$i) {
            $carte = CarteFidelite::generer();
            $this->antidater($carte, $maintenant->modify('-30 days'));
            $this->entityManager->persist($carte);
            ++$nombre;
        }

        return $nombre;
    }

    /**
     * Remplit l'agenda jour par jour : dense dans le passé, de plus en plus libre dans le futur.
     *
     * @param list<Prestation>       $prestations
     * @param list<Client>           $clientes
     * @param list<HoraireOuverture> $horaires
     * @param list<Indisponibilite>  $indisponibilites
     *
     * @return array<string, int> nombre de réservations par statut
     */
    private function creerReservations(array $prestations, array $clientes, array $horaires, array $indisponibilites, \DateTimeImmutable $maintenant): array
    {
        $plagesParJour = [];
        foreach ($horaires as $horaire) {
            $plagesParJour[$horaire->getJourSemaine()][] = $horaire;
        }

        // Quelques habituées reviennent beaucoup plus souvent que les autres.
        $tirageClientes = [];
        foreach ($clientes as $index => $cliente) {
            $tirageClientes = [...$tirageClientes, ...array_fill(0, $index < 12 ? 4 : 1, $cliente)];
        }

        $soldes = [];
        $stats = [];
        $aujourdhui = $maintenant->setTime(0, 0);

        for ($decalage = -self::JOURS_PASSES; $decalage <= self::JOURS_FUTURS; ++$decalage) {
            $jour = $aujourdhui->modify(\sprintf('%+d days', $decalage));
            // Taux de remplissage : complet dans le passé, puis l'agenda se libère.
            $remplissage = match (true) {
                $decalage <= 0 => 0.8,
                $decalage <= 7 => 0.7,
                $decalage <= 14 => 0.45,
                default => 0.2,
            };

            foreach ($plagesParJour[(int) $jour->format('N')] ?? [] as $plage) {
                $curseur = $jour->setTime((int) $plage->getHeureDebut()->format('H'), (int) $plage->getHeureDebut()->format('i'));
                $finPlage = $jour->setTime((int) $plage->getHeureFin()->format('H'), (int) $plage->getHeureFin()->format('i'));

                while ($curseur < $finPlage) {
                    $prestation = $this->tirerPrestation($prestations, $curseur, $finPlage);
                    if (null === $prestation || mt_rand() / mt_getrandmax() > $remplissage) {
                        $curseur = $curseur->modify('+'.(mt_rand(1, 4) * 15).' minutes');
                        continue;
                    }

                    $fin = $curseur->modify(\sprintf('+%d minutes', $prestation->getDureeMinutes()));
                    if ($this->estIndisponible($curseur, $fin, $indisponibilites)) {
                        $curseur = $fin;
                        continue;
                    }

                    $cliente = $tirageClientes[array_rand($tirageClientes)];
                    $statut = $this->creerReservation($cliente, $prestation, $curseur, $maintenant, $soldes);
                    $stats[$statut->libelle()] = ($stats[$statut->libelle()] ?? 0) + 1;

                    $curseur = $fin;
                }
            }
        }

        return $stats;
    }

    /**
     * @param array<int, int> $soldes solde de points par cliente (spl_object_id), tenu à jour ici
     */
    private function creerReservation(Client $cliente, Prestation $prestation, \DateTimeImmutable $debut, \DateTimeImmutable $maintenant, array &$soldes): StatutReservation
    {
        $solde = $soldes[spl_object_id($cliente)] ?? 0;
        $passee = $debut < $maintenant;

        // La demande est faite entre 2 et 20 jours avant le rendez-vous, jamais dans le futur.
        $demandeeAt = min($debut->modify(\sprintf('-%d days', mt_rand(2, 20)))->setTime(mt_rand(8, 22), mt_rand(0, 59)), $maintenant->modify('-1 hour'));

        $pointsUtilises = 0;
        $reduction = 0;
        $reductionPossible = self::POINTS_PAR_UTILISATION * self::VALEUR_POINT_CENTIMES;
        if ($solde >= self::POINTS_PAR_UTILISATION && $reductionPossible <= $prestation->getPrixCentimes() && mt_rand(1, 10) <= 4) {
            $pointsUtilises = self::POINTS_PAR_UTILISATION;
            $reduction = $reductionPossible;
        }

        $acompte = Tarification::calculerAcompte($prestation->getPrixCentimes() - $reduction, self::ACOMPTE_POURCENTAGE);
        $reservation = new Reservation($cliente, $prestation, $debut, $acompte, $reduction, $pointsUtilises);
        $this->antidater($reservation, $demandeeAt);
        $this->entityManager->persist($reservation);

        if ($pointsUtilises > 0) {
            $this->mouvement($cliente, -$pointsUtilises, MotifMouvementPoints::UTILISATION, $reservation, $demandeeAt);
            $solde -= $pointsUtilises;
        }

        $statut = $this->tirerStatut($passee, $debut, $maintenant);
        $valideeAt = min($demandeeAt->modify('+'.mt_rand(1, 20).' hours'), $maintenant);

        match ($statut) {
            StatutReservation::EN_ATTENTE => null,
            StatutReservation::CONFIRMEE => $reservation->changerStatut(StatutReservation::CONFIRMEE, $valideeAt),
            StatutReservation::REFUSEE, StatutReservation::EXPIREE => $reservation->changerStatut($statut, $valideeAt),
            StatutReservation::ANNULEE => $reservation->changerStatut(StatutReservation::ANNULEE, $valideeAt),
            StatutReservation::HONOREE, StatutReservation::NON_HONOREE => $this->confirmerPuis($reservation, $statut, $valideeAt),
        };

        if ($pointsUtilises > 0 && \in_array($statut, [StatutReservation::REFUSEE, StatutReservation::EXPIREE, StatutReservation::ANNULEE], true)) {
            $this->mouvement($cliente, $pointsUtilises, MotifMouvementPoints::RESTITUTION, $reservation, $valideeAt);
            $solde += $pointsUtilises;
        }

        if (StatutReservation::HONOREE === $statut) {
            $this->mouvement($cliente, $prestation->getPoints(), MotifMouvementPoints::VISITE, $reservation, $reservation->getFin());
            $solde += $prestation->getPoints();
            $cliente->enregistrerVisite($reservation->getFin());
        }

        $soldes[spl_object_id($cliente)] = $solde;

        return $statut;
    }

    private function tirerStatut(bool $passee, \DateTimeImmutable $debut, \DateTimeImmutable $maintenant): StatutReservation
    {
        $tirage = mt_rand(1, 100);

        if ($passee) {
            return match (true) {
                $tirage <= 84 => StatutReservation::HONOREE,
                $tirage <= 90 => StatutReservation::NON_HONOREE,
                $tirage <= 95 => StatutReservation::ANNULEE,
                $tirage <= 98 => StatutReservation::REFUSEE,
                default => StatutReservation::EXPIREE,
            };
        }

        // Les demandes en attente concernent surtout les jours qui viennent.
        $enAttentePossible = $debut < $maintenant->modify('+10 days');

        return match (true) {
            $enAttentePossible && $tirage <= 20 => StatutReservation::EN_ATTENTE,
            $tirage <= 95 => StatutReservation::CONFIRMEE,
            default => StatutReservation::ANNULEE,
        };
    }

    private function confirmerPuis(Reservation $reservation, StatutReservation $statut, \DateTimeImmutable $valideeAt): void
    {
        $reservation->changerStatut(StatutReservation::CONFIRMEE, $valideeAt);
        $reservation->changerStatut($statut, $reservation->getFin());
    }

    /**
     * @param list<Prestation> $prestations
     */
    private function tirerPrestation(array $prestations, \DateTimeImmutable $debut, \DateTimeImmutable $finPlage): ?Prestation
    {
        // Les poses et remplissages représentent l'essentiel de l'activité.
        $poids = [5, 6, 5, 3, 3, 2, 2, 1, 2];
        $candidates = [];
        foreach ($prestations as $index => $prestation) {
            if ($debut->modify(\sprintf('+%d minutes', $prestation->getDureeMinutes())) <= $finPlage) {
                $candidates = [...$candidates, ...array_fill(0, $poids[$index] ?? 1, $prestation)];
            }
        }

        return [] === $candidates ? null : $candidates[array_rand($candidates)];
    }

    /**
     * @param list<Indisponibilite> $indisponibilites
     */
    private function estIndisponible(\DateTimeImmutable $debut, \DateTimeImmutable $fin, array $indisponibilites): bool
    {
        foreach ($indisponibilites as $indisponibilite) {
            if ($debut < $indisponibilite->getFin() && $fin > $indisponibilite->getDebut()) {
                return true;
            }
        }

        return false;
    }

    private function mouvement(Client $cliente, int $delta, MotifMouvementPoints $motif, Reservation $reservation, \DateTimeImmutable $at): void
    {
        $mouvement = new MouvementPoints($cliente, $delta, $motif, $reservation);
        $this->antidater($mouvement, $at);
        $this->entityManager->persist($mouvement);
    }

    /**
     * Donne une date de création réaliste aux données fictives (le constructeur utilise l'heure courante).
     */
    private function antidater(object $entite, \DateTimeImmutable $date): void
    {
        (new \ReflectionProperty($entite, 'createdAt'))->setValue($entite, $date);
    }

    private function sansAccents(string $texte): string
    {
        return strtolower((string) preg_replace('/[^a-z]/i', '', (string) iconv('UTF-8', 'ASCII//TRANSLIT', $texte)));
    }

    /**
     * Illustration SVG de cinq ongles, en attendant les vraies photos.
     */
    private function dessinerOngles(string $couleur, ?string $pointe, ?string $decor): string
    {
        $ongles = '';
        $positions = [[95, 250, -14], [205, 175, -5], [315, 150, 2], [425, 185, 9], [520, 300, 18]];
        foreach ($positions as $i => [$x, $y, $angle]) {
            $largeur = 4 === $i ? 70 : 88;
            $hauteur = 4 === $i ? 150 : 200;
            $forme = \sprintf('<rect x="%d" y="%d" width="%d" height="%d" rx="%d"/>', $x - $largeur / 2, $y, $largeur, $hauteur, $largeur / 2);

            $contenu = \sprintf('<rect x="0" y="0" width="600" height="600" fill="%s"/>', $couleur);
            if (null !== $pointe) {
                $contenu .= \sprintf('<rect x="0" y="0" width="600" height="%d" fill="%s"/>', $y + 38, $pointe);
            }
            $contenu .= match ($decor) {
                'points' => \sprintf('<g fill="#2b2522"><circle cx="%d" cy="%d" r="9"/><circle cx="%d" cy="%d" r="9"/><circle cx="%d" cy="%d" r="9"/><circle cx="%d" cy="%d" r="9"/></g>', $x - 18, $y + 40, $x + 18, $y + 85, $x - 15, $y + 130, $x + 20, $y + 170),
                'paillettes' => \sprintf('<g fill="#ffffff" opacity="0.75"><circle cx="%d" cy="%d" r="3"/><circle cx="%d" cy="%d" r="2"/><circle cx="%d" cy="%d" r="4"/><circle cx="%d" cy="%d" r="2"/><circle cx="%d" cy="%d" r="3"/></g>', $x - 20, $y + 30, $x + 15, $y + 60, $x - 5, $y + 100, $x + 22, $y + 140, $x - 18, $y + 165),
                'fleurs' => \sprintf('<g fill="#ffffff"><circle cx="%1$d" cy="%2$d" r="9"/><circle cx="%3$d" cy="%2$d" r="9"/><circle cx="%4$d" cy="%5$d" r="9"/><circle cx="%4$d" cy="%6$d" r="9"/><circle cx="%4$d" cy="%2$d" r="7" fill="#e3b04b"/></g>', $x - 13, $y + 80, $x + 13, $x, $y + 67, $y + 93),
                'lignes' => \sprintf('<g stroke="#c9a24a" stroke-width="3"><line x1="%d" y1="%d" x2="%d" y2="%d"/><line x1="%d" y1="%d" x2="%d" y2="%d"/></g>', $x - 44, $y + 60, $x + 44, $y + 110, $x - 44, $y + 140, $x + 44, $y + 90),
                default => '',
            };
            // Reflet brillant
            $contenu .= \sprintf('<ellipse cx="%d" cy="%d" rx="9" ry="45" fill="#ffffff" opacity="0.35"/>', $x - $largeur / 4, $y + $hauteur / 2);

            $ongles .= \sprintf(
                '<g transform="rotate(%d %d %d)"><clipPath id="o%d">%s</clipPath><g clip-path="url(#o%d)">%s</g></g>',
                $angle, $x, $y + $hauteur, $i, $forme, $i, $contenu,
            );
        }

        return <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 600 600" width="600" height="600">
            <rect width="600" height="600" fill="#fcf8f5"/>
            <circle cx="480" cy="110" r="160" fill="#f3e4dc"/>
            {$ongles}
            </svg>
            SVG;
    }
}
