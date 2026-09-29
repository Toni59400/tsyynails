<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\HoraireOuverture;
use App\Entity\Inspiration;
use App\Entity\Photo;
use App\Entity\Prestation;
use App\Entity\QuestionFrequente;
use App\Service\Seo\IndexNow;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Quand l'admin modifie un contenu public (prestation, photo, thème, FAQ, horaires), les pages concernées
 * sont signalées à IndexNow. L'envoi a lieu après la réponse (kernel.terminate) : l'admin n'attend pas.
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postRemove)]
#[AsEventListener(event: KernelEvents::TERMINATE, method: 'envoyer')]
#[AsEventListener(event: ConsoleEvents::TERMINATE, method: 'envoyer')]
final class SignalerModificationsIndexNowListener
{
    /** @var array<string, true> */
    private array $urls = [];

    public function __construct(
        private readonly IndexNow $indexNow,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->noter($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->noter($args->getObject());
    }

    public function postRemove(PostRemoveEventArgs $args): void
    {
        $this->noter($args->getObject());
    }

    public function envoyer(): void
    {
        if ([] === $this->urls) {
            return;
        }
        $urls = array_keys($this->urls);
        $this->urls = [];
        $this->indexNow->soumettre($urls, $this->url('app_indexnow_cle'));
    }

    private function noter(object $entite): void
    {
        if (!$this->indexNow->estActive()) {
            return;
        }

        $routes = match (true) {
            $entite instanceof Prestation => [['app_prestation', ['slug' => $entite->getSlug()]], ['app_prestations', []], ['app_accueil', []]],
            $entite instanceof Photo => [['app_galerie', []], ...$this->pagesDeLaPhoto($entite)],
            $entite instanceof Inspiration => [['app_galerie_theme', ['slug' => $entite->getSlug()]], ['app_galerie', []]],
            $entite instanceof QuestionFrequente => [['app_faq', []]],
            $entite instanceof HoraireOuverture => [['app_infos', []], ['app_accueil', []]],
            default => [],
        };
        foreach ($routes as [$route, $parametres]) {
            $this->urls[$this->url($route, $parametres)] = true;
        }
    }

    /**
     * @return list<array{string, array<string, string>}>
     */
    private function pagesDeLaPhoto(Photo $photo): array
    {
        $pages = [];
        if (null !== $photo->getPrestation()) {
            $pages[] = ['app_prestation', ['slug' => $photo->getPrestation()->getSlug()]];
        }
        foreach ($photo->getInspirations() as $theme) {
            $pages[] = ['app_galerie_theme', ['slug' => $theme->getSlug()]];
        }

        return $pages;
    }

    /**
     * @param array<string, string> $parametres
     */
    private function url(string $route, array $parametres = []): string
    {
        return $this->urlGenerator->generate($route, $parametres, UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
