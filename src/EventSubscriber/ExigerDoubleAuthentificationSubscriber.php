<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Un compte admin sans double authentification ne peut rien faire
 * dans l'admin à part l'activer.
 */
final class ExigerDoubleAuthentificationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Après le pare-feu (priorité 8), pour connaître l'utilisateur.
        return [KernelEvents::REQUEST => ['onKernelRequest', 0]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest()
            || !str_starts_with($request->getPathInfo(), '/admin')
            || 'admin_2fa_configurer' === $request->attributes->get('_route')) {
            return;
        }

        $user = $this->security->getUser();
        if ($user instanceof User && $user->isAdmin() && !$user->isTotpAuthenticationEnabled()) {
            $event->setResponse(new RedirectResponse($this->urlGenerator->generate('admin_2fa_configurer')));
        }
    }
}
