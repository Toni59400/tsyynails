<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Photo;
use App\Entity\Prestation;
use App\Entity\QuestionFrequente;
use App\Repository\HoraireOuvertureRepository;
use App\Repository\PhotoRepository;
use App\Repository\PrestationRepository;
use Symfony\Component\Asset\Packages;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * Données structurées schema.org (JSON-LD) pour Google et les moteurs de réponse génératifs.
 * JSON_HEX_TAG empêche toute sortie du bloc <script>.
 */
final class SeoExtension
{
    private const JOURS_SCHEMA = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
    public const IMAGE_PARTAGE = 'images/partage.png';

    /**
     * @param array<string, string|null> $salon
     */
    public function __construct(
        #[Autowire('%app.salon%')] private readonly array $salon,
        private readonly HoraireOuvertureRepository $horaires,
        private readonly PrestationRepository $prestations,
        private readonly PhotoRepository $photos,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Packages $assets,
        private readonly RequestStack $requestStack,
    ) {
    }

    /** Fiche NailSalon complète (accueil). */
    #[AsTwigFunction('donnees_structurees_salon', isSafe: ['html'])]
    public function donneesStructureesSalon(): string
    {
        return $this->json(['@context' => 'https://schema.org'] + $this->salon());
    }

    /** Service, offre et fil d'Ariane d'une prestation. */
    #[AsTwigFunction('donnees_structurees_prestation', isSafe: ['html'])]
    public function donneesStructureesPrestation(Prestation $prestation): string
    {
        $url = $this->url('app_prestation', ['slug' => $prestation->getSlug()]);
        $photos = $this->photos->findPubliees(3, null, $prestation);

        return $this->json([
            '@context' => 'https://schema.org',
            '@graph' => [
                array_filter([
                    '@type' => 'Service',
                    '@id' => $url.'#service',
                    'name' => $prestation->getNom(),
                    'description' => $prestation->getDescription(),
                    'url' => $url,
                    'serviceType' => 'Prothésie ongulaire',
                    'image' => array_map(fn (Photo $p): string => $this->urlPhoto($p), $photos) ?: null,
                    'provider' => ['@id' => $this->idSalon()],
                    'areaServed' => $this->zone(),
                    'offers' => $this->offre($prestation),
                ]),
                $this->filAriane([
                    'Accueil' => $this->url('app_accueil'),
                    'Prestations' => $this->url('app_prestations'),
                    $prestation->getNom() => $url,
                ]),
            ],
        ]);
    }

