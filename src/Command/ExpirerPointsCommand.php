<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Client;
use App\Entity\Parametre;
use App\Repository\ClientRepository;
use App\Repository\MouvementPointsRepository;
use App\Repository\ParametreRepository;
use App\Service\Compte\NotificationsCompte;
use App\Service\Fidelite\ProgrammeFidelite;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Points non utilisés après la période d'inactivité (12 mois par défaut sans nouveau gain) :
 * un email prévient 30 jours avant, puis le solde expire. Idempotente.
 */
#[AsCommand(name: 'app:fidelite:expirer', description: 'Prévient puis fait expirer les points de fidélité inactifs')]
final class ExpirerPointsCommand extends Command
{
    public const PREAVIS_JOURS = 30;

    public function __construct(
        private readonly MouvementPointsRepository $mouvements,
        private readonly ClientRepository $clientes,
        private readonly ParametreRepository $parametres,
        private readonly ProgrammeFidelite $fidelite,
        private readonly NotificationsCompte $notifications,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $maintenant = $this->clock->now();
        $mois = $this->parametres->valeur(Parametre::EXPIRATION_POINTS_MOIS);
        $soldes = $this->mouvements->soldesParClient();
        $avertis = 0;
        $expires = 0;

        foreach ($this->mouvements->derniersGains() as $idClient => $dernierGain) {
            $solde = $soldes[$idClient] ?? 0;
            if ($solde <= 0) {
                continue;
            }

            $client = $this->clientes->find($idClient);
            if (!$client instanceof Client) {
                continue;
            }

            $expiration = $dernierGain->modify(\sprintf('+%d months', $mois));
            if ($expiration <= $maintenant) {
                $this->fidelite->expirer($client);
                ++$expires;
            } elseif ($expiration <= $maintenant->modify(\sprintf('+%d days', self::PREAVIS_JOURS))
                && (null === $client->getAvertissementPointsAt() || $client->getAvertissementPointsAt() < $dernierGain)) {
                $this->notifications->expirationPoints($client, $solde, $expiration);
                $client->marquerAvertissementPoints($maintenant);
                $this->entityManager->flush();
                ++$avertis;
            }
        }

        $this->logger->info('Expiration des points terminée.', ['avertissements' => $avertis, 'expirations' => $expires]);
        (new SymfonyStyle($input, $output))->success(\sprintf('%d avertissement(s), %d solde(s) expiré(s).', $avertis, $expires));

        return Command::SUCCESS;
    }
}
