<?php

declare(strict_types=1);

namespace App\Tests\Double;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Client HTTP des tests (framework.http_client.mock_response_factory) : aucun appel réseau réel,
 * les requêtes sont mémorisées pour être vérifiées.
 */
final class ReponsesHttpSimulees
{
    /** @var list<array{methode: string, url: string, options: array<string, mixed>}> */
    public static array $requetes = [];

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $methode, string $url, array $options = []): ResponseInterface
    {
        self::$requetes[] = ['methode' => $methode, 'url' => $url, 'options' => $options];

        return new MockResponse('', ['http_code' => 202]);
    }
}