    /**
     * @param list<QuestionFrequente> $questions
     */
    #[AsTwigFunction('donnees_structurees_faq', isSafe: ['html'])]
    public function donneesStructureesFaq(array $questions): string
    {
        return $this->json([
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(static fn (QuestionFrequente $q): array => [
                '@type' => 'Question',
                'name' => $q->getQuestion(),
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q->getReponse()],
            ], $questions),
        ]);
    }

    /**
     * Fil d'Ariane seul (pages internes).
     *
     * @param array<string, string> $etapes libellé => URL absolue
     */
    #[AsTwigFunction('donnees_structurees_fil_ariane', isSafe: ['html'])]
    public function donneesStructureesFilAriane(array $etapes): string
    {
        return $this->json(['@context' => 'https://schema.org'] + $this->filAriane($etapes));
    }

    /** Image de partage absolue (Open Graph) : photo donnée, sinon image par défaut du site. */
    #[AsTwigFunction('image_partage')]
    public function imagePartage(?Photo $photo = null): string
    {
        return null === $photo ? $this->absolu($this->assets->getUrl(self::IMAGE_PARTAGE)) : $this->urlPhoto($photo);
    }

    /**
     * @return array<string, mixed>
     */
    private function salon(): array
    {
        $horaires = [];
        foreach ($this->horaires->findToutesOrdonnees() as $horaire) {
            $horaires[] = [
                '@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => self::JOURS_SCHEMA[$horaire->getJourSemaine()],
                'opens' => $horaire->getHeureDebut()->format('H:i'),
                'closes' => $horaire->getHeureFin()->format('H:i'),
            ];
        }

        $prestations = $this->prestations->findActives();
        $prix = array_map(static fn (Prestation $p): int => $p->getPrixCentimes(), $prestations);
        $photo = $this->photos->findPubliees(1)[0] ?? null;

        return array_filter([
            '@type' => 'NailSalon',
            '@id' => $this->idSalon(),
            'name' => $this->salon['nom'],
            'description' => \sprintf('%s à %s : pose gel, remplissage, semi-permanent, nail art. Réservation en ligne.', $this->salon['activite'], $this->salon['ville']),
            'url' => $this->url('app_accueil'),
            'image' => $this->imagePartage($photo),
            'logo' => $this->absolu($this->assets->getUrl('images/logo-carre.png')),
            'telephone' => $this->salon['telephone'],
            'email' => $this->salon['email'],
            'priceRange' => [] === $prix ? null : \sprintf('%s € – %s €', self::euros(min($prix)), self::euros(max($prix))),
            'currenciesAccepted' => 'EUR',
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => $this->salon['adresse'],
                'postalCode' => $this->salon['code_postal'],
                'addressLocality' => $this->salon['ville'],
                'addressRegion' => 'Hauts-de-France',
                'addressCountry' => 'FR',
            ],
            'geo' => null === ($this->salon['latitude'] ?? null) ? null : [
                '@type' => 'GeoCoordinates',
                'latitude' => (float) $this->salon['latitude'],
                'longitude' => (float) $this->salon['longitude'],
            ],
            'areaServed' => $this->zone(),
            'openingHoursSpecification' => $horaires ?: null,
            'sameAs' => null === ($this->salon['instagram'] ?? null) ? null : [$this->salon['instagram']],
            'hasOfferCatalog' => [] === $prestations ? null : [
                '@type' => 'OfferCatalog',
                'name' => 'Prestations et tarifs',
                'itemListElement' => array_map(fn (Prestation $p): array => $this->offre($p) + [
                    'itemOffered' => [
                        '@type' => 'Service',
                        'name' => $p->getNom(),
                        'url' => $this->url('app_prestation', ['slug' => $p->getSlug()]),
                    ],
                ], $prestations),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function offre(Prestation $prestation): array
    {
        return [
            '@type' => 'Offer',
            'price' => number_format($prestation->getPrixCentimes() / 100, 2, '.', ''),
            'priceCurrency' => 'EUR',
            'availability' => 'https://schema.org/InStock',
            'url' => $this->url('app_reservation_creneau', ['id' => $prestation->getId()]),
        ];
    }

    /**
     * @param array<string, string> $etapes
     *
     * @return array<string, mixed>
     */
    private function filAriane(array $etapes): array
    {
        $elements = [];
        $position = 1;
        foreach ($etapes as $nom => $url) {
            $elements[] = ['@type' => 'ListItem', 'position' => $position++, 'name' => $nom, 'item' => $url];
        }

        return ['@type' => 'BreadcrumbList', 'itemListElement' => $elements];
    }

    /**
     * @return list<array{'@type': string, name: string}>
     */
    private function zone(): array
    {
        return array_map(
            static fn (string $ville): array => ['@type' => 'City', 'name' => trim($ville)],
            array_values(array_filter(explode(',', (string) ($this->salon['zone'] ?? $this->salon['ville'])), static fn (string $v): bool => '' !== trim($v))),
        );
    }

    private function idSalon(): string
    {
        return $this->url('app_accueil').'#salon';
    }

    /**
     * @param array<string, mixed> $parametres
     */
    private function url(string $route, array $parametres = []): string
    {
        return $this->urlGenerator->generate($route, $parametres, UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private function urlPhoto(Photo $photo): string
    {
        return $this->absolu($this->assets->getUrl('uploads/galerie/'.$photo->getFichier()));
    }

    private function absolu(string $chemin): string
    {
        if (str_starts_with($chemin, 'http')) {
            return $chemin;
        }
        $requete = $this->requestStack->getMainRequest();
        $base = null !== $requete ? $requete->getSchemeAndHttpHost() : rtrim($this->url('app_accueil'), '/');

        return $base.'/'.ltrim($chemin, '/');
    }

    private static function euros(int $centimes): string
    {
        return 0 === $centimes % 100 ? (string) intdiv($centimes, 100) : number_format($centimes / 100, 2, ',', '');
    }

    /**
     * @param array<string, mixed> $donnees
     */
    private function json(array $donnees): string
    {
        return json_encode($donnees, \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }
}
