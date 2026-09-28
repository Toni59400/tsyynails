<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tant que le site n'est pas lancé, tout le site demande un identifiant (authentification HTTP),
 * sauf le webhook Stripe qui doit rester joignable.
 *
 * Activée par PREPRODUCTION_ACCES="identifiant:<hash password_hash()>", désactivée si vide.
 * Générer le hash : php -r "echo password_hash('mot-de-passe', PASSWORD_DEFAULT);"
 */
final class ProtectionPreproductionSubscriber implements EventSubscriberInterface
{
    private const CHEMINS_LIBRES = ['/stripe/webhook'];

    public function __construct(#[Autowire(env: 'PREPRODUCTION_ACCES')] private readonly string $acces)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // Avant le pare-feu de sécurité et le routage.
        return [KernelEvents::REQUEST => ['verifier', 512]];
    }

    public function verifier(RequestEvent $evenement): void
    {
        if ('' === $this->acces || !$evenement->isMainRequest()) {
            return;
        }

        $requete = $evenement->getRequest();
        if (\in_array($requete->getPathInfo(), self::CHEMINS_LIBRES, true)) {
            return;
        }

        [$identifiant, $hash] = explode(':', $this->acces, 2) + [1 => ''];
        $saisiIdentifiant = (string) $requete->getUser();
        $saisiMotDePasse = (string) $requete->getPassword();

        if (hash_equals($identifiant, $saisiIdentifiant) && '' !== $hash && password_verify($saisiMotDePasse, $hash)) {
            return;
        }

        $evenement->setResponse(new Response('Site en préparation.', Response::HTTP_UNAUTHORIZED, [
            'WWW-Authenticate' => 'Basic realm="Tsyynails - site en preparation", charset="UTF-8"',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Cache-Control' => 'no-store',
        ]));
    }
}
