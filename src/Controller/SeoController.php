<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\InspirationRepository;
use App\Repository\PhotoRepository;
use App\Repository\PrestationRepository;
use App\Repository\QuestionFrequenteRepository;
use App\Repository\RecompenseFideliteRepository;
use App\Service\Reservation\Tarification;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SeoController extends AbstractController
{
    /** Pages publiques fixes, dans l'ordre du sitemap. */
    private const PAGES = [
        'app_accueil',
        'app_prestations',
        'app_galerie',
        'app_infos',
        'app_faq',
        'app_reservation',
        'app_inscription',
        'app_conditions',
        'app_confidentialite',
        'app_mentions_legales',
    ];

    #[Route('/robots.txt', name: 'app_robots', methods: ['GET'], format: 'txt')]
    public function robots(): Response
    {
        return $this->texte($this->renderView('seo/robots.txt.twig'), 'text/plain');
    }

    #[Route('/sitemap.xml', name: 'app_sitemap', methods: ['GET'], format: 'xml')]
    public function sitemap(PrestationRepository $prestations, InspirationRepository $inspirations, PhotoRepository $photos): Response
    {
        return $this->texte($this->renderView('seo/sitemap.xml.twig', [
            'pages' => self::PAGES,
            'prestations' => $prestations->findActives(),
            'photos_par_prestation' => $photos->findPublieesParPrestation(),
            'inspirations' => $inspirations->findPublieesAvecPhotos(),
            'photos' => $photos->findPubliees(),
        ]), 'application/xml');
    }

    /**
     * Présentation du salon pour les moteurs de réponse génératifs (format llms.txt, Markdown).
     */
    #[Route('/llms.txt', name: 'app_llms', methods: ['GET'], format: 'txt')]
    public function llms(
        PrestationRepository $prestations,
        QuestionFrequenteRepository $questions,
        RecompenseFideliteRepository $recompenses,
        Tarification $tarification,
    ): Response {
        return $this->texte($this->renderView('seo/llms.txt.twig', [
            'prestations' => $prestations->findActives(),
            'questions' => $questions->findPubliees(),
            'paliers' => $recompenses->findActives(),
            'acompte_pourcentage' => $tarification->acomptePourcentage(),
        ]), 'text/plain');
    }

    private function texte(string $contenu, string $type): Response
    {
        return new Response($contenu, Response::HTTP_OK, [
            'Content-Type' => $type.'; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
