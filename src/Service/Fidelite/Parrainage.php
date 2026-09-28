<?php

declare(strict_types=1);

namespace App\Service\Fidelite;

use App\Entity\Client;
use App\Entity\MouvementPoints;
use App\Entity\Parametre;
use App\Entity\Reservation;
use App\Enum\MotifMouvementPoints;
use App\Repository\ClientRepository;
use App\Repository\ParametreRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Parrainage : une cliente partage son code ; la nouvelle cliente l'utilise en créant son compte.
 * Les points (marraine et filleule) ne sont versés qu'au premier rendez-vous honoré de la filleule,
 * une seule fois, dans la limite d'un nombre de filleules récompensées par an et par marraine.
 */
final class Parrainage
{
    /** Alphabet sans caractères ambigus (0/O, 1/I/L) : facile à dicter ou recopier. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const LONGUEUR_CODE = 8;
    public const CLE_SESSION = 'parrainage_code';

    public function __construct(
        private readonly ClientRepository $clientes,
        private readonly ParametreRepository $parametres,
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function estActif(): bool
    {
        return $this->parametres->valeur(Parametre::PARRAINAGE_POINTS_MARRAINE) > 0
            || $this->parametres->valeur(Parametre::PARRAINAGE_POINTS_FILLEULE) > 0;
    }

    /** Code de la cliente, créé à la première demande. */
    public function codePour(Client $client): string
    {
        if (null === $client->getCodeParrainage()) {
            do {
                $code = '';
                for ($i = 0; $i < self::LONGUEUR_CODE; ++$i) {
                    $code .= self::ALPHABET[random_int(0, \strlen(self::ALPHABET) - 1)];
                }
            } while (null !== $this->clientes->findOneBy(['codeParrainage' => $code]));

            $client->attribuerCodeParrainage($code);
            $this->entityManager->flush();
        }

        return (string) $client->getCodeParrainage();
    }

    public function marraineParCode(?string $code): ?Client
    {
        $code = strtoupper(trim((string) $code));
        if (1 !== preg_match('/^['.self::ALPHABET.']{'.self::LONGUEUR_CODE.'}$/', $code)) {
            return null;
        }

        $marraine = $this->clientes->findOneBy(['codeParrainage' => $code]);

        return $marraine instanceof Client && !$marraine->estAnonymise() ? $marraine : null;
    }

    /** Code mémorisé depuis un lien de parrainage (/parrainage/CODE) pendant la visite. */
    public function memoriser(string $code): void
    {
        $this->requestStack->getSession()->set(self::CLE_SESSION, strtoupper($code));
    }

    public function codeMemorise(): ?string
    {
        $session = $this->requestStack->getMainRequest()?->hasSession() ? $this->requestStack->getSession() : null;
        $code = $session?->get(self::CLE_SESSION);

        return \is_string($code) ? $code : null;
    }

    /**
     * Relie une nouvelle cliente à sa marraine. Ne fait rien (sans erreur) si le code est inconnu
     * ou si la cliente ne peut pas être parrainée (déjà venue, déjà parrainée, elle-même…).
     */
    public function rattacher(Client $filleule, ?string $code): bool
    {
        $marraine = $this->marraineParCode($code);
        if (null === $marraine || !$this->estActif() || !$filleule->peutEtreParraineePar($marraine)) {
            return false;
        }

        $filleule->definirMarraine($marraine);
        $this->entityManager->flush();
        if ($this->requestStack->getMainRequest()?->hasSession()) {
            $this->requestStack->getSession()->remove(self::CLE_SESSION);
        }
        $this->logger->info('Parrainage enregistré.', ['filleule' => $filleule->getId(), 'marraine' => $marraine->getId()]);

        return true;
    }

    /**
     * Premier rendez-vous honoré d'une filleule : points de bienvenue pour elle, points pour sa marraine
     * (sauf plafond annuel atteint). Idempotent.
     */
    public function recompenser(Reservation $reservation): void
    {
        $filleule = $reservation->getClient();
        $marraine = $filleule->getMarraine();
        if (null === $marraine || null !== $filleule->getParrainageRecompenseAt()) {
            return;
        }

        $maintenant = $this->clock->now();
        $plafondAtteint = $this->clientes->countParrainagesRecompensesDepuis($marraine, $maintenant->modify('-12 months'))
            >= $this->parametres->valeur(Parametre::PARRAINAGE_MAX_PAR_AN);
        $filleule->marquerParrainageRecompense($maintenant);

        $pointsFilleule = $this->parametres->valeur(Parametre::PARRAINAGE_POINTS_FILLEULE);
        if ($pointsFilleule > 0) {
            $this->entityManager->persist(new MouvementPoints($filleule, $pointsFilleule, MotifMouvementPoints::PARRAINAGE, null, null, 'Bienvenue, parrainée par '.$marraine->getPrenom()));
        }

        $pointsMarraine = $this->parametres->valeur(Parametre::PARRAINAGE_POINTS_MARRAINE);
        if ($pointsMarraine > 0 && !$marraine->estAnonymise() && !$plafondAtteint) {
            $this->entityManager->persist(new MouvementPoints($marraine, $pointsMarraine, MotifMouvementPoints::PARRAINAGE, null, null, 'Filleule : '.$filleule->getPrenom()));
        }

        $this->entityManager->flush();
        $this->logger->info('Parrainage récompensé.', ['filleule' => $filleule->getId(), 'marraine' => $marraine->getId(), 'plafond_atteint' => $plafondAtteint]);
    }

    /**
     * @return array{code: string, filleules: list<array{prenom: string, recompensee: bool}>, points_marraine: int, points_filleule: int}
     */
    public function etat(Client $marraine): array
    {
        return [
            'code' => $this->codePour($marraine),
            'filleules' => array_map(static fn (Client $f): array => [
                'prenom' => $f->getPrenom(),
                'recompensee' => null !== $f->getParrainageRecompenseAt(),
            ], $this->clientes->findFilleules($marraine)),
            'points_marraine' => $this->parametres->valeur(Parametre::PARRAINAGE_POINTS_MARRAINE),
            'points_filleule' => $this->parametres->valeur(Parametre::PARRAINAGE_POINTS_FILLEULE),
        ];
    }
}
