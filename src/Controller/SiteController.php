<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Parametre;
use App\Entity\Prestation;
use App\Repository\InspirationRepository;
use App\Repository\ParametreRepository;
use App\Repository\PhotoRepository;
use App\Repository\PrestationRepository;
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

    /**
     * Filtres facultatifs : ?theme=<slug d'un thème d'inspiration> ou ?prestation=<id>.
     * Un filtre inconnu est ignoré (toutes les photos s'affichent).
     */
    #[Route('/galerie', name: 'app_galerie', methods: ['GET'])]
    public function galerie(Request $request): Response
    {
        $theme = $this->inspirations->findPublieeParSlug($request->query->getString('theme'));

        $prestation = null;
        $idPrestation = (int) filter_var($request->query->getString('prestation'), \FILTER_VALIDATE_INT, ['options' => ['default' => 0]]);
        if (null === $theme && $idPrestation > 0) {
            $prestation = $this->prestations->find($idPrestation);
            if (!$prestation instanceof Prestation || !$prestation->isActive()) {
                $prestation = null;
            }
        }

        return $this->render('site/galerie.html.twig', [
            'photos' => $this->photos->findPubliees(null, $theme, $prestation),
            'inspirations' => $this->inspirations->findPublieesAvecPhotos(),
            'theme' => $theme,
            'prestation' => $prestation,
        ]);
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
            'paliers' => $this->recompenses->findActives(),
            'points_par_euro' => $this->parametres->valeur(Parametre::POINTS_PAR_EURO),
            'expiration_mois' => $this->parametres->valeur(Parametre::EXPIRATION_POINTS_MOIS),
            'bonus_inscription' => $this->parametres->valeur(Parametre::BONUS_INSCRIPTION_POINTS),
            'reduction_max' => $this->parametres->valeur(Parametre::REDUCTION_MAX_POURCENTAGE),
            'fidele_visites' => $this->parametres->valeur(Parametre::FIDELE_VISITES),
        ]);
    }
}
