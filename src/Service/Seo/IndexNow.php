<?php

declare(strict_types=1);

namespace App\Service\Seo;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Protocole IndexNow (https://www.indexnow.org) : prévient Bing (et donc ChatGPT, Copilot), Yandex, Seznam…
 * qu'une page a changé, pour qu'elle soit relue en quelques heures au lieu de quelques semaines.
 * La clé (INDEXNOW_KEY) est publiée sur /indexnow-cle.txt ; vide = désactivé (développement, tests).
 * Un échec est journalisé mais n'interrompt jamais l'action de l'admin.
 */
final class IndexNow
{
    public const API = 'https://api.indexnow.org/indexnow';
    public const MAX_URLS = 10000;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'INDEXNOW_KEY')] private readonly string $cle,
    ) {
    }

    public function estActive(): bool
    {
        return 1 === preg_match('/^[a-zA-Z0-9-]{8,128}$/', $this->cle);
    }

    public function cle(): string
    {
        return $this->cle;
    }

    /**
     * @param list<string> $urls adresses absolues, toutes du même domaine
     */
    public function soumettre(array $urls, string $urlCle): bool
    {
        $urls = array_slice(array_values(array_unique($urls)), 0, self::MAX_URLS);
        if (!$this->estActive() || [] === $urls) {
            return false;
        }

        try {
            $reponse = $this->httpClient->request('POST', self::API, [
                'json' => [
                    'host' => parse_url($urls[0], \PHP_URL_HOST),
                    'key' => $this->cle,
                    'keyLocation' => $urlCle,
                    'urlList' => $urls,
                ],
                'timeout' => 5,
            ]);
            $statut = $reponse->getStatusCode();
        } catch (\Throwable $erreur) {
            $this->logger->warning('IndexNow indisponible.', ['erreur' => $erreur->getMessage()]);

            return false;
        }

        // 200 = reçu, 202 = reçu, clé en cours de vérification.
        if (\in_array($statut, [200, 202], true)) {
            $this->logger->info('Pages signalées à IndexNow.', ['nombre' => \count($urls)]);

            return true;
        }
        $this->logger->warning('IndexNow a refusé la soumission.', ['statut' => $statut]);

        return false;
    }
}
