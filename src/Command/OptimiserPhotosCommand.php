<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\PhotoRepository;
use App\Service\Media\OptimiseurPhoto;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Optimise les photos déjà en ligne (envoyées avant l'optimisation automatique).
 * Seules les photos trop grandes ou trop lourdes sont retraitées : relancer la commande ne dégrade rien.
 */
#[AsCommand(name: 'app:photos:optimiser', description: 'Redimensionne et compresse les photos de la galerie trop lourdes')]
final class OptimiserPhotosCommand extends Command
{
    public const POIDS_MAX = 600 * 1024;

    public function __construct(
        private readonly PhotoRepository $photos,
        private readonly OptimiseurPhoto $optimiseur,
        #[Autowire('%app.dossier_galerie%')]
        private readonly string $dossier,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $traitees = 0;
        $gain = 0;

        foreach ($this->photos->findAll() as $photo) {
            $chemin = $this->dossier.'/'.basename($photo->getFichier());
            if (!is_file($chemin) || !$this->aOptimiser($chemin)) {
                continue;
            }

            $avant = (int) filesize($chemin);
            $this->optimiseur->optimiser($chemin);
            clearstatcache(true, $chemin);
            $gain += $avant - (int) filesize($chemin);
            ++$traitees;
        }

        $io->success(\sprintf('%d photo(s) optimisée(s), %s Ko gagnés.', $traitees, number_format(max(0, $gain) / 1024, 0, ',', ' ')));

        return Command::SUCCESS;
    }

    private function aOptimiser(string $chemin): bool
    {
        if (filesize($chemin) > self::POIDS_MAX) {
            return true;
        }
        $taille = @getimagesize($chemin);

        return false !== $taille && max($taille[0], $taille[1]) > OptimiseurPhoto::COTE_MAX;
    }
}
