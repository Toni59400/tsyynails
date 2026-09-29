<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Toutes les tâches de la tâche planifiée horaire OVH (bin/cron-horaire.php).
 * Une tâche en échec n'empêche pas les suivantes ; le code de retour signale l'échec.
 */
#[AsCommand(name: 'app:taches:horaires', description: 'Lance les tâches planifiées de chaque heure')]
final class TachesHorairesCommand extends Command
{
    public const TACHES = ['app:reservations:expirer', 'app:rappels:envoyer', 'app:fidelite:expirer', 'app:rgpd:purger', 'app:avis:demander'];

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $application = $this->getApplication() ?? throw new \LogicException('Application console absente.');
        $resultat = Command::SUCCESS;

        foreach (self::TACHES as $tache) {
            $commande = $application->find($tache);
            if (Command::SUCCESS !== $commande->run(new ArrayInput([]), $output)) {
                $resultat = Command::FAILURE;
            }
        }

        return $resultat;
    }
}
