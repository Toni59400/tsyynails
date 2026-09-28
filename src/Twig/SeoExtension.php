<?php

declare(strict_types=1);

namespace App\Twig;

use App\Repository\HoraireOuvertureRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Attribute\AsTwigFunction;

final class SeoExtension
{
    private const JOURS_SCHEMA = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    /**
     * @param array<string, string|null> $salon
     */
    public function __construct(
        #[Autowire('%app.salon%')] private readonly array $salon,
        private readonly HoraireOuvertureRepository $horaires,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Données structurées schema.org NailSalon (adresse, horaires, téléphone) pour le référencement local.
     * JSON_HEX_TAG empêche toute sortie du bloc <script>.
     */
    #[AsTwigFunction('donnees_structurees_salon', isSafe: ['html'])]
    public function donneesStructureesSalon(): string
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

        $donnees = [
            '@context' => 'https://schema.org',
            '@type' => 'NailSalon',
            'name' => $this->salon['nom'],
            'url' => $this->urlGenerator->generate('app_accueil', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'telephone' => $this->salon['telephone'],
            'email' => $this->salon['email'],
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => $this->salon['adresse'],
                'postalCode' => $this->salon['code_postal'],
                'addressLocality' => $this->salon['ville'],
                'addressCountry' => 'FR',
            ],
            'openingHoursSpecification' => $horaires,
        ];
        if (null !== $this->salon['instagram']) {
            $donnees['sameAs'] = [$this->salon['instagram']];
        }

        return json_encode($donnees, \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }
}
