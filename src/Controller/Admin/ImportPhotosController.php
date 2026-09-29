<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Prestation;
use App\Entity\User;
use App\Repository\InspirationRepository;
use App\Repository\PrestationRepository;
use App\Service\Media\ImportPhotos;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Import de photos en lot, sans limite de nombre : la page envoie les fichiers un par un
 * (l'hébergement limite le nombre de fichiers par requête). Routes : admin_photos_import_index, admin_photos_import_fichier.
 */
#[IsGranted(User::ROLE_ADMIN)]
#[AdminRoute('/photos/import', name: 'photos_import')]
final class ImportPhotosController extends AbstractController
{
    public const JETON_CSRF = 'import_photos';

    public function __construct(
        private readonly PrestationRepository $prestations,
        private readonly InspirationRepository $inspirations,
        private readonly ImportPhotos $import,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AdminRoute('/', name: 'index', options: ['methods' => ['GET']])]
    public function index(Request $request): Response
    {
        return $this->render('admin/import_photos.html.twig', [
            'prestations' => $this->prestations->findBy([], ['ordre' => 'ASC', 'nom' => 'ASC']),
            'inspirations' => $this->inspirations->findBy([], ['ordre' => 'ASC', 'nom' => 'ASC']),
            'prestation_choisie' => self::identifiant($request->query->getString('prestation')),
            'taille_max' => ImportPhotos::TAILLE_MAX,
        ]);
    }

    /** Reçoit une seule photo avec les réglages du lot ; répond en JSON pour la barre de progression. */
    #[AdminRoute('/fichier', name: 'fichier', options: ['methods' => ['POST']])]
    public function fichier(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::JETON_CSRF, $request->request->getString('_token'))) {
            return new JsonResponse(['erreur' => 'Session expirée : rechargez la page.'], Response::HTTP_FORBIDDEN);
        }

        $fichier = $request->files->get('photo');
        if (!$fichier instanceof UploadedFile || !$fichier->isValid()) {
            return new JsonResponse(['erreur' => 'Fichier non reçu (trop lourd pour le serveur ?).'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Champ vide = photo d'inspiration seule (getInt() refuse une chaîne vide).
        $idPrestation = self::identifiant($request->request->getString('prestation'));
        $prestation = null === $idPrestation ? null : $this->prestations->find($idPrestation);
        $themes = array_values(array_filter(array_map(
            fn ($id) => null === self::identifiant((string) $id) ? null : $this->inspirations->find((int) $id),
            $request->request->all('themes'),
        )));
        $legende = trim($request->request->getString('legende'));
        if ('' === $legende) {
            $legende = match (true) {
                $prestation instanceof Prestation => 'Réalisation '.$prestation->getNom(),
                [] !== $themes => 'Inspiration '.$themes[0]->getNom(),
                default => 'Réalisation',
            };
        }

        try {
            $photo = $this->import->importer($fichier, $legende, $prestation instanceof Prestation ? $prestation : null, $themes, $request->request->getBoolean('publiee'));
        } catch (\InvalidArgumentException $erreur) {
            return new JsonResponse(['erreur' => $erreur->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var User $admin */
        $admin = $this->getUser();
        $this->logger->info('Photo importée.', ['photo' => $photo->getId(), 'par' => $admin->getUserIdentifier()]);

        return new JsonResponse(['id' => $photo->getId(), 'fichier' => $photo->getFichier()], Response::HTTP_CREATED);
    }

    private static function identifiant(string $valeur): ?int
    {
        return ctype_digit($valeur) ? (int) $valeur : null;
    }
}
