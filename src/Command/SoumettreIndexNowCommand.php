<?php

declare(strict_types=1);

namespace App\Command;

use App\Controller\SeoController;
use App\Repository\InspirationRepository;
use App\Repository\PrestationRepository;
use App\Service\Seo\IndexNow;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Signale d'un coup toutes les pages du sitemap à IndexNow (mise en service, changement de domaine).
 * Ensuite, chaque modification faite dans l'admin est signalée automatiquement.
 */
#[AsCommand(name: 'app:indexnow:soumettre', description: 'Signale toutes les pages publiques à IndexNow (Bing, ChatGPT…)')]
final class SoumettreIndexNowCommand extends Command
{
    public function __construct(
        private readonly IndexNow $indexNow,
        private readonly PrestationRepository $prestations,
        private readonly InspirationRepository $inspirations,
        private readonly UrlGeneratorInterface $urlGenerator,
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

        $urls = array_map(fn (string $route): string => $this->url($route), SeoController::PAGES);
        foreach ($this->prestations->findActives() as $prestation) {
            $urls[] = $this->url('app_prestation', ['slug' => $prestation->getSlug()]);
        }
        foreach ($this->inspirations->findPublieesAvecPhotos() as $theme) {
            $urls[] = $this->url('app_galerie_theme', ['slug' => $theme->getSlug()]);
        }

        if (!$this->indexNow->soumettre($urls, $this->url('app_indexnow_cle'))) {
            $io->error('Soumission refusée ou impossible (voir les journaux).');

            return Command::FAILURE;
        }
        $io->success(\sprintf('%d page(s) signalée(s) à IndexNow.', \count($urls)));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, string> $parametres
     */
    private function url(string $route, array $parametres = []): string
    {
        return $this->urlGenerator->generate($route, $parametres, UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
