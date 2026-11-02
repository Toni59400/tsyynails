<?php

declare(strict_types=1);

namespace App\Service\Paiement;

use Psr\Log\LoggerInterface;
use Stripe\StripeClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * État de la configuration Stripe pour l'admin (passage en production sans surprise) :
 * mode des clés, cohérence clé publique / secrète, secret du webhook, puis — sur demande —
 * compte activé (paiements, virements) et webhook déclaré avec les bons événements.
 *
 * À lancer depuis l'admin (contexte web) : sur l'hébergement OVH, les connexions sortantes
 * sont bloquées en ligne de commande.
 */
final class DiagnosticStripe
{
    /** Événements traités par StripeWebhookController. */
    public const EVENEMENTS_REQUIS = ['payment_intent.amount_capturable_updated', 'payment_intent.succeeded'];

    public function __construct(
        #[Autowire(env: 'STRIPE_SECRET_KEY')] private readonly string $cleSecrete,
        #[Autowire(env: 'STRIPE_PUBLIC_KEY')] private readonly string $clePublique,
        #[Autowire(env: 'STRIPE_WEBHOOK_SECRET')] private readonly string $secretWebhook,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** simulation (aucune clé), test ou live, d'après le préfixe de la clé secrète (sk_ ou clé restreinte rk_). */
    public function mode(): string
    {
        return match (true) {
            '' === $this->cleSecrete => 'simulation',
            1 === preg_match('/^(sk|rk)_live_/', $this->cleSecrete) => 'live',
            default => 'test',
        };
    }

    public function urlWebhook(): string
    {
        return $this->urlGenerator->generate('app_stripe_webhook', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * Vérifications locales (sans appel à Stripe).
     *
     * @return list<string> problèmes détectés, vide si tout est en ordre
     */
    public function problemesLocaux(): array
    {
        if ('simulation' === $this->mode()) {
            return ['Aucune clé Stripe : le paiement par carte est simulé.'];
        }

        $problemes = [];
        $publiqueLive = str_starts_with($this->clePublique, 'pk_live_');
        if ('' === $this->clePublique) {
            $problemes[] = 'Clé publique (STRIPE_PUBLIC_KEY) manquante : le formulaire de carte ne s\'affichera pas.';
        } elseif ($publiqueLive !== ('live' === $this->mode())) {
            $problemes[] = 'La clé publique et la clé secrète ne sont pas du même mode (test / production).';
        }
        if ('' === $this->secretWebhook) {
            $problemes[] = 'Secret du webhook (STRIPE_WEBHOOK_SECRET) manquant : Stripe ne peut pas prévenir le site des paiements.';
        } elseif (!str_starts_with($this->secretWebhook, 'whsec_')) {
            $problemes[] = 'Le secret du webhook doit commencer par « whsec_ ».';
        }

        return $problemes;
    }

    /**
     * Interroge Stripe : compte activé et webhook déclaré pour ce site avec les événements requis.
     *
     * @return array{compte: array{nom: string, paiements: bool, virements: bool, dossier_complet: bool}|null, webhook: array{trouve: bool, actif: bool, manquants: list<string>}|null, problemes: list<string>}
     */
    public function verifierAupresDeStripe(): array
    {
        $problemes = [];
        $compte = null;
        $webhook = null;
        if ('simulation' === $this->mode()) {
            return ['compte' => null, 'webhook' => null, 'problemes' => ['Aucune clé Stripe configurée.']];
        }
        $stripe = new StripeClient($this->cleSecrete);

        try {
            $donnees = $stripe->accounts->retrieve();
            $compte = [
                'nom' => (string) ($donnees->settings->dashboard->display_name ?? $donnees->business_profile->name ?? ''),
                'paiements' => (bool) $donnees->charges_enabled,
                'virements' => (bool) $donnees->payouts_enabled,
                'dossier_complet' => (bool) $donnees->details_submitted,
            ];
            if (!$compte['paiements']) {
                $problemes[] = 'Le compte Stripe ne peut pas encore encaisser : terminez l\'activation dans le tableau de bord Stripe.';
            }
            if (!$compte['virements']) {
                $problemes[] = 'Les virements vers votre banque ne sont pas encore activés (IBAN ou justificatifs à fournir).';
            }
        } catch (\Throwable $erreur) {
            $this->logger->warning('Diagnostic Stripe : compte illisible.', ['erreur' => $erreur->getMessage()]);
            $problemes[] = 'Lecture du compte Stripe impossible (clé invalide, ou clé restreinte sans droit « Account »).';
        }

        try {
            $url = $this->urlWebhook();
            $webhook = ['trouve' => false, 'actif' => false, 'manquants' => self::EVENEMENTS_REQUIS];
            foreach ($stripe->webhookEndpoints->all(['limit' => 100])->data as $point) {
                if ($point->url !== $url) {
                    continue;
                }
                $evenements = $point->enabled_events ?? [];
                $webhook = [
                    'trouve' => true,
                    'actif' => 'enabled' === $point->status,
                    'manquants' => \in_array('*', $evenements, true) ? [] : array_values(array_diff(self::EVENEMENTS_REQUIS, $evenements)),
                ];
                break;
            }
            if (!$webhook['trouve']) {
                $problemes[] = \sprintf('Aucun webhook Stripe ne pointe vers %s.', $url);
            } elseif (!$webhook['actif']) {
                $problemes[] = 'Le webhook Stripe est désactivé.';
            } elseif ([] !== $webhook['manquants']) {
                $problemes[] = 'Événements manquants dans le webhook Stripe : '.implode(', ', $webhook['manquants']).'.';
            }
        } catch (\Throwable $erreur) {
            $this->logger->warning('Diagnostic Stripe : webhooks illisibles.', ['erreur' => $erreur->getMessage()]);
            $problemes[] = 'Lecture des webhooks impossible (clé restreinte sans droit « Webhook Endpoints » en lecture ?).';
        }

        return ['compte' => $compte, 'webhook' => $webhook, 'problemes' => $problemes];
    }
}
