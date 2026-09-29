<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Seo\IndexNow;
use App\Service\Seo\PagesPubliques;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Signale d'un coup toutes les pages du sitemap à IndexNow (aussi possible depuis Admin → Réglages).
 * Ensuite, chaque modification faite dans l'admin est signalée automatiquement.
 * Sur OVH mutualisé, les connexions sortantes sont bloquées en SSH : utiliser le bouton de l'admin.
 */
#[AsCommand(name: 'app:indexnow:soumettre', description: 'Signale toutes les pages publiques à IndexNow (Bing, ChatGPT…)')]
final class SoumettreIndexNowCommand extends Command
{
    public function __construct(
        private readonly IndexNow $indexNow,
        private readonly PagesPubliques $pages,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->indexNow->estActive()) {
            $io->warning('INDEXNOW_KEY absente ou invalide : rien n\'est envoyé.');

            return Command::FAILURE;
        }

        $urls = $this->pages->urls();
        if (!$this->indexNow->soumettre($urls, $this->pages->urlCleIndexNow())) {
            $io->error('Soumission refusée ou impossible (voir les journaux).');

            return Command::FAILURE;
        }
        $io->success(\sprintf('%d page(s) signalée(s) à IndexNow.', \count($urls)));

        return Command::SUCCESS;
    }
}
