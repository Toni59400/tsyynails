<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SeoController extends AbstractController
{
    /** Pages publiques indexables, dans l'ordre du sitemap. */
    private const PAGES = [
        'app_accueil',
        'app_prestations',
        'app_galerie',
        'app_infos',
        'app_reservation',
        'app_conditions',
        'app_confidentialite',
        'app_mentions_legales',
    ];

    #[Route('/robots.txt', name: 'app_robots', methods: ['GET'], format: 'txt')]
    public function robots(): Response
    {
        $response = $this->render('seo/robots.txt.twig');
        $response->headers->set('Content-Type', 'text/plain; charset=UTF-8');

        return $response;
    }

    #[Route('/sitemap.xml', name: 'app_sitemap', methods: ['GET'], format: 'xml')]
    public function sitemap(): Response
    {
        $response = $this->render('seo/sitemap.xml.twig', ['pages' => self::PAGES]);
        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');

        return $response;
    }
}
