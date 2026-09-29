<?php

declare(strict_types=1);

namespace App\Service\Seo;

use App\Controller\SeoController;
use App\Repository\InspirationRepository;
use App\Repository\PrestationRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Adresses absolues des pages publiques (celles du sitemap), pour les signalements IndexNow.
 */
final class PagesPubliques
{
    public function __construct(
        private readonly PrestationRepository $prestations,
        private readonly InspirationRepository $inspirations,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return list<string>
     */
    public function urls(): array
    {
        $urls = array_map(fn (string $route): string => $this->url($route), SeoController::PAGES);
        foreach ($this->prestations->findActives() as $prestation) {
            $urls[] = $this->url('app_prestation', ['slug' => $prestation->getSlug()]);
        }
        foreach ($this->inspirations->findPublieesAvecPhotos() as $theme) {
            $urls[] = $this->url('app_galerie_theme', ['slug' => $theme->getSlug()]);
        }

        return $urls;
    }

    public function urlCleIndexNow(): string
    {
        return $this->url('app_indexnow_cle');
    }

    /**
     * @param array<string, string> $parametres
     */
    private function url(string $route, array $parametres = []): string
    {
        return $this->urlGenerator->generate($route, $parametres, UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
