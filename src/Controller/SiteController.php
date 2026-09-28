<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Parametre;
use App\Entity\Prestation;
use App\Repository\InspirationRepository;
use App\Repository\ParametreRepository;
use App\Repository\PhotoRepository;
use App\Repository\PrestationRepository;
use App\Repository\QuestionFrequenteRepository;
use App\Repository\RecompenseFideliteRepository;
use App\Service\Reservation\Tarification;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages du site vitrine.
 */
final class SiteController extends AbstractController
{
    public function __construct(
        private readonly PrestationRepository $prestations,
        private readonly PhotoRepository $photos,
        private readonly InspirationRepository $inspirations,
        private readonly Tarification $tarification,
        private readonly RecompenseFideliteRepository $recompenses,
        private readonly ParametreRepository $parametres,
        private readonly QuestionFrequenteRepository $questions,
    ) {
    }

    #[Route('/', name: 'app_accueil', methods: ['GET'])]
    public function accueil(): Response
    {
        return $this->render('site/accueil.html.twig', [
            'prestations' => $this->prestations->findActives(3),
            'photos' => $this->photos->findPubliees(6),
            'inspirations' => $this->inspirations->findPublieesAvecPhotos(),
        ]);
    }

    #[Route('/prestations', name: 'app_prestations', methods: ['GET'])]
    public function prestations(): Response
    {
        return $this->render('site/prestations.html.twig', [
            'prestations' => $this->prestations->findActives(),
            'photos_par_prestation' => $this->photos->findPublieesParPrestation(),
            'acompte_pourcentage' => $this->tarification->acomptePourcentage(),
        ]);
    }

    /** Page d'une prestation : texte détaillé, prix, durée, réalisations, réservation. */
    #[Route('/prestations/{slug<[a-z0-9-]+>}', name: 'app_prestation', methods: ['GET'])]
    public function prestation(string $slug): Response
    {
        $prestation = $this->prestations->findActiveParSlug($slug) ?? throw $this->createNotFoundException();

        return $this->render('site/prestation.html.twig', [
            'prestation' => $prestation,
            'photos' => $this->photos->findPubliees(null, null, $prestation),
            'autres' => array_values(array_filter($this->prestations->findActives(), static fn (Prestation $p): bool => $p !== $prestation)),
            'acompte_pourcentage' => $this->tarification->acomptePourcentage(),
        ]);
    }

    /**
     * Galerie complète. Les anciens filtres (?theme=, ?prestation=) redirigent définitivement
     * vers les pages dédiées, qui seules sont indexées.
     */
    #[Route('/galerie', name: 'app_galerie', methods: ['GET'])]
    public function galerie(Request $request): Response
    {
        $theme = $this->inspirations->findPublieeParSlug($request->query->getString('theme'));
        if (null !== $theme) {
            return $this->redirectToRoute('app_galerie_theme', ['slug' => $theme->getSlug()], Response::HTTP_MOVED_PERMANENTLY);
        }

        $idPrestation = (int) filter_var($request->query->getString('prestation'), \FILTER_VALIDATE_INT, ['options' => ['default' => 0]]);
        $prestation = $idPrestation > 0 ? $this->prestations->find($idPrestation) : null;
        if ($prestation instanceof Prestation && $prestation->isActive()) {
            return $this->redirect($this->generateUrl('app_prestation', ['slug' => $prestation->getSlug()]).'#realisations', Response::HTTP_MOVED_PERMANENTLY);
        }

        return $this->render('site/galerie.html.twig', [
            'photos' => $this->photos->findPubliees(),
            'inspirations' => $this->inspirations->findPublieesAvecPhotos(),
            'theme' => null,
        ]);
    }

    #[Route('/galerie/{slug<[a-z0-9-]+>}', name: 'app_galerie_theme', methods: ['GET'])]
    public function galerieTheme(string $slug): Response
    {
        $theme = $this->inspirations->findPublieeParSlug($slug) ?? throw $this->createNotFoundException();

        return $this->render('site/galerie.html.twig', [
            'photos' => $this->photos->findPubliees(null, $theme),
            'inspirations' => $this->inspirations->findPublieesAvecPhotos(),
            'theme' => $theme,
        ]);
    }

    #[Route('/questions-frequentes', name: 'app_faq', methods: ['GET'])]
    public function faq(): Response
    {
        return $this->render('site/faq.html.twig', ['questions' => $this->questions->findPubliees()]);
    }

    #[Route('/infos-pratiques', name: 'app_infos', methods: ['GET'])]
    public function infos(): Response
    {
        return $this->render('site/infos.html.twig');
    }

    #[Route('/mentions-legales', name: 'app_mentions_legales', methods: ['GET'])]
    public function mentionsLegales(): Response
    {
        return $this->render('site/legal/mentions_legales.html.twig');
    }

    #[Route('/confidentialite', name: 'app_confidentialite', methods: ['GET'])]
    public function confidentialite(): Response
    {
        return $this->render('site/legal/confidentialite.html.twig');
    }

    #[Route('/conditions-de-reservation', name: 'app_conditions', methods: ['GET'])]
    public function conditions(): Response
    {
        return $this->render('site/legal/conditions.html.twig', [
            'acompte_pourcentage' => $this->tarification->acomptePourcentage(),
            'annulation_heures' => $this->parametres->valeur(Parametre::ANNULATION_GRATUITE_HEURES),
            'paliers' => $this->recompenses->findActives(),
            'points_par_euro' => $this->parametres->valeur(Parametre::POINTS_PAR_EURO),
            'expiration_mois' => $this->parametres->valeur(Parametre::EXPIRATION_POINTS_MOIS),
            'bonus_inscription' => $this->parametres->valeur(Parametre::BONUS_INSCRIPTION_POINTS),
            'reduction_max' => $this->parametres->valeur(Parametre::REDUCTION_MAX_POURCENTAGE),
            'fidele_visites' => $this->parametres->valeur(Parametre::FIDELE_VISITES),
            'parrainage_marraine' => $this->parametres->valeur(Parametre::PARRAINAGE_POINTS_MARRAINE),
            'parrainage_filleule' => $this->parametres->valeur(Parametre::PARRAINAGE_POINTS_FILLEULE),
            'parrainage_max' => $this->parametres->valeur(Parametre::PARRAINAGE_MAX_PAR_AN),
        ]);
    }
}
